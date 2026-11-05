<?php

namespace Tests\Support;

use Illuminate\Testing\TestResponse;

/** Builds Stripe events and signs them exactly the way Stripe does: HMAC-SHA256 over "{timestamp}.{payload}". */
trait SignsStripeWebhooks
{
    protected string $webhookSecret = 'whsec_test_secret';

    protected function setUpWebhooks(): void
    {
        config(['services.stripe.webhook_secret' => $this->webhookSecret]);
    }

    protected function signature(string $payload, ?int $timestamp = null, ?string $secret = null): string
    {
        $timestamp ??= time();

        return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$payload}", $secret ?? $this->webhookSecret);
    }

    protected function event(string $type, array $object, string $id = 'evt_1'): array
    {
        return [
            'id' => $id,
            'object' => 'event',
            'api_version' => '2025-01-27.acacia',
            'created' => time(),
            'type' => $type,
            'livemode' => false,
            'data' => ['object' => $object],
        ];
    }

    protected function sessionObject(string $orderId, array $overrides = []): array
    {
        return $overrides + [
            'id' => 'cs_test_'.$orderId,
            'object' => 'checkout.session',
            'payment_status' => 'paid',
            'amount_total' => 4900,
            'currency' => 'usd',
            'payment_intent' => 'pi_'.$orderId,
            'client_reference_id' => $orderId,
            'metadata' => ['order_id' => $orderId],
        ];
    }

    protected function postWebhook(array $event, ?string $signature = null, ?string $rawBody = null): TestResponse
    {
        $payload = $rawBody ?? json_encode($event);

        return $this->call('POST', '/api/stripe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $signature ?? $this->signature($payload),
        ], $payload);
    }
}
