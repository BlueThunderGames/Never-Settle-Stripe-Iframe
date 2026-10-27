<?php

namespace App\Models;

use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class PaymentAttempt extends Model
{
    use HasUlids;

    protected $guarded = ['id', 'public_id'];

    // Never serialize the secret by accident.
    protected $hidden = ['client_secret'];

    protected function casts(): array
    {
        return [
            'status' => PaymentAttemptStatus::class,
            'payment_type' => PaymentType::class,
            'client_secret' => 'encrypted',
            'amount_cents' => 'integer',
            'fee_cents' => 'integer',
            'total_cents' => 'integer',
            'last_synced_at' => 'datetime',
            'succeeded_at' => 'datetime',
        ];
    }

    // Only public_id is a ULID; the auto-increment id stays the primary key.
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * What the payment form polls for: pending (waiting on the worker), ready (customer can
     * pay), failed (the PaymentIntent couldn't be created) or done (past the customer).
     */
    public function pollState(): string
    {
        if (! $this->stripe_payment_intent_id) {
            return $this->status === PaymentAttemptStatus::Failed ? 'failed' : 'pending';
        }

        $awaitingCustomer = [
            PaymentAttemptStatus::RequiresPaymentMethod,
            PaymentAttemptStatus::RequiresConfirmation,
            PaymentAttemptStatus::RequiresAction,
        ];

        return in_array($this->status, $awaitingCustomer, true) && $this->client_secret ? 'ready' : 'done';
    }

    /**
     * What the return page tells the customer:
     * success: money received. processing: submitted but not yet cleared (typical for ACH).
     * verification: a bank account entered by hand must be confirmed with micro-deposits first.
     * failed: nothing was charged, or the payment was not completed.
     */
    public function returnOutcome(): string
    {
        return match (true) {
            $this->status === PaymentAttemptStatus::Succeeded => 'success',
            $this->status === PaymentAttemptStatus::Processing => 'processing',
            $this->status === PaymentAttemptStatus::RequiresAction
                && $this->payment_type === PaymentType::Ach => 'verification',
            default => 'failed',
        };
    }

    /**
     * Fingerprint of the user-supplied inputs that define this attempt.
     */
    public static function fingerprint(string $invoiceNumber, int $amountCents, PaymentType $paymentType): string
    {
        return hash('sha256', implode('|', [$invoiceNumber, $amountCents, $paymentType->value]));
    }
}
