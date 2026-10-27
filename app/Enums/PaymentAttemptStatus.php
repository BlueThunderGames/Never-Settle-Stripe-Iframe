<?php

namespace App\Enums;

/**
 * Mirrors Stripe's PaymentIntent statuses, plus a local "pending" for the
 * window between inserting our row and Stripe returning an intent.
 */
enum PaymentAttemptStatus: string
{
    case Pending = 'pending';
    case RequiresPaymentMethod = 'requires_payment_method';
    case RequiresConfirmation = 'requires_confirmation';
    case RequiresAction = 'requires_action';
    case RequiresCapture = 'requires_capture';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Canceled = 'canceled';

    /**
     * Final states never change again, so we can stop re-checking Stripe.
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::Succeeded, self::Canceled], true);
    }
}
