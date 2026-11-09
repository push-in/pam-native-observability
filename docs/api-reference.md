# API reference

> Moved out of the README; nothing was removed.

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
(1000–60000), `nativeCrashes(bool = true)`, `framesTracking(bool = true)` (off unless called; on iOS it keeps a display link running in the foreground), `persist(bool = true)`,
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
