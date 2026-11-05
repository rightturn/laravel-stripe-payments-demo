<?php

namespace App\Payments;

final class CheckoutSession
{
    public function __construct(public readonly string $id, public readonly string $url)
    {
    }
}
