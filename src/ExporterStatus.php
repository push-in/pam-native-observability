<?php

declare(strict_types=1);

namespace Pam\Native\Observability;

/** Native exporter snapshot, for diagnostics screens. */
final readonly class ExporterStatus
{
    public function __construct(
        public bool $enabled,
        public string $host = '',
        public string $environment = '',
        public string $release = '',
        public bool $nativeCrashes = false,
        public bool $anr = false,
        public ?string $lastEventId = null,
        public string $platform = '',
        public string $error = '',
    ) {
    }

    /** @param array<array-key, mixed> $wire */
    public static function fromWire(array $wire): self
    {
        $last = self::text($wire, 'lastEventId');

        return new self(
            enabled: (bool) ($wire['enabled'] ?? false),
            host: self::text($wire, 'host'),
            environment: self::text($wire, 'environment'),
            release: self::text($wire, 'release'),
            nativeCrashes: (bool) ($wire['nativeCrashes'] ?? false),
            anr: (bool) ($wire['anr'] ?? false),
            lastEventId: $last === '' ? null : $last,
            platform: self::text($wire, 'platform'),
            error: self::text($wire, 'error'),
        );
    }

    /** @param array<array-key, mixed> $wire */
    private static function text(array $wire, string $key): string
    {
        $value = $wire[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }
}
