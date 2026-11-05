<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Payments\PaymentGateway;
use App\Payments\PaymentGatewayException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function store(Request $request, PaymentGateway $gateway): JsonResponse
    {
        $data = $request->validate([
            'sku' => ['required', 'string', Rule::in(array_keys(config('shop.products')))],
        ]);
        $key = $request->header('Idempotency-Key');
        if ($key !== null && strlen($key) > 100) {
            return response()->json(['message' => 'Idempotency-Key is too long.'], 422);
        }

        // Retrying the same request returns the same order instead of charging twice.
        if ($key && ($existing = Order::where('idempotency_key', $key)->first())) {
            if ($existing->sku !== $data['sku']) {
                return response()->json(['message' => 'Idempotency-Key was already used with a different request.'], 422);
            }

            return response()->json($this->payload($existing), 200);
        }

        $order = Order::create([
            'sku' => $data['sku'],
            'amount_cents' => config("shop.products.{$data['sku']}.amount"),
            'currency' => config('shop.currency'),
            'idempotency_key' => $key,
        ]);

        try {
            $session = $gateway->createCheckoutSession($order);
        } catch (PaymentGatewayException $e) {
            $order->apply(['status' => OrderStatus::Failed, 'note' => $e->getMessage()]);

            return response()->json(['message' => 'Payment provider unavailable.', 'order_id' => $order->id], 502);
        }

        $order->apply(['stripe_session_id' => $session->id]);

        return response()->json($this->payload($order) + ['checkout_url' => $session->url], 201);
    }

    public function show(Order $order): JsonResponse
    {
        return response()->json($this->payload($order));
    }

    private function payload(Order $order): array
    {
        return [
            'id' => $order->id,
            'sku' => $order->sku,
            'amount_cents' => $order->amount_cents,
            'currency' => $order->currency,
            'status' => $order->status->value,
            'refunded_cents' => $order->refunded_cents,
            'paid_at' => $order->paid_at?->toIso8601String(),
        ];
    }
}
