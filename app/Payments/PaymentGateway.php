<?php

namespace App\Payments;

use App\Models\Order;

interface PaymentGateway
{
    /** @throws PaymentGatewayException when the provider cannot create the session */
    public function createCheckoutSession(Order $order): CheckoutSession;
}
