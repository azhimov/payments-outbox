<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers;

use App\Application\Orders\CreateOrder;
use App\Application\Orders\GetOrder;
use App\Presentation\Http\Middleware\AuthenticateMerchant;
use App\Presentation\Http\Requests\CreateOrderRequest;
use App\Presentation\Http\Resources\OrderResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class OrderController
{
    public function store(CreateOrderRequest $request, CreateOrder $createOrder): JsonResponse
    {
        return (new OrderResource($createOrder->handle($request->toCommand())))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, string $order, GetOrder $getOrder): OrderResource
    {
        $found = $getOrder->handle($order, AuthenticateMerchant::merchantId($request));

        if ($found === null) {
            throw new NotFoundHttpException('Order not found.');
        }

        return new OrderResource($found);
    }
}
