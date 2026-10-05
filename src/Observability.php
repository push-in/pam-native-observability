<?php

declare(strict_types=1);

namespace Pam\Native\Observability;

use Closure;
use InvalidArgumentException;
use JsonException;
use Pam\Native\Modules\NativeModuleResult;
use Pam\Native\Modules\NativeModules;
use Throwable;

/**
 * Vendor-neutral telemetry (instances, OTLP / PAM JSON) plus a process-wide
 * native exporter (static methods):
 *
 * ```php
 * Observability::exporter(SentryExporter::dsn($dsn)->environment('production')->release('app@1.0.0'));
 * Observability::user('42');
 * Observability::breadcrumb('Opened chat 42', 'navigation');
 * Observability::capture($error);
 * ```
 */
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
        SentryForwarder::capture($error, $handled, $handled ? Severity::Error : Severity::Fatal);
    }

    /**
     * Installs the process-wide native exporter (Sentry). Native crashes, ANRs
     * and NDK crashes are captured by the platform SDK; PHP uncaught
     * exceptions, selected PHP errors and fatal shutdowns are forwarded.
     *
     * @param null|Closure(bool, ?string): void $then
     */
    public static function exporter(SentryExporter $exporter, ?Closure $then = null): int
    {
        return SentryForwarder::install($exporter, $then);
    }

    /**
     * Reports a throwable with its PHP stack frames and chained causes.
     * De-duplicated for 60 s and rate limited; a no-op without an exporter.
     *
     * @param array<string, string> $tags
     * @param array<string, scalar|null> $extra
     * @param null|Closure(?string): void $then receives the event id
     */
    public static function capture(Throwable $error, bool $handled = true, array $tags = [], array $extra = [], ?Closure $then = null): bool
    {
        return SentryForwarder::capture($error, $handled, $handled ? Severity::Error : Severity::Fatal, $tags, $extra, then: $then);
    }

    /**
     * @param array<string, string> $tags
     * @param null|Closure(?string): void $then
     */
    public static function message(string $message, Severity $level = Severity::Info, array $tags = [], ?Closure $then = null): bool
    {
        return SentryForwarder::message($message, $level, $tags, $then);
    }

    /** @param array<string, scalar|null> $data */
    public static function breadcrumb(string $message, string $category = 'app', array $data = [], Severity $level = Severity::Info): void
    {
        SentryForwarder::breadcrumb($message, $category, $data, $level);
    }

    /** Identifies the signed-in user on every following event; pass null to clear. */
    public static function user(?string $id, ?string $email = null, ?string $username = null): void
    {
        if (SentryForwarder::active()) {
            SentryForwarder::fire('sentryUser', ['id' => $id ?? '', 'email' => $email ?? '', 'username' => $username ?? '']);
        }
    }

    public static function tag(string $key, string $value): void
    {
        if (preg_match('/^[A-Za-z0-9_.:-]{1,32}$/D', $key) !== 1 || strlen($value) > 200) {
            throw new InvalidArgumentException('Invalid exporter tag.');
        }
        if (SentryForwarder::active()) {
            SentryForwarder::fire('sentryTag', ['key' => $key, 'value' => $value]);
        }
    }

    /**
     * Runs `$body`, reporting (then rethrowing) anything it throws.
     *
     * @template T
     * @param Closure(): T $body
     * @return T
     */
    public static function guard(Closure $body): mixed
    {
        try {
            return $body();
        } catch (Throwable $error) {
            SentryForwarder::capture($error, handled: false, mechanism: 'pam.php.guard');
            throw $error;
        }
    }

    /** @param null|Closure(): void $then */
    public static function exporterFlush(int $timeoutMillis = 2000, ?Closure $then = null): int
    {
        if ($timeoutMillis < 0 || $timeoutMillis > 30_000) {
            throw new InvalidArgumentException('Flush timeout must be 0-30000 ms.');
        }

        return NativeModules::call(SentryForwarder::MODULE, 'sentryFlush', ['timeoutMs' => $timeoutMillis], static function () use ($then): void {
            $then?->__invoke();
        });
    }

    /** @param Closure(ExporterStatus): void $then */
    public static function exporterStatus(Closure $then): int
    {
        return NativeModules::call(SentryForwarder::MODULE, 'sentryStatus', [], static function (NativeModuleResult $result) use ($then): void {
            if (!$result->succeeded()) {
                $then(new ExporterStatus(false, error: $result->message()));

                return;
            }
            try {
                $wire = json_decode((string) ($result->values()['status'] ?? '{}'), true, 8, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $wire = [];
            }
            $then(ExporterStatus::fromWire(is_array($wire) ? $wire : []));
        });
    }

    /**
     * Sends a diagnostic exception through the native SDK and flushes it.
     *
     * @param Closure(bool, string): void $then success and the event id or the error message
     */
    public static function exporterTest(Closure $then): int
    {
        return NativeModules::call(SentryForwarder::MODULE, 'sentryTest', [], static function (NativeModuleResult $result) use ($then): void {
            $then($result->succeeded(), $result->succeeded() ? (string) ($result->values()['eventId'] ?? '') : $result->message());
        });
    }

    /** Stops the exporter and forgets the persisted configuration (e.g. on opt-out). */
    public static function exporterStop(?Closure $then = null): int
    {
        SentryForwarder::reset();

        return NativeModules::call(SentryForwarder::MODULE, 'sentryStop', [], static function () use ($then): void {
            $then?->__invoke();
        });
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
            return $this->spliceBatch($this->config->batchSize);
        }

        $family = SignalKind::from($this->queue[0]['kind'])->family();
        $length = 1;
        while ($length < min($this->config->batchSize, count($this->queue))
            && SignalKind::from($this->queue[$length]['kind'])->family() === $family
        ) {
            $length++;
        }

        return $this->spliceBatch($length);
    }

    /**
     * @return list<array{kind: int, timestampUnixNano: string, data: array<string, mixed>}>
     */
    private function spliceBatch(int $length): array
    {
        $batch = [];
        foreach (array_splice($this->queue, 0, $length) as $signal) {
            $batch[] = $signal;
        }

        return $batch;
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
