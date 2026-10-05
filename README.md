<!-- pam:product-page:start -->
<div align="center">

# PAM Native Observability

**Traces, metrics, logs, and crash context without vendor lock-in.**

Instrument PHP, Rust, and native boundaries with redacted, bounded telemetry that can be exported to the backend of your choice.

[![Latest version](https://img.shields.io/packagist/v/pushinbr/pam-native-observability?style=flat-square&label=stable)](https://packagist.org/packages/pushinbr/pam-native-observability)
[![CI](https://img.shields.io/github/actions/workflow/status/push-in/pam-native-observability/ci.yml?branch=main&style=flat-square&label=CI)](https://github.com/push-in/pam-native-observability/actions)
![PHP](https://img.shields.io/badge/PHP-8.5-777BB4?style=flat-square&logo=php&logoColor=white)
![Android](https://img.shields.io/badge/Android-API%2026%2B-3DDC84?style=flat-square&logo=android&logoColor=white)
![iOS](https://img.shields.io/badge/iOS-15%2B-000000?style=flat-square&logo=apple&logoColor=white)

**[Documentation](https://push-in.github.io/pam-docs/native/overview/) · [Quick start](#quick-start) · [What you can build](#what-you-can-build) · [PAM ecosystem](https://push-in.github.io/pam-docs/ecosystem/) · [Issues](https://github.com/push-in/pam-native-observability/issues)**

</div>

---

## Why PAM Native Observability

Instrument PHP, Rust, and native boundaries with redacted, bounded telemetry that can be exported to the backend of your choice. The public API is strictly typed for PHP 8.5; expensive or frame-sensitive work stays in Rust or the platform SDK instead of crossing the application boundary every frame.

| | |
| --- | --- |
| **Best for** | A focused capability you can add to any PAM Native application |
| **Native path** | OpenTelemetry-compatible native pipeline |
| **Application model** | Composer package + generated native integration |
| **Design rule** | Independent module; no feed, vertical, or application template bundled |

## What you can build

- Cross-layer performance tracing
- Crash and error correlation
- Production health dashboards and SLO evidence

## Quick start

Already have a PAM Native project? Add only this capability:

```bash
pam composer require pushinbr/pam-native-observability
pam doctor --fix
```

New to PAM? Follow the **[five-minute PAM Native setup](https://push-in.github.io/pam-docs/native/overview/)** once, then return here. Your application stays a normal Composer project with a committed lockfile.
<!-- pam:product-page:end -->

## See it in action

Vendor-neutral, dependency-light spans, structured logs, counters, gauges, crash context, deterministic sampling, bounded batching, and pluggable export for PAM Native apps. The default HTTPS transport works with a collector or ingestion gateway; use `Observability::exporter(SentryExporter::dsn(...))` for Sentry, or implement `TelemetryTransport` for Datadog, Honeycomb, New Relic, or an offline spool.

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

## Sentry exporter (native crashes + PHP errors)

```php
use Pam\Native\Observability\Observability;
use Pam\Native\Observability\ExporterStatus;
use Pam\Native\Observability\SentryExporter;

// Once, at boot.
Observability::exporter(
    SentryExporter::dsn('https://public@o1.ingest.sentry.io/42')
        ->environment('production')
        ->release('chat@2.4.0+118')
        ->tracesSampleRate(0.25),
);

Observability::user('42');                                  // after sign-in; user(null) on sign-out
Observability::breadcrumb('Opened chat 42', 'navigation');
Observability::capture($error, tags: ['screen' => 'chat']); // handled throwable
$result = Observability::guard(fn () => $repository->load()); // report and rethrow
Observability::exporterStatus(fn (ExporterStatus $s) => ...); // diagnostics screen
Observability::exporterTest(fn (bool $ok, string $idOrError) => ...);
```

What is captured:

| Source | How |
| --- | --- |
| JVM crashes and ANRs | Sentry Android SDK (`->anr(true, 5000)`) |
| Native crashes, including the embedded PHP runtime | Sentry NDK integration (`->nativeCrashes()`) |
| Crashes before PHP boots | the configuration is persisted (`->persist()`) and restored by a ContentProvider on the next launch |
| PHP uncaught exceptions, `E_WARNING`/`E_USER_*` errors, fatal shutdowns | handlers installed by `->capturePhpErrors()` (previous handlers are chained) |
| Handled PHP errors | `Observability::capture()`, `message()`, `guard()` and the existing `$telemetry->crash()` |

PHP events carry PHP stack frames (oldest first, `vendor/` frames marked as not
in-app) and up to five chained causes. Identical events are de-duplicated for
60 s and PHP-originated events are rate limited (`->rateLimit(30)` per
minute): every native call completion costs a PAM render, so error storms must
not reach the bridge. Personal data is off by default (`->sendDefaultPii()`).
The Sentry manifest auto-init is disabled; the DSN comes only from PHP.

Known limit: exceptions thrown inside PAM callbacks are caught by the
framework runtime (error overlay) and do not reach PHP's global exception
handler. Wrap critical callbacks with `Observability::guard()` or call
`capture()` until the framework exposes an error hook.

Android API 26+ and iOS 15+. On iOS the exporter runs on the Sentry Cocoa SDK
(8.x Swift package): native crashes (signal/Mach handler), app hangs
(`anr()`/`anrTimeoutMs`), PHP events with PHP stack frames, breadcrumbs,
user, tags, flush, status and the diagnostic test event. The persisted
configuration starts Sentry when the module is created at launch, before the
PHP runtime runs. Upload dSYMs for symbolicated native frames. The iOS
implementation has not been validated on a device yet; see
`ios/Tests/SentryBridgeTests.swift`.

Instrumented suite (sends real envelopes to a loopback MockWebServer):

```bash
cd android && ANDROID_SERIAL=emulator-5558 \
  ../../../pam-native/android/gradlew -p . connectedDebugAndroidTest
```

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

This package targets PAM Native `>=1.0.35 <2.0.0`, Android API 26+, and iOS 15+ unless a platform-specific section above states a stricter requirement. Platform SDKs, credentials, entitlements, physical hardware, and store configuration remain application responsibilities.

- [PAM documentation](https://push-in.github.io/pam-docs/introduction/)
- [PAM Native overview](https://push-in.github.io/pam-docs/native/overview/)
- [Plugin and native capability model](https://push-in.github.io/pam-docs/native/plugins/)
- [Report an issue](https://github.com/push-in/pam-native-observability/issues)

Security vulnerabilities should be reported through the repository security policy or GitHub private vulnerability reporting, not a public issue.
