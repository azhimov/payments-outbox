<?php

declare(strict_types=1);

namespace App\Infrastructure\Psp;

/**
 * Webhook signature: X-Psp-Timestamp + X-Psp-Signature: sha256=HMAC-SHA256(secret, "{timestamp}.{body}").
 * Signing the timestamp with the body lets the receiver reject replays of old webhooks.
 */
final readonly class PspSignature
{
    public const TIMESTAMP_HEADER = 'X-Psp-Timestamp';

    public const SIGNATURE_HEADER = 'X-Psp-Signature';

    public function __construct(private string $secret) {}

    public function sign(int $timestamp, string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $this->secret);
    }

    public function verify(string $timestamp, string $body, string $signature, int $now, int $toleranceSeconds): bool
    {
        if (preg_match('/^\d{1,12}$/', $timestamp) !== 1 || abs($now - (int) $timestamp) > $toleranceSeconds) {
            return false;
        }

        return hash_equals($this->sign((int) $timestamp, $body), $signature);
    }
}
