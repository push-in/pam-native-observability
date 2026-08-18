<?php

declare(strict_types=1);

use Pam\Native\Observability\CurlTelemetryTransport;
use Pam\Native\Observability\Observability;
use Pam\Native\Observability\ObservabilityConfig;
use Pam\Native\Observability\Severity;
use Pam\Native\Observability\SpanStatus;
use Pam\Native\Observability\TraceContext;
use Pam\Native\Observability\WireProtocol;

require dirname(__DIR__).'/vendor/autoload.php';

$endpoint = getenv('PAM_OTLP_ENDPOINT');
if (!is_string($endpoint) || $endpoint === '') {
    fwrite(STDERR, "PAM_OTLP_ENDPOINT is required\n");
    exit(64);
}

$telemetry = new Observability(
    new ObservabilityConfig(
        endpoint: $endpoint,
        serviceName: 'pam-native-certification',
        serviceVersion: '0.2.0',
        batchSize: 8,
        maxQueue: 64,
        wireProtocol: WireProtocol::OtlpHttpJson,
    ),
    new CurlTelemetryTransport(),
);
$parent = $telemetry->span('native.screen.open');
$child = $telemetry->span('native.feed.load', $parent)->status(SpanStatus::Ok);
$child->end();
$parent->end();
$remote = TraceContext::fromTraceparent(
    '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
);
$telemetry->span('native.server.continued', $remote)->status(SpanStatus::Ok)->end();
$telemetry->log(Severity::Info, 'native-ready', ['surface.code' => 2]);
$telemetry->crash(new RuntimeException('must-not-leak'), handled: true);
$telemetry->counter('native.render.count', 2);
$telemetry->gauge('native.memory.bytes', 42.5);

if ($telemetry->flush() !== 7) {
    fwrite(STDERR, "not every signal was exported\n");
    exit(1);
}
