<?php

declare(strict_types=1);

namespace App\Infrastructure\Idempotency;

use App\Presentation\Http\Middleware\AuthenticateMerchant;
use Azhimov\Idempotency\Contracts\ScopeResolver;
use Illuminate\Http\Request;

/**
 * Idempotency keys belong to a merchant: two merchants may use the same key.
 */
final class MerchantScopeResolver implements ScopeResolver
{
    public function resolve(Request $request): string
    {
        return 'merchant:'.AuthenticateMerchant::merchantId($request);
    }
}
