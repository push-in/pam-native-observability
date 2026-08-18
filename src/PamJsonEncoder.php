<?php

declare(strict_types=1);

namespace Pam\Native\Observability;

use JsonException;

final class PamJsonEncoder implements TelemetryEncoder
{
    /**
     * @param list<array{kind: int, timestampUnixNano: string, data: array<string, mixed>}> $signals
     * @param array<string, scalar> $context
     * @throws JsonException
     */
    public function encode(
        ObservabilityConfig $config,
        array $signals,
        array $context,
        int $dropped,
    ): EncodedTelemetry {
        $body = json_encode([
            'schemaVersion' => 1,
            'resource' => [
                'service.name' => $config->serviceName,
                'service.version' => $config->serviceVersion,
            ],
            'context' => $context,
            'dropped' => $dropped,
            'signals' => $signals,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return new EncodedTelemetry(
            endpoint: $config->endpoint,
            body: $body,
            headers: [
                'content-type' => 'application/json',
                'user-agent' => 'pam-native-observability/0.2',
                ...$config->headers,
            ],
        );
    }
}
