<?php

declare(strict_types=1);

namespace Pam\Native\Observability;

enum Severity: int
{
    case Debug = 1;
    case Info = 2;
    case Warning = 3;
    case Error = 4;
    case Fatal = 5;

    public function otlpNumber(): int
    {
        return match ($this) {
            self::Debug => 5,
            self::Info => 9,
            self::Warning => 13,
            self::Error => 17,
            self::Fatal => 21,
        };
    }

    public function otlpText(): string
    {
        return match ($this) {
            self::Debug => 'DEBUG',
            self::Info => 'INFO',
            self::Warning => 'WARN',
            self::Error => 'ERROR',
            self::Fatal => 'FATAL',
        };
    }
}
