<?php

namespace App\Payments;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\StripeEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Event;

/**
 * Applies verified Stripe events to orders, exactly once per event id.
 *
 * The "have we seen this event" row and the order change are written in ONE transaction:
 *  - a duplicate delivery hits the primary key and is skipped;
 *  - if handling fails, the transaction rolls back (including the event row), the controller
 *    answers 500 and Stripe retries, so no event is ever half-applied or lost.
 */
class WebhookProcessor
{
    public const DUPLICATE = 'duplicate';

    /** @return string outcome: applied | ignored | needs_review | duplicate */
    public function process(Event $event): string
    {
        try {
            return DB::transaction(function () use ($event) {
                // Fails with a unique violation if another delivery already recorded this event.
                StripeEvent::create(['id' => $event->id, 'type' => $event->type, 'outcome' => 'processing', 'processed_at' => now()]);

                $outcome = $this->handle($event);

                StripeEvent::whereKey($event->id)->update(['outcome' => $outcome]);

                return $outcome;
            });
        } catch (UniqueConstraintViolationException) {
            return self::DUPLICATE;
        }
    }

    private function handle(Event $event): string
    {
        $object = $event->data->object;

        return match ($event->type) {
            'checkout.session.completed', 'checkout.session.async_payment_succeeded' => $this->sessionPaid($object),
            'checkout.session.expired' => $this->sessionExpired($object),
            'charge.refunded' => $this->chargeRefunded($object),
            default => 'ignored',
        };
    }

    private function sessionPaid($session): string
    {
        // Delayed payment methods send "completed" while still unpaid; wait for async_payment_succeeded.
        if (($session->payment_status ?? null) !== 'paid') {
            return 'ignored';
        }

        $order = $this->orderForSession($session);
        if (! $order) {
            return 'ignored';
        }
        if ($order->status === OrderStatus::Paid || $order->status === OrderStatus::Refunded) {
            return 'ignored'; // already settled: a late duplicate-looking event must not change anything
        }

        // Never trust that "paid" means "paid the right amount".
        if ((int) $session->amount_total !== $order->amount_cents || strtolower((string) $session->currency) !== strtolower($order->currency)) {
            $order->apply([
                'status' => OrderStatus::NeedsReview,
                'stripe_session_id' => $session->id,
                'stripe_payment_intent_id' => $session->payment_intent ?? null,
                'note' => sprintf('Paid %s %s, expected %s %s.', $session->amount_total, $session->currency, $order->amount_cents, $order->currency),
            ]);
            Log::warning('Stripe amount mismatch', ['order' => $order->id, 'session' => $session->id]);

            return 'needs_review';
        }

        $order->apply([
            'status' => OrderStatus::Paid,
            'paid_at' => now(),
            'stripe_session_id' => $session->id,
            'stripe_payment_intent_id' => $session->payment_intent ?? null,
        ]);

        return 'applied';
    }

    private function sessionExpired($session): string
    {
        $order = $this->orderForSession($session);
        // Only an unpaid order can expire; an expiry that arrives after payment is ignored.
        if (! $order || $order->status !== OrderStatus::Pending) {
            return 'ignored';
        }
        $order->apply(['status' => OrderStatus::Expired]);

        return 'applied';
    }

    private function chargeRefunded($charge): string
    {
        $paymentIntent = $charge->payment_intent ?? null;
        $order = $paymentIntent
            ? Order::where('stripe_payment_intent_id', $paymentIntent)->lockForUpdate()->first()
            : null;
        if (! $order) {
            Log::info('Refund for an unknown payment intent', ['payment_intent' => $paymentIntent]);

            return 'ignored';
        }

        $refunded = (int) $charge->amount_refunded;
        $order->refunded_cents = $refunded;
        // A partial refund keeps the order "paid"; only a full refund changes its status.
        if ($refunded >= $order->amount_cents) {
            $order->status = OrderStatus::Refunded;
        }
        $order->save();

        return 'applied';
    }

    private function orderForSession($session): ?Order
    {
        $id = $session->metadata->order_id ?? $session->client_reference_id ?? null;

        return $id ? Order::whereKey($id)->lockForUpdate()->first() : null;
    }
}
