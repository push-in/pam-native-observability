<?php

declare(strict_types=1);

namespace Pam\Native\Observability;

use InvalidArgumentException;

/**
 * Sentry exporter for `Observability::exporter()`.
 *
 * ```php
 * Observability::exporter(
 *     SentryExporter::dsn('https://public@o1.ingest.sentry.io/42')
 *         ->environment('production')
 *         ->release('chat@2.4.0+118'),
 * );
 * ```
 *
 * The native Sentry Android SDK captures JVM crashes, ANRs and native (NDK)
 * crashes of the embedded PHP runtime; PHP uncaught exceptions, selected PHP
 * errors, fatal shutdowns and `Observability::capture()` are forwarded with
 * PHP stack frames. The configuration is persisted (see `persist()`) so the
 * next launch is covered before PHP boots.
 */
final class SentryExporter
{
    private const int DEFAULT_PHP_ERRORS = E_WARNING | E_USER_WARNING | E_USER_ERROR | E_RECOVERABLE_ERROR;

    private string $environment = '';

    private string $release = '';

    private string $dist = '';

    private float $sampleRate = 1.0;

    private ?float $tracesSampleRate = null;

    private bool $sendDefaultPii = false;

    private bool $debug = false;

    /** @var array<string, string> */
    private array $tags = [];

    private bool $anr = true;

    private int $anrTimeoutMillis = 5000;

    private bool $nativeCrashes = true;

    private bool $persist = true;

    private int $maxBreadcrumbs = 100;

    private bool $capturePhpErrors = true;

    private int $phpErrorLevels = self::DEFAULT_PHP_ERRORS;

    private int $eventsPerMinute = 30;

    private function __construct(public readonly string $dsn)
    {
    }

    public static function dsn(string $dsn): self
    {
        $parts = parse_url($dsn);
        if (!is_array($parts)
            || ($parts['user'] ?? '') === ''
            || isset($parts['query']) || isset($parts['fragment'])
            || preg_match('#^/(?:[A-Za-z0-9._~-]+/)*[0-9]+$#D', $parts['path'] ?? '') !== 1
            || !EndpointPolicy::valid(($parts['scheme'] ?? '').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : ''))
        ) {
            throw new InvalidArgumentException('Sentry DSN must look like https://<public-key>@<host>/<project-id> (HTTP only on loopback).');
        }

        return new self($dsn);
    }

    public function environment(string $environment): self
    {
        $this->environment = self::label($environment, 'environment');

        return $this;
    }

    public function release(string $release): self
    {
        $this->release = self::label($release, 'release', 200);

        return $this;
    }

    public function dist(string $dist): self
    {
        $this->dist = self::label($dist, 'dist');

        return $this;
    }

    /** Fraction of error events sent (0..1). */
    public function sampleRate(float $rate): self
    {
        $this->sampleRate = self::rate($rate);

        return $this;
    }

    /** Enables Sentry performance tracing for the native SDK (0..1). */
    public function tracesSampleRate(float $rate): self
    {
        $this->tracesSampleRate = self::rate($rate);

        return $this;
    }

    /** Lets Sentry attach IP addresses and similar personal data (off by default). */
    public function sendDefaultPii(bool $enabled = true): self
    {
        $this->sendDefaultPii = $enabled;

        return $this;
    }

    public function debug(bool $enabled = true): self
    {
        $this->debug = $enabled;

        return $this;
    }

    public function tag(string $key, string $value): self
    {
        $this->tags[self::key($key)] = self::label($value, 'tag value', 200);

        return $this;
    }

    /** @param array<string, string> $tags */
    public function tags(array $tags): self
    {
        foreach ($tags as $key => $value) {
            $this->tag($key, $value);
        }

        return $this;
    }

    /** Application Not Responding detection (main thread blocked for `$timeoutMillis`). */
    public function anr(bool $enabled = true, int $timeoutMillis = 5000): self
    {
        if ($timeoutMillis < 1000 || $timeoutMillis > 60_000) {
            throw new InvalidArgumentException('ANR timeout must be 1000-60000 ms.');
        }
        $this->anr = $enabled;
        $this->anrTimeoutMillis = $timeoutMillis;

        return $this;
    }

    /** Native (NDK) crash capture, which covers crashes inside the embedded PHP runtime. */
    public function nativeCrashes(bool $enabled = true): self
    {
        $this->nativeCrashes = $enabled;

        return $this;
    }

    /** Persist the configuration so the next launch starts Sentry before PHP boots (default on). */
    public function persist(bool $enabled = true): self
    {
        $this->persist = $enabled;

        return $this;
    }

    public function maxBreadcrumbs(int $count): self
    {
        if ($count < 0 || $count > 500) {
            throw new InvalidArgumentException('Breadcrumbs must be 0-500.');
        }
        $this->maxBreadcrumbs = $count;

        return $this;
    }

    /**
     * Installs PHP exception, error and shutdown handlers that forward to
     * Sentry. `$levels` selects which `E_*` errors become events (warnings and
     * user errors by default; notices and deprecations are ignored).
     */
    public function capturePhpErrors(bool $enabled = true, int $levels = self::DEFAULT_PHP_ERRORS): self
    {
        $this->capturePhpErrors = $enabled;
        $this->phpErrorLevels = $levels;

        return $this;
    }

    /** Upper bound of PHP-originated events per minute; identical events are also de-duplicated for 60 s. */
    public function rateLimit(int $eventsPerMinute = 30): self
    {
        if ($eventsPerMinute < 1 || $eventsPerMinute > 600) {
            throw new InvalidArgumentException('Rate limit must be 1-600 events per minute.');
        }
        $this->eventsPerMinute = $eventsPerMinute;

        return $this;
    }

    public function capturesPhpErrors(): bool
    {
        return $this->capturePhpErrors;
    }

    public function phpErrorLevels(): int
    {
        return $this->phpErrorLevels;
    }

    public function eventsPerMinute(): int
    {
        return $this->eventsPerMinute;
    }

    /** @return array<string, mixed> */
    public function toWire(): array
    {
        $wire = [
            'dsn' => $this->dsn,
            'environment' => $this->environment,
            'release' => $this->release,
            'dist' => $this->dist,
            'sampleRate' => $this->sampleRate,
            'sendDefaultPii' => $this->sendDefaultPii,
            'debug' => $this->debug,
            'tags' => (object) $this->tags,
            'anr' => $this->anr,
            'anrTimeoutMs' => $this->anrTimeoutMillis,
            'nativeCrashes' => $this->nativeCrashes,
            'persist' => $this->persist,
            'maxBreadcrumbs' => $this->maxBreadcrumbs,
        ];
        if ($this->tracesSampleRate !== null) {
            $wire['tracesSampleRate'] = $this->tracesSampleRate;
        }

        return $wire;
    }

    private static function label(string $value, string $name, int $max = 64): string
    {
        if ($value === '' || strlen($value) > $max || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new InvalidArgumentException("Invalid Sentry {$name}.");
        }

        return $value;
    }

    private static function key(string $key): string
    {
        if (preg_match('/^[A-Za-z0-9_.:-]{1,32}$/D', $key) !== 1) {
            throw new InvalidArgumentException('Sentry tag keys use 1-32 letters, digits, "_", ".", ":" or "-".');
        }

        return $key;
    }

    private static function rate(float $rate): float
    {
        if (!is_finite($rate) || $rate < 0 || $rate > 1) {
            throw new InvalidArgumentException('Sample rates must be between 0 and 1.');
        }

        return $rate;
    }
}
