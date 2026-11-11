<?php

declare(strict_types=1);

namespace App\Presentation\Http\Middleware;

use App\Application\Ports\MerchantDirectory;
use App\Presentation\Http\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * X-Api-Key -> merchant. The merchant id is stored on the request for controllers
 * and for the idempotency scope (one merchant's keys never collide with another's).
 */
final readonly class AuthenticateMerchant
{
    public const ATTRIBUTE = 'merchant_id';

    public function __construct(private MerchantDirectory $merchants) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('X-Api-Key');
        $merchantId = is_string($key) && $key !== '' ? $this->merchants->findIdByApiKey($key) : null;

        if ($merchantId === null) {
            return ApiError::response(401, 'unauthenticated', 'A valid X-Api-Key header is required.');
        }

        $request->attributes->set(self::ATTRIBUTE, $merchantId);

        return $next($request);
    }

    public static function merchantId(Request $request): string
    {
        $id = $request->attributes->get(self::ATTRIBUTE);

        if (! is_string($id)) {
            throw new \LogicException('AuthenticateMerchant did not run for this route.');
        }

        return $id;
    }
}
