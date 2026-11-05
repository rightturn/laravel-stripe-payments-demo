<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Payments\WebhookProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\Webhook;
use Throwable;

class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, WebhookProcessor $processor): JsonResponse
    {
        $secret = config('services.stripe.webhook_secret');
        if (! $secret) {
            Log::error('STRIPE_WEBHOOK_SECRET is not configured');

            return response()->json(['error' => 'Webhook not configured.'], 500);
        }

        try {
            // The signature covers the RAW body, so verify before anything parses or re-encodes it.
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature', ''),
                $secret,
                config('services.stripe.webhook_tolerance'),
            );
        } catch (SignatureVerificationException|UnexpectedValueException) {
            return response()->json(['error' => 'Invalid signature or payload.'], 400);
        }

        try {
            $outcome = $processor->process($event);
        } catch (Throwable $e) {
            report($e);

            // 5xx makes Stripe retry later; the transaction already rolled everything back.
            return response()->json(['error' => 'Could not process the event.'], 500);
        }

        return response()->json(['received' => true, 'outcome' => $outcome]);
    }
}
