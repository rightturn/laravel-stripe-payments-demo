<?php

namespace Tests\Support;

use App\Models\Order;
use App\Payments\CheckoutSession;
use App\Payments\PaymentGateway;
use App\Payments\PaymentGatewayException;

class FakeGateway implements PaymentGateway
{
    public int $calls = 0;
    public bool $fail = false;

    public function createCheckoutSession(Order $order): CheckoutSession
    {
        $this->calls++;
        if ($this->fail) {
            throw new PaymentGatewayException('boom');
        }

        return new CheckoutSession('cs_test_'.$order->id, 'https://checkout.stripe.test/c/pay/'.$order->id);
    }
}
