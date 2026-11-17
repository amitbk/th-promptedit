<?php

namespace App\Services\Pixelforge;

/**
 * Verifies the `Pixelforge-Signature` header: `t=<unix timestamp>,v1=<hex HMAC-SHA256>`, where the
 * HMAC covers `<timestamp>.<raw request body>` and is keyed with the signing secret.
 */
class PixelforgeWebhookSignature
{
    /** Maximum age, in seconds, of a signature timestamp (replay protection). */
    private const TOLERANCE_SECONDS = 300;

    public function verify(string $rawBody, ?string $header): bool
    {
        $secret = config('pixelforge.webhook_secret');

        if (blank($secret) || blank($header)) {
            return false;
        }

        $parts = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $parts[trim($key)] = trim($value);
        }

        $timestamp = $parts['t'] ?? '';

        if (! ctype_digit($timestamp) || abs(now()->timestamp - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret);

        return hash_equals($expected, $parts['v1'] ?? '');
    }
}
