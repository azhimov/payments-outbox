<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\Application\Payments\Exceptions\OrderAlreadyPaid;
use App\Application\Payments\Exceptions\OrderNotFound;
use App\Application\Payments\Exceptions\PaymentAlreadyInProgress;
use App\Application\Refunds\Exceptions\PaymentNotFound;
use App\Application\Refunds\Exceptions\PspUnavailable;
use App\Domain\Order\Exceptions\InvalidOrder;
use App\Domain\Payment\Exceptions\InvalidPaymentTransition;
use App\Domain\Payment\Exceptions\RefundNotAllowed;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Maps exceptions to the API error format for /api routes.
 */
final class ExceptionRenderer
{
    public static function register(Exceptions $exceptions): void
    {
        $api = fn (Request $request): bool => $request->is('api/*');

        $exceptions->render(fn (ValidationException $e, Request $request) => $api($request)
            ? ApiError::response(422, 'validation_failed', 'The request is invalid.', $e->errors())
            : null);

        $exceptions->render(fn (OrderNotFound $e, Request $request) => $api($request)
            ? ApiError::response(404, 'not_found', 'Order not found.')
            : null);

        $exceptions->render(fn (OrderAlreadyPaid $e, Request $request) => $api($request)
            ? ApiError::response(409, 'order_already_paid', 'The order is already paid.')
            : null);

        $exceptions->render(fn (PaymentAlreadyInProgress $e, Request $request) => $api($request)
            ? ApiError::response(409, 'payment_in_progress', 'The order already has a payment that is processing or succeeded.')
            : null);

        $exceptions->render(fn (PaymentNotFound $e, Request $request) => $api($request)
            ? ApiError::response(404, 'not_found', 'Payment not found.')
            : null);

        // 5xx: not stored by laravel-idempotency, so a retry with the same key runs again.
        $exceptions->render(fn (PspUnavailable $e, Request $request) => $api($request)
            ? ApiError::response(503, 'psp_unavailable', $e->getMessage())->header('Retry-After', '2')
            : null);

        $exceptions->render(fn (InvalidOrder $e, Request $request) => $api($request)
            ? ApiError::response(422, 'invalid_order', $e->getMessage())
            : null);

        $exceptions->render(fn (RefundNotAllowed $e, Request $request) => $api($request)
            ? ApiError::response(422, 'refund_not_allowed', $e->getMessage())
            : null);

        $exceptions->render(fn (InvalidPaymentTransition $e, Request $request) => $api($request)
            ? ApiError::response(409, 'invalid_payment_state', $e->getMessage())
            : null);

        $exceptions->render(fn (NotFoundHttpException $e, Request $request) => $api($request)
            ? ApiError::response(404, 'not_found', $e->getMessage() !== '' ? $e->getMessage() : 'Not found.')
            : null);

        $exceptions->render(fn (HttpExceptionInterface $e, Request $request) => $api($request)
            ? ApiError::response($e->getStatusCode(), 'http_'.$e->getStatusCode(), $e->getMessage() !== '' ? $e->getMessage() : 'Request failed.')
            : null);
    }
}
