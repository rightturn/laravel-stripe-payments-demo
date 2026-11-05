<?php

use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\StripeWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/orders', [OrderController::class, 'store'])->middleware('throttle:20,1');
Route::get('/orders/{order}', [OrderController::class, 'show']);

// Called by Stripe, authenticated by the signature, not by a user session or token.
Route::post('/stripe/webhook', StripeWebhookController::class);
