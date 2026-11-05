<?php

namespace App\Payments;

use App\Models\Order;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

class StripeGateway implements PaymentGateway
{
    public function __construct(private readonly ?string $secret)
    {
    }

    public function createCheckoutSession(Order $order): CheckoutSession
    {
        if (! $this->secret) {
            throw new PaymentGatewayException('STRIPE_SECRET is not configured.');
        }

        $product = config("shop.products.{$order->sku}");

        try {
            $session = (new StripeClient($this->secret))->checkout->sessions->create([
                'mode' => 'payment',
                'client_reference_id' => $order->id,
                // The webhook finds the order again through this metadata.
                'metadata' => ['order_id' => $order->id],
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => $order->currency,
                        'unit_amount' => $order->amount_cents,
                        'product_data' => ['name' => $product['name']],
                    ],
                ]],
                'success_url' => config('services.stripe.success_url'),
                'cancel_url' => config('services.stripe.cancel_url'),
            ], [
                // If our request is retried, Stripe returns the same session instead of creating another.
                'idempotency_key' => 'checkout-'.$order->id,
            ]);
        } catch (ApiErrorException $e) {
            throw new PaymentGatewayException('Stripe error: '.$e->getMessage(), 0, $e);
        }

        return new CheckoutSession($session->id, (string) $session->url);
    }
}
