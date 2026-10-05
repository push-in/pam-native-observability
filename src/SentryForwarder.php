<?php

declare(strict_types=1);

namespace Pam\Native\Observability;

use Closure;
use ErrorException;
use JsonException;
use Pam\Native\Modules\NativeModuleResult;
use Pam\Native\Modules\NativeModules;
use Throwable;

/**
 * @internal Converts PHP throwables to Sentry events and forwards them to the
 * native exporter. Use the static methods on `Observability`.
 */
final class SentryForwarder
{
    public const string MODULE = 'observability';

    private const int MAX_FRAMES = 100;

    private const int MAX_CHAIN = 5;

    private const int FATAL = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;

    private static ?SentryExporter $exporter = null;

    private static bool $handlersInstalled = false;

    private static bool $forwarding = false;

    /** @var array<string, float> fingerprint => last sent */
    private static array $recent = [];

    private static float $windowStart = 0.0;

    private static int $windowCount = 0;

    private function __construct()
    {
    }

    /** @param null|Closure(bool, ?string): void $then */
    public static function install(SentryExporter $exporter, ?Closure $then): int
    {
        self::$exporter = $exporter;
        self::$recent = [];
        if ($exporter->capturesPhpErrors() && !self::$handlersInstalled) {
            self::$handlersInstalled = true;
            self::installHandlers();
        }

        return NativeModules::call(self::MODULE, 'sentryInit', ['config' => self::json($exporter->toWire())], static function (NativeModuleResult $result) use ($then): void {
            $then?->__invoke($result->succeeded(), $result->succeeded() ? null : $result->message());
        });
    }

    public static function active(): bool
    {
        return self::$exporter !== null;
    }

    public static function reset(): void
    {
        self::$exporter = null;
        self::$recent = [];
        self::$windowCount = 0;
    }

    /**
     * @param array<string, string> $tags
     * @param array<string, scalar|null> $extra
     * @param null|Closure(?string): void $then receives the Sentry event id
     */
    public static function capture(
        Throwable $error,
        bool $handled = true,
        Severity $level = Severity::Error,
        array $tags = [],
        array $extra = [],
        string $mechanism = 'pam.php.capture',
        ?Closure $then = null,
    ): bool {
        $fingerprint = $error::class."\0".$error->getMessage()."\0".$error->getFile()."\0".$error->getLine();

        return self::send([
            'level' => $level->value,
            'handled' => $handled,
            'exceptions' => self::exceptions($error, $mechanism),
            'tags' => (object) self::strings($tags),
            'extra' => (object) self::strings($extra),
            'logger' => 'pam.php',
        ], $fingerprint, $then);
    }

    /**
     * @param array<string, string> $tags
     * @param null|Closure(?string): void $then
     */
    public static function message(string $message, Severity $level, array $tags = [], ?Closure $then = null): bool
    {
        return self::send([
            'level' => $level->value,
            'message' => substr($message, 0, 8192),
            'tags' => (object) self::strings($tags),
            'logger' => 'pam.php',
        ], 'message'."\0".$message, $then);
    }

    /** @param array<string, scalar|null> $data */
    public static function breadcrumb(string $message, string $category, array $data, Severity $level): void
    {
        if (!self::active()) {
            return;
        }
        self::fire('sentryBreadcrumb', ['breadcrumb' => self::json([
            'message' => substr($message, 0, 2048),
            'category' => substr($category, 0, 64),
            'level' => $level->value,
            'data' => (object) self::strings($data),
        ])]);
    }

    /** @param array<string, string|int|float|bool> $values */
    public static function fire(string $method, array $values): void
    {
        try {
            NativeModules::call(self::MODULE, $method, $values, static fn (NativeModuleResult $result): null => null);
        } catch (Throwable) {
            // Reporting must never break the application.
        }
    }

    /** @return list<array{type: string, value: string, mechanism: string, frames: list<array<string, string|int|bool>>}> */
    public static function exceptions(Throwable $error, string $mechanism): array
    {
        $chain = [];
        for ($current = $error; $current !== null && count($chain) < self::MAX_CHAIN; $current = $current->getPrevious()) {
            $chain[] = [
                'type' => $current::class,
                'value' => substr($current->getMessage(), 0, 8192),
                'mechanism' => $mechanism,
                'frames' => self::frames($current),
            ];
        }

        // Sentry lists the root cause first and the reported exception last.
        return array_reverse($chain);
    }

    /** @return list<array<string, string|int|bool>> oldest call first, as Sentry expects */
    public static function frames(Throwable $error): array
    {
        $frames = [];
        $file = $error->getFile();
        $line = $error->getLine();
        foreach ($error->getTrace() as $call) {
            $class = $call['class'] ?? '';
            $frames[] = self::frame($file, $line, $class.($call['type'] ?? '').$call['function'], $class);
            $file = $call['file'] ?? '[internal]';
            $line = $call['line'] ?? 0;
            if (count($frames) >= self::MAX_FRAMES - 1) {
                break;
            }
        }
        $frames[] = self::frame($file, $line, '{main}', '');

        return array_reverse($frames);
    }

    /** @param array<string, mixed> $event @param null|Closure(?string): void $then */
    private static function send(array $event, string $fingerprint, ?Closure $then): bool
    {
        if (!self::active() || self::$forwarding || !self::admit($fingerprint)) {
            $then?->__invoke(null);

            return false;
        }
        self::$forwarding = true;
        try {
            NativeModules::call(self::MODULE, 'sentryCapture', ['event' => self::json($event)], static function (NativeModuleResult $result) use ($then): void {
                $id = $result->succeeded() ? (string) ($result->values()['eventId'] ?? '') : '';
                $then?->__invoke($id === '' ? null : $id);
            });
        } catch (Throwable) {
            return false;
        } finally {
            self::$forwarding = false;
        }

        return true;
    }

    private static function admit(string $fingerprint): bool
    {
        $now = microtime(true);
        $key = hash('xxh3', $fingerprint);
        if (isset(self::$recent[$key]) && $now - self::$recent[$key] < 60.0) {
            return false;
        }
        if ($now - self::$windowStart >= 60.0) {
            self::$windowStart = $now;
            self::$windowCount = 0;
        }
        if (self::$windowCount >= (self::$exporter?->eventsPerMinute() ?? 30)) {
            return false;
        }
        self::$windowCount++;
        if (count(self::$recent) >= 256) {
            self::$recent = array_slice(self::$recent, -128, preserve_keys: true);
        }
        self::$recent[$key] = $now;

        return true;
    }

    private static function installHandlers(): void
    {
        $previousException = null;
        $previousException = set_exception_handler(static function (Throwable $error) use (&$previousException): void {
            self::capture($error, handled: false, level: Severity::Fatal, mechanism: 'pam.php.uncaught');
            if (is_callable($previousException)) {
                $previousException($error);

                return;
            }
            error_log('PHP Fatal error:  Uncaught '.$error);
        });

        $previousError = null;
        $previousError = set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0) use (&$previousError): bool {
            $levels = self::$exporter?->capturesPhpErrors() === true ? self::$exporter->phpErrorLevels() : 0;
            if (($levels & $severity) !== 0 && (error_reporting() & $severity) !== 0) {
                self::capture(
                    new ErrorException($message, 0, $severity, $file, $line),
                    level: ($severity & self::FATAL) !== 0 ? Severity::Error : Severity::Warning,
                    mechanism: 'pam.php.error_handler',
                );
            }

            return is_callable($previousError) ? (bool) $previousError($severity, $message, $file, $line) : false;
        });

        register_shutdown_function(static function (): void {
            $last = error_get_last();
            if ($last === null || ($last['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR)) === 0) {
                return;
            }
            self::capture(
                new ErrorException($last['message'], 0, $last['type'], $last['file'], $last['line']),
                handled: false,
                level: Severity::Fatal,
                mechanism: 'pam.php.fatal',
            );
        });
    }

    /** @return array<string, string|int|bool> */
    private static function frame(string $file, int $line, string $function, string $module): array
    {
        $frame = [
            'file' => $file,
            'line' => $line,
            'function' => $function,
            'inApp' => !str_contains($file, '/vendor/') && $file !== '[internal]',
        ];
        if ($module !== '') {
            $frame['module'] = $module;
        }

        return $frame;
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<string, string>
     */
    private static function strings(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            if (is_string($key) && $key !== '' && strlen($key) <= 64 && (is_scalar($value) || $value === null)) {
                $out[$key] = substr(match (true) {
                    $value === null => 'null',
                    is_bool($value) => $value ? 'true' : 'false',
                    default => (string) $value,
                }, 0, 1024);
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $value */
    private static function json(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        } catch (JsonException) {
            return '{}';
        }
    }
}
