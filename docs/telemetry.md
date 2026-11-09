# Telemetry pipeline

> Moved out of the README; nothing was removed.

Vendor-neutral, dependency-light spans, structured logs, counters, gauges, crash context, deterministic sampling, bounded batching, and pluggable export for PAM Native apps. The default HTTPS transport works with a collector or ingestion gateway; use `Observability::exporter(SentryExporter::dsn(...))` for Sentry, or implement `TelemetryTransport` for Datadog, Honeycomb, New Relic, or an offline spool.

```bash
pam composer require pushinbr/pam-native-observability
pam doctor --fix
```

```php
$config = new ObservabilityConfig(
    endpoint: 'https://collector.example',
    serviceName: 'showcase-mobile',
    serviceVersion: '1.0.0',
    wireProtocol: WireProtocol::OtlpHttpJson,
);
$telemetry = new Observability($config, new CurlTelemetryTransport());
$span = $telemetry->span('feed.load');
try { loadFeed(); $span->status(SpanStatus::Ok); }
catch (Throwable $e) { $span->exception($e); throw $e; }
finally { $span->end(); $telemetry->flush(); }
```

Continue a PAM Server trace from its validated response header without
inventing a new root:

```php
$parent = TraceContext::fromTraceparent($response->header('traceparent'));
$span = $telemetry->span('native.feed.render', $parent);
```

Only lowercase W3C version `00` contexts with nonzero trace/span identifiers
are accepted. Native creates a distinct child span, preserves trace flags and
does not enqueue children of an unsampled remote context. `tracestate` remains
unsupported until a vendor allowlist and bounded forwarding policy exist.

The queue is bounded and drops the oldest signals under backpressure. Failed exports are restored to the front of the queue. Secrets and personal data are never collected automatically; applications explicitly choose context and attributes.

