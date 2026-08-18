<?php

declare(strict_types=1);

namespace Pam\Native\Observability;

use InvalidArgumentException;
use Throwable;

final class Observability
{
    /** @var list<array{kind: int, timestampUnixNano: string, data: array<string, mixed>}> */
    private array $queue = [];

    private int $dropped = 0;

    /** @var array<string, scalar> */
    private array $context = [];

    private readonly TelemetryEncoder $encoder;

    public function __construct(
        private readonly ObservabilityConfig $config,
        private readonly TelemetryTransport $transport,
    ) {
        $this->encoder = match ($config->wireProtocol) {
            WireProtocol::PamJson => new PamJsonEncoder(),
            WireProtocol::OtlpHttpJson => new OtlpHttpJsonEncoder(),
        };
    }

    /** @param array<array-key, mixed> $values */
    public function context(array $values): void
    {
        $this->context = $this->clean($values);
    }

    public function span(string $name, Span|TraceContext|null $parent = null): Span
    {
        if (preg_match('/^[^\x00-\x1f]{1,200}$/u', $name) !== 1) {
            throw new InvalidArgumentException('Invalid span name.');
        }

        $traceId = match (true) {
            $parent instanceof Span => $parent->traceId,
            $parent instanceof TraceContext => $parent->traceId,
            default => bin2hex(random_bytes(16)),
        };
        $parentSpanId = match (true) {
            $parent instanceof Span => $parent->spanId,
            $parent instanceof TraceContext => $parent->spanId,
            default => null,
        };
        $traceFlags = match (true) {
            $parent instanceof Span => $parent->traceFlags,
            $parent instanceof TraceContext => $parent->flags,
            default => 1,
        };

        return new Span(
            owner: $this,
            name: $name,
            traceId: $traceId,
            spanId: bin2hex(random_bytes(8)),
            parentSpanId: $parentSpanId,
            traceFlags: $traceFlags,
            startedNs: hrtime(true),
        );
    }

    /** @param array<array-key, mixed> $attributes */
    public function log(Severity $severity, string $message, array $attributes = []): void
    {
        $this->enqueue(SignalKind::Log, [
            'severity' => $severity->value,
            'message' => substr($message, 0, 8192),
            'attributes' => $this->clean($attributes),
        ]);
    }

    /** @param array<array-key, mixed> $attributes */
    public function counter(
        string $name,
        int|float $value = 1,
        array $attributes = [],
    ): void {
        $this->metric(SignalKind::Counter, $name, $value, $attributes);
    }

    /** @param array<array-key, mixed> $attributes */
    public function gauge(string $name, int|float $value, array $attributes = []): void
    {
        $this->metric(SignalKind::Gauge, $name, $value, $attributes);
    }

    public function crash(Throwable $error, bool $handled = false): void
    {
        $exception = [
            'type' => $error::class,
        ];
        if ($this->config->captureExceptionDetails) {
            $exception += [
                'message' => substr($error->getMessage(), 0, 4096),
                'file' => $error->getFile(),
                'line' => $error->getLine(),
                'trace' => substr($error->getTraceAsString(), 0, 16000),
            ];
        }
        $this->enqueue(SignalKind::Crash, [
            'handled' => $handled,
            'exception' => $exception,
        ]);
    }

    /** @param array<array-key, mixed> $attributes */
    public function finishSpan(
        Span $span,
        int $start,
        int $end,
        SpanStatus $status,
        array $attributes,
    ): void {
        if (($span->traceFlags & 1) === 0 || !$this->sample($span->traceId)) {
            return;
        }
        $this->enqueue(SignalKind::Span, [
            'name' => $span->name,
            'traceId' => $span->traceId,
            'spanId' => $span->spanId,
            'parentSpanId' => $span->parentSpanId,
            'traceFlags' => $span->traceFlags,
            'startUnixNano' => $this->unixNano($start),
            'endUnixNano' => $this->unixNano($end),
            'status' => $status->value,
            'attributes' => $this->clean($attributes),
        ]);
    }

    public function flush(): int
    {
        $sent = 0;
        while ($this->queue !== []) {
            $batch = $this->takeBatch();
            try {
                $encoded = $this->encoder->encode(
                    $this->config,
                    $batch,
                    $this->context,
                    $this->dropped,
                );
                $this->transport->send(
                    $encoded->endpoint,
                    $encoded->body,
                    $encoded->headers,
                    $this->config->timeoutMillis,
                );
                $sent += count($batch);
                $this->dropped = 0;
            } catch (Throwable $error) {
                $this->queue = [...$batch, ...$this->queue];
                throw $error;
            }
        }

        return $sent;
    }

    public function queued(): int
    {
        return count($this->queue);
    }

    public function dropped(): int
    {
        return $this->dropped;
    }

    /**
     * @param array<array-key, mixed> $attributes
     */
    private function metric(
        SignalKind $kind,
        string $name,
        int|float $value,
        array $attributes,
    ): void {
        if (preg_match('/^[A-Za-z][A-Za-z0-9._-]{0,127}$/D', $name) !== 1
            || !is_finite((float) $value)
        ) {
            throw new InvalidArgumentException('Invalid metric.');
        }
        $this->enqueue($kind, [
            'name' => $name,
            'value' => $value,
            'attributes' => $this->clean($attributes),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function enqueue(SignalKind $kind, array $data): void
    {
        while (count($this->queue) >= $this->config->maxQueue) {
            array_shift($this->queue);
            $this->dropped++;
        }
        $this->queue[] = [
            'kind' => $kind->value,
            'timestampUnixNano' => (string) ((int) (microtime(true) * 1_000_000_000)),
            'data' => $data,
        ];
    }

    /**
     * @return list<array{kind: int, timestampUnixNano: string, data: array<string, mixed>}>
     */
    private function takeBatch(): array
    {
        if ($this->config->wireProtocol === WireProtocol::PamJson) {
            return array_splice($this->queue, 0, $this->config->batchSize);
        }

        $family = SignalKind::from($this->queue[0]['kind'])->family();
        $length = 1;
        while ($length < min($this->config->batchSize, count($this->queue))
            && SignalKind::from($this->queue[$length]['kind'])->family() === $family
        ) {
            $length++;
        }

        return array_splice($this->queue, 0, $length);
    }

    private function sample(string $traceId): bool
    {
        if ($this->config->sampleRate >= 1) {
            return true;
        }
        if ($this->config->sampleRate <= 0) {
            return false;
        }
        $value = hexdec(substr($traceId, 0, 8)) / 0xffffffff;

        return $value < $this->config->sampleRate;
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<string, scalar>
     */
    private function clean(array $values): array
    {
        $output = [];
        foreach ($values as $key => $value) {
            if (!is_string($key)
                || preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $key) !== 1
                || !is_scalar($value)
            ) {
                continue;
            }
            $output[$key] = is_string($value) ? substr($value, 0, 2048) : $value;
        }

        return $output;
    }

    private function unixNano(int $monotonic): string
    {
        static $offset = null;
        $offset ??= (int) (microtime(true) * 1_000_000_000) - hrtime(true);

        return (string) ($offset + $monotonic);
    }
}
