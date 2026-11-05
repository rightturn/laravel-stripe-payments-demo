<?php

/*
 | The product catalogue lives on the server: the client only sends a SKU,
 | so a customer can never choose their own price.
 | Amounts are integers in the smallest currency unit (cents).
 */
return [
    'currency' => 'usd',
    'products' => [
        'starter' => ['name' => 'Starter plan', 'amount' => 1900],
        'pro' => ['name' => 'Pro plan', 'amount' => 4900],
        'team' => ['name' => 'Team plan', 'amount' => 14900],
    ],
];
