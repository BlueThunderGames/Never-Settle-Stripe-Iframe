<?php

namespace App\Services;

use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentType;
use App\Exceptions\IdempotencyKeyReused;
use App\Models\PaymentAttempt;
use Illuminate\Database\UniqueConstraintViolationException;

class PaymentAttemptService
{
    public function __construct(private FeeCalculator $fees) {}

    /**
     * Create the attempt for this idempotency key, or return the one already created for it.
     *
     * Check `$attempt->wasRecentlyCreated` to tell the two apart. Amounts are always
     * priced here on the server; a retry returns the originally stored amounts.
     *
     * @throws IdempotencyKeyReused when the key was used with different invoice details
     */
    public function findOrCreate(
        string $idempotencyKey,
        string $invoiceNumber,
        int $amountCents,
        PaymentType|string $paymentType,
    ): PaymentAttempt {
        $paymentType = $paymentType instanceof PaymentType ? $paymentType : PaymentType::from($paymentType);
        $invoiceNumber = trim($invoiceNumber);
        $fingerprint = PaymentAttempt::fingerprint($invoiceNumber, $amountCents, $paymentType);

        if ($existing = $this->find($idempotencyKey, $fingerprint)) {
            return $existing;
        }

        $quote = $this->fees->quote($amountCents, $paymentType);

        try {
            return PaymentAttempt::create([
                'idempotency_key' => $idempotencyKey,
                'request_fingerprint' => $fingerprint,
                'invoice_number' => $invoiceNumber,
                'payment_type' => $paymentType,
                'amount_cents' => $quote['amount_cents'],
                'fee_cents' => $quote['fee_cents'],
                'total_cents' => $quote['total_cents'],
                'currency' => config('payments.currency'),
                'status' => PaymentAttemptStatus::Pending,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent request with the same key won the insert; use its row.
            return $this->find($idempotencyKey, $fingerprint) ?? throw new IdempotencyKeyReused;
        }
    }

    private function find(string $idempotencyKey, string $fingerprint): ?PaymentAttempt
    {
        $attempt = PaymentAttempt::where('idempotency_key', $idempotencyKey)->first();

        if ($attempt && ! hash_equals($attempt->request_fingerprint, $fingerprint)) {
            throw new IdempotencyKeyReused;
        }

        return $attempt;
    }
}
