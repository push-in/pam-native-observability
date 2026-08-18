<?php

declare(strict_types=1);

namespace Pam\Native\Observability;

interface TelemetryTransport
{
    /** @param array<string, string> $headers */
    public function send(
        string $endpoint,
        string $body,
        array $headers,
        int $timeoutMillis,
    ): void;
}
