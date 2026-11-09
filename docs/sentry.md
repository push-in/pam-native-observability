# Sentry exporter (native crashes + PHP errors)

> Moved out of the README; nothing was removed.


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

