<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Application\Ports\ChargeRequest;
use App\Application\Ports\ChargeResult;
use App\Application\Ports\PaymentGateway;
use App\Application\Ports\RefundRequest;
use Closure;

/** Counts PSP calls by outcome and times them. */
final readonly class MeteredPaymentGateway implements PaymentGateway
{
    public function __construct(
        private PaymentGateway $inner,
        private Metrics $metrics,
    ) {}

    public function charge(ChargeRequest $request): ChargeResult
    {
        return $this->measure('charge', fn (): ChargeResult => $this->inner->charge($request));
    }

    public function status(string $paymentId): ChargeResult
    {
        return $this->measure('status', fn (): ChargeResult => $this->inner->status($paymentId));
    }

    public function refund(RefundRequest $request): ChargeResult
    {
        return $this->measure('refund', fn (): ChargeResult => $this->inner->refund($request));
    }

    /**
     * @param  Closure(): ChargeResult  $call
     */
    private function measure(string $operation, Closure $call): ChargeResult
    {
        $started = microtime(true);
        $result = $call();
        $this->metrics->psp($operation, $result->outcome->value, microtime(true) - $started);

        return $result;
    }
}
