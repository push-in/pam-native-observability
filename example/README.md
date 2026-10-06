# Observability demo

A one-screen PAM Native app for `pushinbr/pam-native-observability`: the
Sentry exporter started at boot (as Zé Chat does), `App::onError()` forwarding
errors thrown inside PAM callbacks, handled captures with chained causes,
breadcrumbs, user and tags, the exporter status and a diagnostic test event.

1. Paste your DSN into `DiagnosticsDemo::DSN` (`src/DiagnosticsDemo.php`).
2. Run it:

```bash
cd example
pam composer install
pam doctor --fix
pam dev            # or: pam build
```

The app installs the released package from Packagist. "Throw in a press
handler" shows the runtime error overlay in debug builds and still reaches
Sentry through `App::onError()`.
