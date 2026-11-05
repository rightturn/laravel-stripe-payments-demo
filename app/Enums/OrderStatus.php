<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Expired = 'expired';
    case Refunded = 'refunded';
    case Failed = 'failed';
    /** Stripe says the payment succeeded but it does not match the order (e.g. amount): a human must look. */
    case NeedsReview = 'needs_review';
}
