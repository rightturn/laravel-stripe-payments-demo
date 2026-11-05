<?php

namespace App\Payments;

use App\Models\Order;

/**
 * Offline stand-in for Stripe so the demo runs without an account (PAYMENTS_DRIVER=fake).
 * It only invents a session id and URL; pair it with scripts/simulate-webhook.sh to play Stripe's part.
 */
class LocalGateway implements PaymentGateway
{
    public function createCheckoutSession(Order $order): CheckoutSession
    {
        return new CheckoutSession('cs_local_'.$order->id, rtrim(config('app.url'), '/').'/local-checkout/'.$order->id);
    }
}
