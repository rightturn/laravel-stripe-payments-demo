<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Payments\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeGateway;
use Tests\TestCase;

class OrderApiTest extends TestCase
{
    use RefreshDatabase;

    private FakeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = new FakeGateway();
        $this->app->instance(PaymentGateway::class, $this->gateway);
    }

    public function test_creating_an_order_returns_a_checkout_url_and_uses_the_server_side_price(): void
    {
        $response = $this->postJson('/api/orders', ['sku' => 'pro', 'amount_cents' => 1]) // a client-sent price is ignored
            ->assertCreated()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('amount_cents', 4900)
            ->assertJsonPath('currency', 'usd');

        $this->assertStringStartsWith('https://checkout.stripe.test/', $response->json('checkout_url'));
        $this->assertSame('cs_test_'.$response->json('id'), Order::first()->stripe_session_id);
    }

    public function test_unknown_or_missing_sku_is_rejected(): void
    {
        $this->postJson('/api/orders', ['sku' => 'free-money'])->assertUnprocessable()->assertJsonValidationErrors('sku');
        $this->postJson('/api/orders', [])->assertUnprocessable();
        $this->assertSame(0, Order::count());
    }

    public function test_retrying_with_the_same_idempotency_key_returns_the_same_order(): void
    {
        $headers = ['Idempotency-Key' => 'client-key-1'];

        $first = $this->postJson('/api/orders', ['sku' => 'pro'], $headers)->assertCreated();
        $second = $this->postJson('/api/orders', ['sku' => 'pro'], $headers)->assertOk();

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame(1, Order::count());
        $this->assertSame(1, $this->gateway->calls, 'Stripe must be called only once');
    }

    public function test_reusing_an_idempotency_key_for_a_different_sku_is_refused(): void
    {
        $headers = ['Idempotency-Key' => 'client-key-2'];
        $this->postJson('/api/orders', ['sku' => 'pro'], $headers)->assertCreated();

        $this->postJson('/api/orders', ['sku' => 'team'], $headers)->assertUnprocessable();
        $this->assertSame(1, Order::count());
    }

    public function test_a_gateway_failure_marks_the_order_failed_and_returns_502(): void
    {
        $this->gateway->fail = true;

        $response = $this->postJson('/api/orders', ['sku' => 'starter'])->assertStatus(502);

        $this->assertSame(OrderStatus::Failed, Order::findOrFail($response->json('order_id'))->status);
    }

    public function test_an_order_can_be_read_back(): void
    {
        $id = $this->postJson('/api/orders', ['sku' => 'team'])->json('id');

        $this->getJson("/api/orders/{$id}")->assertOk()->assertJsonPath('sku', 'team')->assertJsonPath('status', 'pending');
        $this->getJson('/api/orders/'.\Illuminate\Support\Str::uuid())->assertNotFound();
    }
}
