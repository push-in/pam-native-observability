<?php

declare(strict_types=1);

namespace Pam\Native\Observability;

final readonly class EncodedTelemetry
{
    /** @param array<string, string> $headers */
    public function __construct(
        public string $endpoint,
        public string $body,
        public array $headers,
    ) {}
}
