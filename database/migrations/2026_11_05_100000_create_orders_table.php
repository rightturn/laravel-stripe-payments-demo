<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('sku');
            $table->unsignedInteger('amount_cents');
            $table->char('currency', 3);
            $table->string('status', 20)->default('pending')->index();
            // Client-supplied key that makes "create order" safe to retry.
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->string('stripe_session_id')->nullable()->unique();
            $table->string('stripe_payment_intent_id')->nullable()->index();
            $table->unsignedInteger('refunded_cents')->default(0);
            $table->text('note')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        // One row per Stripe event we have handled. The unique primary key is what makes
        // webhook handling idempotent even when Stripe delivers the same event twice at once.
        Schema::create('stripe_events', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('type')->index();
            $table->string('outcome', 30);
            $table->timestamp('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_events');
        Schema::dropIfExists('orders');
    }
};
