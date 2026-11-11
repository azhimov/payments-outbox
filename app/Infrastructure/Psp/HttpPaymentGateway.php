<?php

declare(strict_types=1);

namespace App\Infrastructure\Psp;

use App\Application\Ports\ChargeOutcome;
use App\Application\Ports\ChargeRequest;
use App\Application\Ports\ChargeResult;
use App\Application\Ports\PaymentGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Talks to the PSP over HTTP. Every answer that does not say clearly what happened
 * (timeout, 5xx, garbage) becomes Unknown, so the caller never guesses "failed".
 */
final readonly class HttpPaymentGateway implements PaymentGateway
{
    public function __construct(
        private Http $http,
        private string $baseUrl,
        private float $timeout,
        private float $connectTimeout,
    ) {}

    public function charge(ChargeRequest $request): ChargeResult
    {
        return $this->call(fn (PendingRequest $http): Response => $http
            ->withHeaders(['Idempotency-Key' => $request->paymentId])
            ->post('/psp/payments', array_filter([
                'amount' => $request->amount->amount,
                'currency' => $request->amount->currency->code,
                'card_token' => $request->cardToken,
                'webhook_url' => $request->webhookUrl,
            ], fn (mixed $v): bool => $v !== null)));
    }

    public function status(string $paymentId): ChargeResult
    {
        return $this->call(fn (PendingRequest $http): Response => $http->get('/psp/payments/'.rawurlencode($paymentId)));
    }

    /**
     * @param  callable(PendingRequest): Response  $send
     */
    private function call(callable $send): ChargeResult
    {
        try {
            $response = $send($this->http->baseUrl($this->baseUrl)->acceptJson()->timeout($this->timeout)->connectTimeout($this->connectTimeout));
        } catch (ConnectionException $e) {
            return new ChargeResult(ChargeOutcome::Unknown, reason: 'psp_unreachable: '.$e->getMessage());
        }

        if ($response->status() === 404) {
            return new ChargeResult(ChargeOutcome::NotFound);
        }

        if (! $response->successful()) {
            return new ChargeResult(ChargeOutcome::Unknown, reason: "psp_http_{$response->status()}");
        }

        $status = $response->json('status');
        $id = $response->json('id');
        $reason = $response->json('decline_reason');

        return match ($status) {
            'succeeded' => new ChargeResult(ChargeOutcome::Succeeded, is_string($id) ? $id : null),
            'pending' => new ChargeResult(ChargeOutcome::Pending, is_string($id) ? $id : null),
            'declined' => new ChargeResult(ChargeOutcome::Declined, is_string($id) ? $id : null, is_string($reason) ? $reason : 'declined'),
            default => new ChargeResult(ChargeOutcome::Unknown, reason: 'psp_unexpected_answer'),
        };
    }
}
