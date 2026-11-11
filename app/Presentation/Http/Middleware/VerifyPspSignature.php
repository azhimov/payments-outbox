<?php

declare(strict_types=1);

namespace App\Presentation\Http\Middleware;

use App\Infrastructure\Psp\PspSignature;
use App\Presentation\Http\ApiError;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only the PSP can call the webhook: HMAC over timestamp and raw body, within a time window.
 */
final readonly class VerifyPspSignature
{
    public function __construct(private PspSignature $signature) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $timestamp = $request->header(PspSignature::TIMESTAMP_HEADER);
        $signature = $request->header(PspSignature::SIGNATURE_HEADER);

        $valid = is_string($timestamp) && is_string($signature) && $this->signature->verify(
            $timestamp,
            $request->getContent(),
            $signature,
            Date::now()->getTimestamp(),
            config()->integer('services.psp.webhook_tolerance'),
        );

        return $valid
            ? $next($request)
            : ApiError::response(401, 'invalid_signature', 'The webhook signature is missing, wrong or too old.');
    }
}
