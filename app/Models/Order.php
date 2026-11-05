<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasUuids;

    protected $fillable = ['sku', 'amount_cents', 'currency', 'idempotency_key'];

    protected $attributes = ['status' => 'pending', 'refunded_cents' => 0];

    /**
     * Server-side state changes (status, Stripe ids, refunds). Kept separate from $fillable so a
     * request payload can never set them through mass assignment.
     */
    public function apply(array $attributes): bool
    {
        return $this->forceFill($attributes)->save();
    }

    protected function casts(): array
    {
        return ['status' => OrderStatus::class, 'paid_at' => 'datetime'];
    }
}
