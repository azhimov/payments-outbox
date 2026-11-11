<?php

declare(strict_types=1);

namespace Tests\Doubles;

use App\Application\Ports\ChargeOutcome;
use App\Application\Ports\ChargeRequest;
use App\Application\Ports\ChargeResult;
use App\Application\Ports\PaymentGateway;
use App\Application\Ports\RefundRequest;
use Closure;

/**
 * A PSP for feature tests: answers with scripted results and records every call.
 */
final class ScriptedGateway implements PaymentGateway
{
    /** @var list<ChargeRequest> */
    public array $charges = [];

    /** @var list<string> */
    public array $statusQueries = [];

    public ?Closure $during = null;

    /** @var list<RefundRequest> */
    public array $refunds = [];

    public ChargeResult $refundResult;

    public function __construct(
        public ChargeResult $chargeResult = new ChargeResult(ChargeOutcome::Succeeded, 'psp_test'),
        public ChargeResult $statusResult = new ChargeResult(ChargeOutcome::NotFound),
    ) {
        $this->refundResult = new ChargeResult(ChargeOutcome::Succeeded, 'psp_refund');
    }

    public function charge(ChargeRequest $request): ChargeResult
    {
        $this->charges[] = $request;

        if ($this->during !== null) {
            ($this->during)($request);
        }

        return $this->chargeResult;
    }

    public function refund(RefundRequest $request): ChargeResult
    {
        $this->refunds[] = $request;

        return $this->refundResult;
    }

    public function status(string $paymentId): ChargeResult
    {
        $this->statusQueries[] = $paymentId;

        return $this->statusResult;
    }
}
