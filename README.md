# PAM Native Observability

Vendor-neutral, dependency-light spans, structured logs, counters, gauges, crash context, deterministic sampling, bounded batching, and pluggable export for PAM Native apps. The default HTTPS transport works with a collector or ingestion gateway; implement `TelemetryTransport` for Sentry, Datadog, Honeycomb, New Relic, or an offline spool.

```bash
pam add observability
pam doctor
```

```php
$telemetry = new Observability($config, new CurlTelemetryTransport());
$span = $telemetry->span('feed.load');
try { loadFeed(); $span->status(SpanStatus::Ok); }
catch (Throwable $e) { $span->exception($e); throw $e; }
finally { $span->end(); $telemetry->flush(); }
```

The queue is bounded and drops the oldest signals under backpressure. Failed exports are restored to the front of the queue. Secrets and personal data are never collected automatically; applications explicitly choose context and attributes.


## What installation does

`pam add observability` resolves the official compatible package, performs a non-mutating Composer preflight, updates the normal `composer.json` and `composer.lock`, refreshes generated native integration when required, and leaves the project ready for `pam doctor` validation.

Use `pam packages` to inspect availability and `pam remove observability` to uninstall the capability safely. Direct Composer commands are an advanced interoperability path; PAM is the supported application workflow.

## API guide

| API | Responsibility |
| --- | --- |
| `Observability` | Create spans, logs, counters, gauges, crash context, and flush batches. |
| `ObservabilityConfig` | Set endpoint, service identity, sampling, queue, and batch policy. |
| `Span` / `SpanStatus` | Capture timed operations, status, attributes, and exceptions. |
| `TelemetryTransport` | Implement vendor, collector, gateway, or offline delivery. |
| `CurlTelemetryTransport` | Send batches through the dependency-light default HTTPS transport. |
| `Severity` / `SignalKind` | Typed log severity and signal categories. |

All coded states, kinds, and variants are sequential integer-backed enums. Use enum cases in application code; do not depend on raw wire numbers.

## Production checklist

- Send telemetry only to HTTPS endpoints you control or explicitly trust.
- Define an attribute allowlist and never attach secrets or personal data by default.
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
