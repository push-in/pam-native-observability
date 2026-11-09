# A real example: Zé Chat

> Moved out of the README; nothing was removed.

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

A runnable minimal app is in [`example/`](../example).

