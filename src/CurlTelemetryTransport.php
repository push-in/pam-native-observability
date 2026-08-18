<?php

declare(strict_types=1);

namespace Pam\Native\Observability;

use CurlHandle;
use RuntimeException;

final class CurlTelemetryTransport implements TelemetryTransport
{
    private const int MAX_RESPONSE_BYTES = 65536;

    /** @param array<string, string> $headers */
    public function send(
        string $endpoint,
        string $body,
        array $headers,
        int $timeoutMillis,
    ): void {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('The curl extension is required by CurlTelemetryTransport.');
        }
        $handle = curl_init($endpoint);
        if (!$handle instanceof CurlHandle) {
            throw new RuntimeException('Could not initialize cURL.');
        }
        $response = '';
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => array_map(
                static fn (string $key, string $value): string => $key.': '.$value,
                array_keys($headers),
                $headers,
            ),
            CURLOPT_WRITEFUNCTION => static function (CurlHandle $_, string $chunk) use (&$response): int {
                if (strlen($response) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                    return 0;
                }
                $response .= $chunk;

                return strlen($chunk);
            },
            CURLOPT_CONNECTTIMEOUT_MS => $timeoutMillis,
            CURLOPT_TIMEOUT_MS => $timeoutMillis,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS
                | (EndpointPolicy::permitsHttp($endpoint) ? CURLPROTO_HTTP : 0),
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $result = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($result === false || $status < 200 || $status >= 300) {
            throw new RuntimeException(
                'Telemetry export failed: '.($error !== '' ? $error : 'HTTP '.$status),
            );
        }
        OtlpResponse::assertAccepted($response);
    }
}
