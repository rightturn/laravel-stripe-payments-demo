# Laravel Stripe Payments Demo

A compact, production-minded example of taking payments with **Stripe Checkout** in **Laravel 13**:
server-side prices, **signature-verified webhooks**, **idempotent** event handling, amount checks and refunds.

![CI](https://github.com/rightturn/laravel-stripe-payments-demo/actions/workflows/ci.yml/badge.svg)

The payment code that most tutorials skip is the part that loses money in production, so that is what this repo is about:

| Problem | How it is handled |
|---|---|
| A customer edits the price in the browser | Prices live on the server (`config/shop.php`); the API accepts only a SKU |
| Someone forges a webhook | Every webhook is verified with Stripe's signature (`Webhook::constructEvent`) against the **raw body**; wrong secret, tampered body and missing header all return 400 |
| A captured webhook is replayed later | The signed timestamp must be within a tolerance (default 5 min) |
| Stripe delivers the same event twice (or twice **at once**) | `stripe_events` has the event id as primary key, written in the **same transaction** as the order change; duplicates are skipped |
| Handling crashes half-way | The transaction rolls back, the endpoint answers 500 and Stripe retries; nothing is half-applied |
| The client retries "create order" after a timeout | `Idempotency-Key` header: the same key returns the same order and calls Stripe once (and Stripe's own idempotency key is set on the session) |
| "Paid" but for the wrong amount or currency | Order goes to `needs_review` instead of `paid` |
| Delayed payment methods | `completed` with `unpaid` is ignored until `async_payment_succeeded` |
| Events arrive out of order | An expiry after payment is ignored; refunds update the order only if it is paid |
| Client tries to set `status` through the API | Server-side fields are not mass-assignable (`Order::apply()` is the only writer) |

## Flow
```
POST /api/orders {"sku":"pro"}  ->  order (pending) + Stripe Checkout Session  ->  { checkout_url }
customer pays on Stripe's hosted page
Stripe -> POST /api/stripe/webhook  (signed)  ->  verify -> dedupe -> update order -> 200
GET /api/orders/{id}   ->  status: pending | paid | expired | refunded | failed | needs_review
```

## API
| Method | Path | Description |
|---|---|---|
| POST | `/api/orders` | `{"sku": "starter\|pro\|team"}` creates an order and returns `checkout_url`. Optional `Idempotency-Key` header. |
| GET | `/api/orders/{id}` | Order status, amounts and `refunded_cents` |
| POST | `/api/stripe/webhook` | Called by Stripe. Handles `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.expired`, `charge.refunded`; other events are acknowledged and recorded |

## Run it offline (no Stripe account)
```bash
export APP_KEY=base64:$(openssl rand -base64 32)
docker compose up --build          # app + MySQL, PAYMENTS_DRIVER=fake
```
```bash
# create an order
curl -s localhost:8000/api/orders -H 'Content-Type: application/json' -H 'Accept: application/json' \
     -H 'Idempotency-Key: demo-1' -d '{"sku":"pro"}'

# play Stripe: send a correctly signed webhook (secret defaults to whsec_demo)
scripts/simulate-webhook.sh <order-id>

# send the same event again: the response says "duplicate" and nothing changes
scripts/simulate-webhook.sh <order-id> evt_same_id
scripts/simulate-webhook.sh <order-id> evt_same_id
```
Set `APP_PORT` to use another port. A concurrency check I ran against MySQL: **12 simultaneous deliveries of the
same signed event produced 1 `applied` and 11 `duplicate`**, one row in `stripe_events`, and the order was paid once.

## Run it against real Stripe (test mode)
1. Create a Stripe account and copy the **test** secret key (`sk_test_...`).
2. Install the [Stripe CLI](https://docs.stripe.com/stripe-cli) and run
   `stripe listen --forward-to localhost:8000/api/stripe/webhook`; it prints a webhook secret (`whsec_...`).
3. Start with `PAYMENTS_DRIVER=stripe STRIPE_SECRET=sk_test_... STRIPE_WEBHOOK_SECRET=whsec_... docker compose up --build`.
4. `POST /api/orders`, open `checkout_url`, pay with test card `4242 4242 4242 4242`.

## Tests
```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan test         # 24 tests, in-memory SQLite, no network
```
The webhook tests build real events and sign them with HMAC-SHA256 exactly like Stripe, then run them through Stripe's own
verification code. They cover signature failures, replay protection, duplicates, amount and currency mismatches,
async payments, expiry, full and partial refunds, unknown events and rollback-then-retry.

**What is not covered:** the Stripe API call that creates the Checkout Session (`StripeGateway`) is tested through the
`PaymentGateway` interface with a fake, not against live Stripe, because that needs your own keys.
Follow the steps above to try it in test mode.

## Layout
```
app/Payments/WebhookProcessor.php          dedupe + order state changes in one transaction
app/Payments/StripeGateway.php             creates Checkout Sessions (Stripe SDK)
app/Payments/PaymentGateway.php            interface; LocalGateway = offline demo driver
app/Http/Controllers/Api/                  OrderController, StripeWebhookController
config/shop.php                            server-side product catalogue
tests/Feature/                             webhook and order API tests
scripts/simulate-webhook.sh                sends a signed webhook for the offline demo
```

## Limits (this is a demo)
No user accounts or authorization on orders (order ids are unguessable UUIDs), no e-mails or fulfilment step,
refunds are observed through webhooks (not initiated by this API), and a refund event that arrives before the payment
event is ignored. Money is stored as integer cents.

## License
MIT
