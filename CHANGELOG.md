# Changelog

## 0.5.1 - 2026-10-06

### Changed

- The persisted exporter is restored off the UI thread. On Android
  `SentryBootstrap` ran `SentryAndroid.init` (options, NDK library) inside
  the content provider: ~21 ms of UI-thread time on a Galaxy S10 before the
  first Activity was created, on every cold start. It now runs on a
  background thread, and the module's own restore runs on its worker ahead of
  the PHP calls. On iOS the module restores it from its worker, so
  `SentrySDK.start` reaches the main thread after the launch returns,
  overlapping the PHP boot. A React Native app starts the SDK from
  JavaScript, after its first frame; crashes in the first milliseconds of a
  process (before the SDK is up) are no longer reported.
  iOS uncompiled on the release machine; needs device validation.

## 0.5.0 - 2026-10-06

### Added

- `SentryExporter::profilesSampleRate()`: Sentry transaction profiling on
  Android (`SentryOptions.profilesSampleRate`) and iOS
  (`Options.profilesSampleRate`). Opt-in, needs `tracesSampleRate()`.
  PHP contract test, Android instrumented assertion and XCTest mirror
  (iOS uncompiled on the release machine; needs device validation).

## 0.4.0 - 2026-10-05

### Added

- iOS Sentry exporter on the Sentry Cocoa SDK (8.x Swift package): crash
  handler, app-hang tracking, PHP events with stack frames and mechanisms,
  breadcrumbs, user, tags, flush, status, diagnostic test and stop; the
  configuration is persisted and restored at launch. Same module contract as
  Android.
- XCTest mirror (`ios/Tests`). Uncompiled on the release machine; needs
  device validation.

## 0.3.0 - 2026-10-05

### Added

- Native Sentry exporter: `Observability::exporter(SentryExporter::dsn(...)
  ->environment()->release()->dist()->sampleRate()->tracesSampleRate()
  ->tags()->anr()->nativeCrashes()->persist()->capturePhpErrors()->rateLimit())`
  backed by the Sentry Android SDK 8.50.1 (JVM crashes, ANRs and NDK crashes
  of the embedded PHP runtime).
- The exporter configuration is persisted and restored by a ContentProvider
  at process start, so crashes before the PHP runtime boots are captured.
- PHP forwarding with PHP stack frames and chained causes: uncaught exception,
  error (`E_WARNING`/`E_USER_*` by default) and fatal-shutdown handlers,
  `Observability::capture()`, `message()`, `guard()`, and the existing
  `Observability::crash()` pipeline. De-duplicated for 60 s and rate limited.
- `breadcrumb()`, `user()`, `tag()`, `exporterFlush()`, `exporterStatus()`
  (`ExporterStatus`), `exporterTest()` and `exporterStop()`.
- The package is now a PAM Native plugin (`pam-native.plugin.json`, Android
  module `observability`, iOS stub) with an Android instrumented suite that
  verifies delivered Sentry envelopes.

### Changed

- Requires PAM Native `>=1.0.35 <2.0.0`.

## 0.2.0 - 2026-08-23

- Add certified OTLP/HTTP JSON encoding for traces, logs, crashes, delta
  counters, and gauges with signal-specific endpoints.
- Preserve the schema 1 PAM JSON wire protocol as the default compatibility
  mode through the sequential integer-backed `WireProtocol` enum.
- Add bounded Collector responses, partial-success rejection, full-batch
  restoration, loopback-only HTTP, opt-in exception details, PHPStan level 9,
  a reproducible dependency lock, and official-Collector CI certification.
- Add strict W3C version `00` parent import with preserved trace ID, parent span
  ID and sampling flags for Server-to-Native trace continuation.

## 0.1.0 - 2026-08-01

- Initial public release of the documented PAM Native package contract.
- Add bounded input validation, sequential integer protocol enums, automated
  package tests, and PHP 8.4/8.5 continuous integration.
