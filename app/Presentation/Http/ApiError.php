<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use Illuminate\Http\JsonResponse;

/**
 * The one error shape of the API: {"error": {"code", "message", "details"?}}.
 * The same shape laravel-idempotency renders for its own errors.
 */
final class ApiError
{
    /**
     * @param  array<array-key, mixed>|null  $details
     */
    public static function response(int $status, string $code, string $message, ?array $details = null): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];

        if ($details !== null) {
            $error['details'] = $details;
        }

        return new JsonResponse(['error' => $error], $status);
    }
}
