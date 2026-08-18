<?php

declare(strict_types=1);

namespace Pam\Native\Observability;

interface TelemetryEncoder
{
    /**
     * @param list<array{kind: int, timestampUnixNano: string, data: array<string, mixed>}> $signals
     * @param array<string, scalar> $context
     */
    public function encode(
        ObservabilityConfig $config,
        array $signals,
        array $context,
        int $dropped,
    ): EncodedTelemetry;
}
