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
        ->tracesSampleRate(0.25)
        ->profilesSampleRate(0.05),   // opt-in; profiles a share of the traced transactions
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

Exceptions thrown inside PAM callbacks (renders, event handlers, module
results) are caught by the framework runtime (error overlay) and do not reach
PHP's global exception handler. Since PAM Native 1.7.0 the runtime exposes them
through `App::onError()`; forward them once at boot:

```php
use Pam\Native\App;
use Pam\Native\Diagnostics\RuntimeError;

App::onError(function (Throwable $error, RuntimeError $context): void {
    Observability::capture($error, handled: !$context->fatal(), tags: ['phase' => $context->phase->value]);
});
```

On older PAM Native versions wrap critical callbacks with
`Observability::guard()` or call `capture()` yourself.

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

`pam add observability` (or `pam composer require pushinbr/pam-native-observability` followed by `pam doctor --fix`) resolves the official compatible package, performs a non-mutating Composer preflight, updates the normal `composer.json` and `composer.lock`, refreshes generated native integration when required, and leaves the project ready for `pam doctor` validation. The package is a PAM Native plugin (module `observability`); nothing is added to `pam-native.json`. The pure-PHP telemetry pipeline (`Observability` instances, encoders, transports) also works outside PAM Native.

Use `pam packages` to inspect availability and `pam remove observability` to uninstall the capability safely. Direct Composer commands are an advanced interoperability path; PAM is the supported application workflow.

- **Android:** merged permissions `INTERNET` and `ACCESS_NETWORK_STATE`.
  Dependencies `io.sentry:sentry-android-core` and `sentry-android-ndk`
  `8.50.1`. The manifest disables Sentry's auto-init
  (`io.sentry.auto-init=false`, `SentryInitProvider` removed) and adds the
  `SentryBootstrap` content provider that restores the persisted exporter
  before PHP boots.
- **iOS:** Swift package `getsentry/sentry-cocoa` from `8.40.0` (up to next
  major), product `Sentry`. No Info.plist keys. Upload dSYMs to Sentry for
  symbolicated native frames.
- `CurlTelemetryTransport` needs the PHP `curl` extension; implement
  `TelemetryTransport` when it is not available.

## A real example: Zé Chat

Zé Chat starts the Sentry exporter once at boot, before the first screen
renders, so crashes of the embedded PHP runtime and ANRs are reported from the
first launch; the configuration is persisted for crashes that happen before
PHP boots on the next launch:

```php
use Pam\Native\Observability\{ExporterStatus, Observability, SentryExporter};

final class CrashReporting
{
    private static bool $started = false;

    public static function start(): void
    {
        if (self::$started) {
            return;
        }
        self::$started = true;
        Observability::exporter(
            SentryExporter::dsn('https://public@sentry.example.com/14')
                ->environment('production')
                ->tracesSampleRate(0.25)
                ->profilesSampleRate(0.05),   // 0.5.0+
        );
    }
}

// Settings → Diagnostics screen.
Observability::exporterStatus(fn (ExporterStatus $s) => $this->sentry = $s->enabled ? "{$s->host} ({$s->environment})" : $s->error);
Observability::exporterTest(fn (bool $ok, string $idOrError) => $this->toast($ok ? "Sent {$idOrError}" : $idOrError));
```

A runnable minimal app is in [`example/`](example).

## API reference

All classes live in `Pam\Native\Observability`.

### Sentry exporter (static, module `observability`)

| Method | Description |
| --- | --- |
| `Observability::exporter(SentryExporter $exporter, ?Closure(bool, ?string) $then = null): int` | Starts (or reconfigures) the native Sentry SDK and installs the PHP handlers. |
| `capture(Throwable $error, bool $handled = true, array $tags = [], array $extra = [], ?Closure(?string) $then = null): bool` | Reports a throwable (with PHP frames and up to five causes); `$then` receives the event id. Returns `false` when nothing is sent (no exporter, duplicate within 60 s, rate limit). |
| `message(string $message, Severity $level = Info, array $tags = [], ?Closure(?string) $then = null): bool` | Reports a message. |
| `breadcrumb(string $message, string $category = 'app', array $data = [], Severity $level = Info): void` | Adds a breadcrumb. |
| `user(?string $id, ?string $email = null, ?string $username = null): void`, `tag(string $key, string $value): void` | Scope. |
| `guard(Closure(): T $body): T` | Runs `$body`, reports and rethrows any throwable. |
| `exporterFlush(int $timeoutMillis = 2000, ?Closure $then = null)` | Flushes queued events (0–30000 ms). |
| `exporterStatus(Closure(ExporterStatus) $then)`, `exporterTest(Closure(bool, string) $then)`, `exporterStop(?Closure $then = null)` | Diagnostics and shutdown. |

`SentryExporter` (immutable builder): `dsn(string $dsn)` (HTTPS; HTTP only on
loopback), `environment()`, `release()`, `dist()`, `sampleRate(float)`,
`tracesSampleRate(float)`, `profilesSampleRate(float)` (0.5.0+, needs traces),
`sendDefaultPii(bool = true)` (off by default), `debug()`,
`tag(string, string)`, `tags(array)`, `anr(bool = true, int $timeoutMillis = 5000)`
(1000–60000), `nativeCrashes(bool = true)`, `persist(bool = true)`,
`maxBreadcrumbs(int)` (0–500, default 100),
`capturePhpErrors(bool = true, int $levels = E_WARNING | E_USER_WARNING | E_USER_ERROR | E_RECOVERABLE_ERROR)`,
`rateLimit(int $eventsPerMinute = 30)` (1–600); `capturesPhpErrors()`,
`phpErrorLevels()`, `eventsPerMinute()`, `toWire()`. ANR, native crashes,
persistence and PHP error capture are on by default.

`ExporterStatus` (readonly): `enabled`, `host`, `environment`, `release`,
`nativeCrashes`, `anr`, `lastEventId`, `platform`, `error`.

### Telemetry pipeline (instances)

| API | Description |
| --- | --- |
| `new Observability(ObservabilityConfig $config, TelemetryTransport $transport)` | Bounded queue and batching. |
| `context(array $values)` | Resource attributes added to every signal. |
| `span(string $name, Span\|TraceContext\|null $parent = null): Span` | Starts a span (child of a span or a remote W3C context). |
| `log(Severity $severity, string $message, array $attributes = [])` | Structured log. |
| `counter(string $name, int\|float $value = 1, array $attributes = [])`, `gauge(string $name, int\|float $value, array $attributes = [])` | Metrics (counters use delta temporality in OTLP). |
| `crash(Throwable $error, bool $handled = false)` | Crash context (details only with `captureExceptionDetails`). |
| `flush(): int` | Sends queued batches; returns the number of signals sent. A transport failure restores the batch and rethrows. |
| `queued(): int`, `dropped(): int` | Backpressure diagnostics. |

`ObservabilityConfig(string $endpoint, string $serviceName, string $serviceVersion = '0.0.0', float $sampleRate = 1.0, int $batchSize = 64, int $maxQueue = 1024, int $timeoutMillis = 5000, array $headers = [], WireProtocol $wireProtocol = PamJson, bool $captureExceptionDetails = false)`.
`Span` (readonly `name`, `traceId`, `spanId`, `parentSpanId`, `traceFlags`):
`attribute(string, scalar)`, `status(SpanStatus)`, `exception(Throwable)`,
`end()` (also on destruction). `TraceContext`: `fromTraceparent(string)`,
`traceparent()`, `sampled()`, readonly `traceId`, `spanId`, `flags`.

Transports implement `TelemetryTransport::send(string $endpoint, string $body, array $headers, int $timeoutMillis): void`
and throw to signal a failed delivery. `CurlTelemetryTransport` is the
default. `TelemetryEncoder`, `PamJsonEncoder`, `OtlpHttpJsonEncoder`,
`EncodedTelemetry`, `OtlpResponse` and `EndpointPolicy` (`valid()`,
`permitsHttp()`) are the building blocks.

### Enums (int-backed)

| Enum | Cases |
| --- | --- |
| `Severity` | `Debug = 1`, `Info`, `Warning`, `Error`, `Fatal = 5`; `otlpNumber()`, `otlpText()` |
| `SpanStatus` | `Unset = 1`, `Ok`, `Error` |
| `SignalKind` | `Span = 1`, `Log`, `Counter`, `Gauge`, `Crash = 5`; `family()` |
| `SignalFamily` | `Trace = 1`, `Log`, `Metric` |
| `WireProtocol` | `PamJson = 1`, `OtlpHttpJson = 2` |

### Errors

`InvalidArgumentException` for an invalid DSN, sample rates outside 0–1, ANR
timeouts outside 1–60 s, breadcrumb limits outside 0–500, rate limits outside
1–600, invalid tag keys, span or metric names, telemetry headers, configuration
values and `traceparent` headers. `CurlTelemetryTransport` throws
`RuntimeException` without the curl extension and on failed or partially
rejected (`partialSuccess`) deliveries; `flush()` puts the batch back at the
front of the queue and rethrows. The static Sentry methods never throw for
delivery problems.

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

| `pushinbr/pam-native-observability` | `pushinbr/pam-native` | Android | iOS |
| --- | --- | --- | --- |
| 0.5.x | `>=1.0.35 <2.0.0` (tested with 1.14.x; `App::onError()` needs 1.7+) | API 26+, Sentry Android 8.50.1 | 15+, Sentry Cocoa 8.x |
| 0.4.x | `>=1.0.35 <2.0.0` | API 26+ | 15+ |
| 0.3.x | `>=1.0.35 <2.0.0` | API 26+ | Telemetry pipeline only |

This package targets PAM Native `>=1.0.35 <2.0.0`, Android API 26+, and iOS 15+ unless a platform-specific section above states a stricter requirement. Platform SDKs, credentials, entitlements, physical hardware, and store configuration remain application responsibilities.

- [PAM documentation](https://push-in.github.io/pam-docs/introduction/)
- [PAM Native overview](https://push-in.github.io/pam-docs/native/overview/)
- [Plugin and native capability model](https://push-in.github.io/pam-docs/native/plugins/)
- [Report an issue](https://github.com/push-in/pam-native-observability/issues)

Security vulnerabilities should be reported through the repository security policy or GitHub private vulnerability reporting, not a public issue.
