<?php

declare(strict_types=1);

use Pam\Native\Observability\Observability;
use Pam\Native\Observability\ObservabilityConfig;
use Pam\Native\Observability\OtlpResponse;
use Pam\Native\Observability\Severity;
use Pam\Native\Observability\SignalFamily;
use Pam\Native\Observability\SignalKind;
use Pam\Native\Observability\SpanStatus;
use Pam\Native\Observability\TelemetryTransport;
use Pam\Native\Observability\WireProtocol;
$root = dirname(__DIR__);
if (!class_exists(Observability::class)) {
    spl_autoload_register(static function (string $class) use ($root): void {
        $prefix = 'Pam\\Native\\Observability\\';
        if (str_starts_with($class, $prefix)) {
            require $root.'/src/'.substr($class, strlen($prefix)).'.php';
        }
    });
}

final class FakeTransport implements TelemetryTransport
{
    /** @var list<array{endpoint: string, body: array<string, mixed>, headers: array<string, string>}> */
    public array $requests = [];

    public bool $failNext = false;

    /** @param array<string, string> $headers */
    public function send(
        string $endpoint,
        string $body,
        array $headers,
        int $timeoutMillis,
    ): void {
        if ($this->failNext) {
            $this->failNext = false;
            throw new RuntimeException('transient collector failure');
        }
        $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('expected an encoded JSON object');
        }
        $this->requests[] = [
            'endpoint' => $endpoint,
            'body' => $decoded,
            'headers' => $headers,
        ];
    }
}

$tests = 0;

/** @param callable(): void $test */
function test(string $name, callable $test): void
{
    global $tests;
    try {
        $test();
        $tests++;
        echo "PASS {$name}\n";
    } catch (Throwable $error) {
        fwrite(STDERR, "FAIL {$name}: {$error->getMessage()}\n");
        exit(1);
    }
}

function same(mixed $expected, mixed $actual, string $message): void
{
    if ($actual !== $expected) {
        throw new RuntimeException(
            $message.' expected '.var_export($expected, true).' got '.var_export($actual, true),
        );
    }
}

function truth(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param callable(): mixed $operation */
function throws(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (Throwable) {
        return;
    }
    throw new RuntimeException($message);
}

/**
 * @param array<array-key, mixed> $value
 * @param list<int|string> $path
 * @return array<array-key, mixed>
 */
function objectAt(array $value, array $path): array
{
    $current = $value;
    foreach ($path as $key) {
        $next = $current[$key] ?? null;
        if (!is_array($next)) {
            throw new RuntimeException('Expected an object at '.implode('.', $path));
        }
        $current = $next;
    }

    return $current;
}

test('legacy payload stays compatible and bounded', static function (): void {
    $transport = new FakeTransport();
    $telemetry = new Observability(
        new ObservabilityConfig(
            endpoint: 'https://collector.example/v1/events',
            serviceName: 'test-app',
            batchSize: 2,
            maxQueue: 3,
        ),
        $transport,
    );
    $span = $telemetry->span('startup')->attribute('screen', 'home')->status(SpanStatus::Ok);
    $span->end();
    $telemetry->log(Severity::Info, 'ready');
    $telemetry->counter('render.count');
    $telemetry->gauge('memory.bytes', 42);

    same(1, $telemetry->dropped(), 'oldest signal should be dropped');
    same(3, $telemetry->flush(), 'three retained signals should flush');
    same(2, count($transport->requests), 'legacy batching should remain unchanged');
    same(1, $transport->requests[0]['body']['schemaVersion'], 'legacy schema version changed');
    same(1, $transport->requests[0]['body']['dropped'], 'drop evidence is missing');
});

test('OTLP encodes every signal family at its standard endpoint', static function (): void {
    $transport = new FakeTransport();
    $telemetry = new Observability(
        new ObservabilityConfig(
            endpoint: 'https://collector.example/tenant',
            serviceName: 'native-showcase',
            serviceVersion: '1.2.3',
            headers: ['authorization' => 'Bearer test'],
            wireProtocol: WireProtocol::OtlpHttpJson,
        ),
        $transport,
    );
    $telemetry->context(['deployment.environment.name' => 'test']);
    $parent = $telemetry->span('screen.open');
    $child = $telemetry->span('feed.load', $parent)->status(SpanStatus::Ok);
    $child->end();
    $parent->end();
    $telemetry->log(Severity::Warning, 'network degraded', ['retry' => true]);
    $telemetry->crash(new RuntimeException('private message'), handled: true);
    $telemetry->counter('render.count', 2, ['screen' => 'feed']);
    $telemetry->gauge('memory.bytes', 42.5);

    same(6, $telemetry->flush(), 'all OTLP signals should flush');
    same(3, count($transport->requests), 'families should produce three requests');
    same('https://collector.example/tenant/v1/traces', $transport->requests[0]['endpoint'], 'trace endpoint');
    same('https://collector.example/tenant/v1/logs', $transport->requests[1]['endpoint'], 'log endpoint');
    same('https://collector.example/tenant/v1/metrics', $transport->requests[2]['endpoint'], 'metric endpoint');
    same('application/json', $transport->requests[0]['headers']['content-type'], 'OTLP content type');
    $childSpan = objectAt($transport->requests[0]['body'], ['resourceSpans', 0, 'scopeSpans', 0, 'spans', 0]);
    $parentSpan = objectAt($transport->requests[0]['body'], ['resourceSpans', 0, 'scopeSpans', 0, 'spans', 1]);
    same($parentSpan['spanId'] ?? null, $childSpan['parentSpanId'] ?? null, 'child must preserve parent span ID');
    $childStatus = objectAt($childSpan, ['status']);
    same(1, $childStatus['code'] ?? null, 'OK status must map to OTLP OK');
    $records = objectAt($transport->requests[1]['body'], ['resourceLogs', 0, 'scopeLogs', 0, 'logRecords']);
    $warning = objectAt($records, [0]);
    $crash = objectAt($records, [1]);
    same(13, $warning['severityNumber'] ?? null, 'warning severity mapping');
    $crashBody = objectAt($crash, ['body']);
    same('Unhandled application exception', $crashBody['stringValue'] ?? null, 'crash body must be redacted');
    truth(!str_contains(json_encode($records, JSON_THROW_ON_ERROR), 'private message'), 'exception message leaked without opt-in');
    truth(!str_contains(json_encode($records, JSON_THROW_ON_ERROR), __FILE__), 'file path leaked without opt-in');
    $counter = objectAt($transport->requests[2]['body'], ['resourceMetrics', 0, 'scopeMetrics', 0, 'metrics', 0, 'sum']);
    $counterPoint = objectAt($counter, ['dataPoints', 0]);
    same('2', $counterPoint['asInt'] ?? null, 'integer metric wire type');
    same(1, $counter['aggregationTemporality'] ?? null, 'counter must use delta temporality');
    $gaugePoint = objectAt($transport->requests[2]['body'], ['resourceMetrics', 0, 'scopeMetrics', 0, 'metrics', 1, 'gauge', 'dataPoints', 0]);
    same(42.5, $gaugePoint['asDouble'] ?? null, 'gauge double wire type');
});

test('failed transport restores a complete batch', static function (): void {
    $transport = new FakeTransport();
    $transport->failNext = true;
    $telemetry = new Observability(
        new ObservabilityConfig('https://collector.example', 'retry-app'),
        $transport,
    );
    $telemetry->counter('retry.count');
    throws($telemetry->flush(...), 'first flush should fail');
    same(1, $telemetry->queued(), 'failed signal must return to the queue');
    same(1, $telemetry->flush(), 'restored signal should retry');
});

test('security validation rejects unsafe endpoints and headers', static function (): void {
    new ObservabilityConfig('http://127.0.0.1:4318', 'local-app');
    new ObservabilityConfig('http://[::1]:4318', 'local-app');
    throws(
        static fn () => new ObservabilityConfig('http://collector.example', 'app'),
        'plain HTTP must be rejected',
    );
    throws(
        static fn () => new ObservabilityConfig('https://user:secret@collector.example', 'app'),
        'URL credentials must be rejected',
    );
    throws(
        static fn () => new ObservabilityConfig(
            'https://collector.example',
            'app',
            headers: ['authorization' => "safe\r\nleak: yes"],
        ),
        'header injection must be rejected',
    );
});

test('public coded variants remain sequential integer enums', static function (): void {
    same([1, 2], array_column(WireProtocol::cases(), 'value'), 'wire protocol codes');
    same([1, 2, 3], array_column(SignalFamily::cases(), 'value'), 'signal family codes');
    same([1, 2, 3, 4, 5], array_column(SignalKind::cases(), 'value'), 'signal kind codes');
    same([1, 2, 3], array_column(SpanStatus::cases(), 'value'), 'span status codes');
    same([1, 2, 3, 4, 5], array_column(Severity::cases(), 'value'), 'severity codes');
});

test('zero sampling retains non-trace signals only', static function (): void {
    $transport = new FakeTransport();
    $telemetry = new Observability(
        new ObservabilityConfig('https://collector.example', 'sampled-app', sampleRate: 0),
        $transport,
    );
    $telemetry->span('discarded')->end();
    $telemetry->counter('retained.count');
    same(1, $telemetry->flush(), 'sampling must not discard metrics');
});

test('OTLP partial success is treated as delivery failure', static function (): void {
    OtlpResponse::assertAccepted('{}');
    throws(
        static fn () => OtlpResponse::assertAccepted(
            '{"partialSuccess":{"rejectedLogRecords":"2","errorMessage":"invalid"}}',
        ),
        'partial rejection must fail delivery',
    );
});

echo "{$tests} tests, 0 failures\n";
