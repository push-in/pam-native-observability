# PAM Native Observability

## Start here

This is a Composer extension for PAM Native. Install the PAM Runtime, create a native project, and then add this package through PAM’s verified Composer toolchain:

```bash
curl --proto '=https' --proto-redir '=https' --tlsv1.2 \
    --connect-timeout 15 --max-time 60 --max-filesize 1048576 -fsSL \
    https://github.com/push-in/pam/releases/latest/download/install.sh | sh

pam init my-app --template native
cd my-app
pam composer require pushinbr/pam-native-observability
pam doctor --fix
```


Vendor-neutral, dependency-light spans, structured logs, counters, gauges, crash context, deterministic sampling, bounded batching, and pluggable export for PAM Native apps. The default HTTPS transport works with a collector or ingestion gateway; implement `TelemetryTransport` for Sentry, Datadog, Honeycomb, New Relic, or an offline spool.

```bash
pam add observability
pam doctor
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

## Certified OTLP export

`WireProtocol::OtlpHttpJson` maps each signal to the matching OpenTelemetry
Protocol endpoint instead of relabeling PAM's original envelope:

| PAM signal | OTLP payload | Endpoint |
| --- | --- | --- |
| Span | `resourceSpans` | `/v1/traces` |
| Log and crash context | `resourceLogs` | `/v1/logs` |
| Counter and gauge | `resourceMetrics` | `/v1/metrics` |

Counters use delta temporality; integer and floating-point points keep their
wire types. Log severity and span status are translated at the external OTLP
boundary, while PAM's public enums remain sequential integers beginning at
`1`. Batches never mix signal families, and any transport or encoding failure
restores the complete batch to the bounded queue.

Remote endpoints require HTTPS. Loopback HTTP is accepted only for local
Collector development and certification. The cURL transport refuses redirects
to HTTP, limits response bodies to 64 KiB, and treats OTLP `partialSuccess`
rejections as failed delivery.

The official-Collector contract is reproducible:

```bash
composer install
scripts/certify-collector.sh
```

CI verifies the Collector's Sigstore identity and immutable image digest before
testing traces, logs, counters, gauges, parent span lineage, and exception-data
redaction.


## What installation does

`pam add observability` resolves the official compatible package, performs a non-mutating Composer preflight, updates the normal `composer.json` and `composer.lock`, refreshes generated native integration when required, and leaves the project ready for `pam doctor` validation.

Use `pam packages` to inspect availability and `pam remove observability` to uninstall the capability safely. Direct Composer commands are an advanced interoperability path; PAM is the supported application workflow.

## API guide

| API | Responsibility |
| --- | --- |
| `Observability` | Create spans, logs, counters, gauges, crash context, and flush batches. |
| `ObservabilityConfig` | Set endpoint, service identity, sampling, queue, and batch policy. |
| `WireProtocol` | Select compatible PAM JSON (`1`) or OTLP/HTTP JSON (`2`). |
| `Span` / `SpanStatus` | Capture timed operations, status, attributes, and exceptions. |
| `TraceContext` | Validate a W3C version `00` parent and continue its sampling/lineage. |
| `TelemetryTransport` | Implement vendor, collector, gateway, or offline delivery. |
| `CurlTelemetryTransport` | Send batches through the dependency-light default HTTPS transport. |
| `Severity` / `SignalKind` | Typed log severity and signal categories. |

All coded states, kinds, and variants are sequential integer-backed enums. Use enum cases in application code; do not depend on raw wire numbers.

## Production checklist

- Send remote telemetry only to HTTPS endpoints you control or explicitly trust.
- Define an attribute allowlist and never attach secrets or personal data by default.
- Keep `captureExceptionDetails` disabled unless users consent to messages,
  source paths, line numbers, and stack traces leaving the device.
- Flush on lifecycle transitions without blocking the UI indefinitely.
- Monitor dropped-signal counters and tune bounded queues from evidence.
- Run `pam doctor`, `pam test`, and a signed release build on every supported platform.
- Exercise denial, cancellation, backgrounding, process restart, and offline behavior before release.

## Troubleshooting

- **Nothing is exported:** verify endpoint, sampling decision, and explicit `flush()`.
- **Batches retry forever:** make the transport surface permanent versus transient failures.
- **Signals disappear under load:** inspect queue capacity and dropped-signal diagnostics.
- **Native integration is stale:** run `pam doctor --fix`, rebuild the native host, and inspect the first reported diagnostic.

## Compatibility and support

This package targets PAM Native `0.6.x`, Android API 26+, and iOS 15+ unless a platform-specific section above states a stricter requirement. Platform SDKs, credentials, entitlements, physical hardware, and store configuration remain application responsibilities.

- [PAM documentation](https://push-in.github.io/pam-docs/introduction/)
- [PAM Native overview](https://push-in.github.io/pam-docs/native/overview/)
- [Plugin and native capability model](https://push-in.github.io/pam-docs/native/plugins/)
- [Report an issue](https://github.com/push-in/pam-native-observability/issues)

Security vulnerabilities should be reported through the repository security policy or GitHub private vulnerability reporting, not a public issue.
