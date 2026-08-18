<?php

declare(strict_types=1);

namespace Pam\Native\Observability;

use RuntimeException;

final class OtlpResponse
{
    private function __construct() {}

    public static function assertAccepted(string $response): void
    {
        if ($response === '') {
            return;
        }
        $decoded = json_decode($response, true);
        if (!is_array($decoded) || !is_array($decoded['partialSuccess'] ?? null)) {
            return;
        }
        foreach (['rejectedSpans', 'rejectedLogRecords', 'rejectedDataPoints'] as $field) {
            $rejected = $decoded['partialSuccess'][$field] ?? 0;
            if ((is_int($rejected) || (is_string($rejected) && ctype_digit($rejected)))
                && (int) $rejected > 0
            ) {
                throw new RuntimeException("Collector partially rejected telemetry: {$field}={$rejected}");
            }
        }
    }
}
