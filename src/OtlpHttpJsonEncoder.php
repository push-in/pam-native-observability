<?php

declare(strict_types=1);

namespace Pam\Native\Observability;

use InvalidArgumentException;
use JsonException;

final class OtlpHttpJsonEncoder implements TelemetryEncoder
{
    /**
     * @param list<array{kind: int, timestampUnixNano: string, data: array<string, mixed>}> $signals
     * @param array<string, scalar> $context
     * @throws JsonException
     */
    public function encode(
        ObservabilityConfig $config,
        array $signals,
        array $context,
        int $dropped,
    ): EncodedTelemetry {
        if ($signals === []) {
            throw new InvalidArgumentException('OTLP batches cannot be empty.');
        }
        $family = SignalKind::from($signals[0]['kind'])->family();
        foreach ($signals as $signal) {
            if (SignalKind::from($signal['kind'])->family() !== $family) {
                throw new InvalidArgumentException('OTLP batches cannot mix signal families.');
            }
        }

        $payload = match ($family) {
            SignalFamily::Trace => $this->traces($config, $signals, $context, $dropped),
            SignalFamily::Log => $this->logs($config, $signals, $context, $dropped),
            SignalFamily::Metric => $this->metrics($config, $signals, $context, $dropped),
        };

        return new EncodedTelemetry(
            endpoint: $config->signalEndpoint($family),
            body: json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            headers: [
                'content-type' => 'application/json',
                'user-agent' => 'pam-native-observability/0.2',
                ...$config->headers,
            ],
        );
    }

    /**
     * @param list<array{kind: int, timestampUnixNano: string, data: array<string, mixed>}> $signals
     * @param array<string, scalar> $context
     * @return array<string, mixed>
     */
    private function traces(
        ObservabilityConfig $config,
        array $signals,
        array $context,
        int $dropped,
    ): array {
        $spans = [];
        foreach ($signals as $signal) {
            $data = $signal['data'];
            $span = [
                'traceId' => $this->requiredString($data, 'traceId'),
                'spanId' => $this->requiredString($data, 'spanId'),
                'name' => $this->requiredString($data, 'name'),
                'kind' => 1,
                'startTimeUnixNano' => $this->requiredString($data, 'startUnixNano'),
                'endTimeUnixNano' => $this->requiredString($data, 'endUnixNano'),
                'attributes' => $this->attributes($this->attributeData($data)),
                'status' => ['code' => $this->spanStatus($data['status'] ?? null)],
                'flags' => $this->traceFlags($data['traceFlags'] ?? null),
            ];
            if (isset($data['parentSpanId']) && is_string($data['parentSpanId'])) {
                $span['parentSpanId'] = $data['parentSpanId'];
            }
            $spans[] = $span;
        }

        return [
            'resourceSpans' => [[
                'resource' => ['attributes' => $this->resourceAttributes($config, $context, $dropped)],
                'scopeSpans' => [[
                    'scope' => $this->scope(),
                    'spans' => $spans,
                ]],
            ]],
        ];
    }

    private function traceFlags(mixed $value): int
    {
        if (!is_int($value) || $value < 0 || $value > 255) {
            throw new InvalidArgumentException('Invalid trace flags.');
        }

        return $value;
    }

    /**
     * @param list<array{kind: int, timestampUnixNano: string, data: array<string, mixed>}> $signals
     * @param array<string, scalar> $context
     * @return array<string, mixed>
     */
    private function logs(
        ObservabilityConfig $config,
        array $signals,
        array $context,
        int $dropped,
    ): array {
        $records = [];
        foreach ($signals as $signal) {
            $kind = SignalKind::from($signal['kind']);
            $data = $signal['data'];
            if ($kind === SignalKind::Crash) {
                $exception = is_array($data['exception'] ?? null) ? $data['exception'] : [];
                $attributes = [
                    'exception.type' => $this->optionalScalar($exception['type'] ?? null),
                    'exception.message' => $this->optionalScalar($exception['message'] ?? null),
                    'exception.escaped' => !($data['handled'] ?? false),
                ];
                if ($config->captureExceptionDetails) {
                    $attributes['code.file.path'] = $this->optionalScalar($exception['file'] ?? null);
                    $attributes['code.line.number'] = $this->optionalScalar($exception['line'] ?? null);
                    $attributes['exception.stacktrace'] = $this->optionalScalar($exception['trace'] ?? null);
                }
                $records[] = [
                    'timeUnixNano' => $signal['timestampUnixNano'],
                    'severityNumber' => 21,
                    'severityText' => 'FATAL',
                    'body' => ['stringValue' => 'Unhandled application exception'],
                    'attributes' => $this->attributes($this->withoutNulls($attributes)),
                ];
                continue;
            }

            $severity = Severity::from($this->requiredInt($data, 'severity'));
            $records[] = [
                'timeUnixNano' => $signal['timestampUnixNano'],
                'severityNumber' => $severity->otlpNumber(),
                'severityText' => $severity->otlpText(),
                'body' => ['stringValue' => $this->requiredString($data, 'message')],
                'attributes' => $this->attributes($this->attributeData($data)),
            ];
        }

        return [
            'resourceLogs' => [[
                'resource' => ['attributes' => $this->resourceAttributes($config, $context, $dropped)],
                'scopeLogs' => [[
                    'scope' => $this->scope(),
                    'logRecords' => $records,
                ]],
            ]],
        ];
    }

    /**
     * @param list<array{kind: int, timestampUnixNano: string, data: array<string, mixed>}> $signals
     * @param array<string, scalar> $context
     * @return array<string, mixed>
     */
    private function metrics(
        ObservabilityConfig $config,
        array $signals,
        array $context,
        int $dropped,
    ): array {
        $metrics = [];
        foreach ($signals as $signal) {
            $kind = SignalKind::from($signal['kind']);
            $data = $signal['data'];
            $value = $data['value'] ?? null;
            if (!is_int($value) && !is_float($value)) {
                throw new InvalidArgumentException('Metric values must be numeric.');
            }
            $point = [
                'timeUnixNano' => $signal['timestampUnixNano'],
                'attributes' => $this->attributes($this->attributeData($data)),
                is_int($value) ? 'asInt' : 'asDouble' => is_int($value) ? (string) $value : $value,
            ];
            $metric = ['name' => $this->requiredString($data, 'name')];
            if ($kind === SignalKind::Counter) {
                $metric['sum'] = [
                    'dataPoints' => [$point],
                    'aggregationTemporality' => 1,
                    'isMonotonic' => true,
                ];
            } else {
                $metric['gauge'] = ['dataPoints' => [$point]];
            }
            $metrics[] = $metric;
        }

        return [
            'resourceMetrics' => [[
                'resource' => ['attributes' => $this->resourceAttributes($config, $context, $dropped)],
                'scopeMetrics' => [[
                    'scope' => $this->scope(),
                    'metrics' => $metrics,
                ]],
            ]],
        ];
    }

    /** @return array{name: string, version: string} */
    private function scope(): array
    {
        return ['name' => 'pam.native.observability', 'version' => '0.2.0'];
    }

    /**
     * @param array<string, scalar> $context
     * @return list<array{key: string, value: array<string, bool|float|string>}>
     */
    private function resourceAttributes(
        ObservabilityConfig $config,
        array $context,
        int $dropped,
    ): array {
        $values = [
            'service.name' => $config->serviceName,
            'service.version' => $config->serviceVersion,
            'telemetry.sdk.name' => 'pam-native-observability',
            'pam.telemetry.dropped' => $dropped,
        ];
        foreach ($context as $key => $value) {
            $values['pam.context.'.$key] = $value;
        }

        return $this->attributes($values);
    }

    /**
     * @param array<string, scalar> $values
     * @return list<array{key: string, value: array<string, bool|float|string>}>
     */
    private function attributes(array $values): array
    {
        $attributes = [];
        foreach ($values as $key => $value) {
            $attributes[] = ['key' => $key, 'value' => $this->anyValue($value)];
        }

        return $attributes;
    }

    /** @return array<string, bool|float|string> */
    private function anyValue(string|int|float|bool $value): array
    {
        return match (true) {
            is_bool($value) => ['boolValue' => $value],
            is_int($value) => ['intValue' => (string) $value],
            is_float($value) => ['doubleValue' => $value],
            default => ['stringValue' => $value],
        };
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, scalar>
     */
    private function attributeData(array $data): array
    {
        $attributes = $data['attributes'] ?? null;
        if (!is_array($attributes)) {
            return [];
        }

        $clean = [];
        foreach ($attributes as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, scalar>
     */
    private function withoutNulls(array $values): array
    {
        $clean = [];
        foreach ($values as $key => $value) {
            if (is_scalar($value)) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    /** @param array<string, mixed> $values */
    private function requiredString(array $values, string $key): string
    {
        $value = $values[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException("Telemetry field {$key} must be a string.");
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private function requiredInt(array $values, string $key): int
    {
        $value = $values[$key] ?? null;
        if (!is_int($value)) {
            throw new InvalidArgumentException("Telemetry field {$key} must be an integer.");
        }

        return $value;
    }

    private function optionalScalar(mixed $value): string|int|float|bool|null
    {
        return is_scalar($value) ? $value : null;
    }

    private function spanStatus(mixed $status): int
    {
        if (!is_int($status)) {
            return 0;
        }

        return match (SpanStatus::tryFrom($status)) {
            SpanStatus::Ok => 1,
            SpanStatus::Error => 2,
            default => 0,
        };
    }
}
