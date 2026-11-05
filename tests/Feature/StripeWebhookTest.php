<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\StripeEvent;
use App\Payments\WebhookProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Stripe\Event;
use Tests\Support\SignsStripeWebhooks;
use Tests\TestCase;

class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;
    use SignsStripeWebhooks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWebhooks();
    }

    private function pendingOrder(array $attrs = []): Order
    {
        return Order::create($attrs + ['sku' => 'pro', 'amount_cents' => 4900, 'currency' => 'usd']);
    }

    // --- signature verification ----------------------------------------------------------
    public function test_rejects_a_missing_signature(): void
    {
        $this->call('POST', '/api/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{}')
            ->assertStatus(400);
    }

    public function test_rejects_a_signature_made_with_the_wrong_secret(): void
    {
        $order = $this->pendingOrder();
        $event = $this->event('checkout.session.completed', $this->sessionObject($order->id));
        $payload = json_encode($event);

        $this->postWebhook($event, $this->signature($payload, null, 'whsec_attacker'))->assertStatus(400);
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    public function test_rejects_a_tampered_payload(): void
    {
        $order = $this->pendingOrder();
        $event = $this->event('checkout.session.completed', $this->sessionObject($order->id));
        $signature = $this->signature(json_encode($event));
        $event['data']['object']['amount_total'] = 1; // changed after signing

        $this->postWebhook($event, $signature)->assertStatus(400);
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    public function test_rejects_an_old_timestamp_to_prevent_replays(): void
    {
        $order = $this->pendingOrder();
        $event = $this->event('checkout.session.completed', $this->sessionObject($order->id));
        $payload = json_encode($event);

        $this->postWebhook($event, $this->signature($payload, time() - 3600))->assertStatus(400);
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    public function test_fails_closed_when_no_webhook_secret_is_configured(): void
    {
        config(['services.stripe.webhook_secret' => null]);
        $this->postWebhook($this->event('ping', []))->assertStatus(500);
    }

    // --- payment ---------------------------------------------------------------------------
    public function test_a_paid_checkout_session_marks_the_order_paid(): void
    {
        $order = $this->pendingOrder();

        $this->postWebhook($this->event('checkout.session.completed', $this->sessionObject($order->id)))
            ->assertOk()->assertJson(['received' => true, 'outcome' => 'applied']);

        $order->refresh();
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertSame('pi_'.$order->id, $order->stripe_payment_intent_id);
        $this->assertNotNull($order->paid_at);
    }

    public function test_the_same_event_delivered_twice_is_applied_once(): void
    {
        $order = $this->pendingOrder();
        $event = $this->event('checkout.session.completed', $this->sessionObject($order->id), 'evt_dup');

        $this->postWebhook($event)->assertOk()->assertJson(['outcome' => 'applied']);
        $paidAt = $order->fresh()->paid_at;

        $this->travel(5)->minutes();
        $this->postWebhook($event)->assertOk()->assertJson(['outcome' => 'duplicate']);

        $this->assertSame(1, StripeEvent::count());
        $this->assertEquals($paidAt, $order->fresh()->paid_at); // untouched by the duplicate
    }

    public function test_a_different_event_for_an_already_paid_order_changes_nothing(): void
    {
        $order = $this->pendingOrder();
        $this->postWebhook($this->event('checkout.session.completed', $this->sessionObject($order->id), 'evt_a'));
        $paidAt = $order->fresh()->paid_at;

        $this->travel(5)->minutes();
        $this->postWebhook($this->event('checkout.session.async_payment_succeeded', $this->sessionObject($order->id), 'evt_b'))
            ->assertJson(['outcome' => 'ignored']);

        $this->assertEquals($paidAt, $order->fresh()->paid_at);
    }

    public function test_an_amount_mismatch_is_flagged_instead_of_marked_paid(): void
    {
        $order = $this->pendingOrder();
        $object = $this->sessionObject($order->id, ['amount_total' => 100]);

        $this->postWebhook($this->event('checkout.session.completed', $object))->assertJson(['outcome' => 'needs_review']);

        $order->refresh();
        $this->assertSame(OrderStatus::NeedsReview, $order->status);
        $this->assertNull($order->paid_at);
        $this->assertStringContainsString('expected 4900', $order->note);
    }

    public function test_a_currency_mismatch_is_flagged(): void
    {
        $order = $this->pendingOrder();
        $object = $this->sessionObject($order->id, ['currency' => 'eur']);

        $this->postWebhook($this->event('checkout.session.completed', $object))->assertJson(['outcome' => 'needs_review']);
        $this->assertSame(OrderStatus::NeedsReview, $order->fresh()->status);
    }

    public function test_an_unpaid_completed_session_waits_for_the_async_success_event(): void
    {
        $order = $this->pendingOrder();

        $this->postWebhook($this->event('checkout.session.completed', $this->sessionObject($order->id, ['payment_status' => 'unpaid']), 'evt_1'))
            ->assertJson(['outcome' => 'ignored']);
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);

        $this->postWebhook($this->event('checkout.session.async_payment_succeeded', $this->sessionObject($order->id), 'evt_2'))
            ->assertJson(['outcome' => 'applied']);
        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
    }

    // --- expiry and refunds ----------------------------------------------------------------
    public function test_an_expired_session_expires_a_pending_order(): void
    {
        $order = $this->pendingOrder();

        $this->postWebhook($this->event('checkout.session.expired', $this->sessionObject($order->id, ['payment_status' => 'unpaid'])))
            ->assertJson(['outcome' => 'applied']);
        $this->assertSame(OrderStatus::Expired, $order->fresh()->status);
    }

    public function test_an_expiry_arriving_after_payment_is_ignored(): void
    {
        $order = $this->pendingOrder();
        $this->postWebhook($this->event('checkout.session.completed', $this->sessionObject($order->id), 'evt_1'));

        $this->postWebhook($this->event('checkout.session.expired', $this->sessionObject($order->id), 'evt_2'))
            ->assertJson(['outcome' => 'ignored']);
        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
    }

    public function test_a_full_refund_marks_the_order_refunded(): void
    {
        $order = $this->pendingOrder();
        $this->postWebhook($this->event('checkout.session.completed', $this->sessionObject($order->id), 'evt_1'));

        $charge = ['id' => 'ch_1', 'object' => 'charge', 'payment_intent' => 'pi_'.$order->id, 'amount' => 4900, 'amount_refunded' => 4900];
        $this->postWebhook($this->event('charge.refunded', $charge, 'evt_2'))->assertJson(['outcome' => 'applied']);

        $order->refresh();
        $this->assertSame(OrderStatus::Refunded, $order->status);
        $this->assertSame(4900, $order->refunded_cents);
    }

    public function test_a_partial_refund_records_the_amount_but_keeps_the_order_paid(): void
    {
        $order = $this->pendingOrder();
        $this->postWebhook($this->event('checkout.session.completed', $this->sessionObject($order->id), 'evt_1'));

        $charge = ['id' => 'ch_1', 'object' => 'charge', 'payment_intent' => 'pi_'.$order->id, 'amount' => 4900, 'amount_refunded' => 1000];
        $this->postWebhook($this->event('charge.refunded', $charge, 'evt_2'));

        $order->refresh();
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertSame(1000, $order->refunded_cents);
    }

    // --- robustness ------------------------------------------------------------------------
    public function test_unknown_event_types_are_acknowledged_and_recorded(): void
    {
        $this->postWebhook($this->event('customer.created', ['id' => 'cus_1']))->assertOk()->assertJson(['outcome' => 'ignored']);
        $this->assertSame(1, StripeEvent::count());
    }

    public function test_events_for_unknown_orders_are_ignored_not_errors(): void
    {
        $this->postWebhook($this->event('checkout.session.completed', $this->sessionObject('00000000-0000-0000-0000-000000000000')))
            ->assertOk()->assertJson(['outcome' => 'ignored']);
    }

    public function test_a_failure_rolls_back_so_stripe_can_retry_and_succeed(): void
    {
        $order = $this->pendingOrder();
        $event = $this->event('checkout.session.completed', $this->sessionObject($order->id), 'evt_retry');

        // First delivery: the processor blows up after the event row was written.
        $this->app->bind(WebhookProcessor::class, fn () => new class extends WebhookProcessor {
            public function process(Event $event): string
            {
                \Illuminate\Support\Facades\DB::transaction(function () use ($event) {
                    \App\Models\StripeEvent::create(['id' => $event->id, 'type' => $event->type, 'outcome' => 'processing', 'processed_at' => now()]);
                    throw new RuntimeException('database went away');
                });
            }
        });
        $this->postWebhook($event)->assertStatus(500);
        $this->assertSame(0, StripeEvent::count(), 'the event row must be rolled back');
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);

        // Stripe retries: the real processor now succeeds.
        $this->app->bind(WebhookProcessor::class, fn () => new WebhookProcessor());
        $this->postWebhook($event)->assertOk()->assertJson(['outcome' => 'applied']);
        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
    }
}
