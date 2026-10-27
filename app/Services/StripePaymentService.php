<?php

namespace App\Services;

use App\Enums\PaymentAttemptStatus;
use App\Exceptions\PaymentProviderException;
use App\Models\PaymentAttempt;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;
use Stripe\StripeClient;
use Throwable;

class StripePaymentService
{
    public const START_OVER_MESSAGE = 'We couldn\'t start your payment. Please refresh the page and try again.';

    public function __construct(private StripeClient $stripe) {}

    /**
     * Get the PaymentIntent for an attempt, creating it on first use.
     *
     * The attempt row always exists before Stripe is called, so a Stripe call is never
     * made without a record to tie it to. Creation is idempotent on both sides: the
     * Stripe idempotency key is derived from the attempt, and once we've stored the
     * intent id a retry fetches it instead of creating another. The caller should check
     * the returned intent's status, since a retried attempt may already be processing,
     * succeeded or canceled.
     *
     * Failure handling:
     * - No response from Stripe (network): nothing is recorded and the error is
     *   retryable. Retrying reuses the same idempotency key, so if Stripe did create the
     *   intent we get that same one back rather than a duplicate.
     * - Stripe rejected the request: the reason is recorded on the attempt. Stripe replays
     *   the stored result for a reused key, so this attempt is finished and the customer
     *   must start a new one.
     * - We fail to save after Stripe succeeds: the intent carries our attempt id in its
     *   metadata, and a retry returns the same intent (same key) and saves it then.
     *
     * @throws PaymentProviderException
     */
    public function intentFor(PaymentAttempt $attempt): PaymentIntent
    {
        if ($attempt->stripe_payment_intent_id) {
            return $this->sync($attempt);
        }

        if ($attempt->failure_code !== null) {
            throw new PaymentProviderException(self::START_OVER_MESSAGE, retryable: false);
        }

        try {
            $intent = $this->stripe->paymentIntents->create(
                $this->intentParams($attempt),
                ['idempotency_key' => 'payment-attempt-'.$attempt->public_id],
            );
        } catch (ApiConnectionException $e) {
            Log::warning('Stripe unreachable creating PaymentIntent', [
                'payment_attempt' => $attempt->public_id,
                'error' => $e->getMessage(),
            ]);

            throw new PaymentProviderException(
                'We couldn\'t reach the payment processor. Please try again.',
                retryable: true,
            );
        } catch (ApiErrorException $e) {
            Log::error('Stripe rejected PaymentIntent creation', [
                'payment_attempt' => $attempt->public_id,
                'stripe_request_id' => $e->getRequestId(),
                'stripe_code' => $e->getStripeCode(),
                'error' => $e->getMessage(),
            ]);

            $attempt->forceFill([
                'failure_code' => $e->getStripeCode() ?? 'api_error',
                'failure_message' => $e->getMessage(),
            ])->save();

            throw new PaymentProviderException(self::START_OVER_MESSAGE, retryable: false);
        }

        try {
            $this->store($attempt, $intent);
        } catch (Throwable $e) {
            Log::critical('PaymentIntent created but could not be saved to its attempt', [
                'payment_attempt' => $attempt->public_id,
                'payment_intent' => $intent->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        return $intent;
    }

    /**
     * Pull the latest status from Stripe into the attempt. Without webhooks this is how
     * we learn the outcome, so call it when the customer returns.
     */
    public function sync(PaymentAttempt $attempt): PaymentIntent
    {
        $intent = $this->stripe->paymentIntents->retrieve($attempt->stripe_payment_intent_id);

        $this->store($attempt, $intent);

        return $intent;
    }

    /**
     * @return array<string, mixed>
     */
    private function intentParams(PaymentAttempt $attempt): array
    {
        $params = [
            'amount' => $attempt->total_cents,
            'currency' => $attempt->currency,
            'payment_method_types' => [$attempt->payment_type === FeeCalculator::ACH ? 'us_bank_account' : 'card'],
            // Lets the client find and match the payment to an invoice by hand in Stripe.
            'description' => "Invoice {$attempt->invoice_number}",
            'metadata' => [
                'invoice_number' => $attempt->invoice_number,
                'invoice_amount' => $this->dollars($attempt->amount_cents),
                'processing_fee' => $this->dollars($attempt->fee_cents),
                'payment_type' => $attempt->payment_type,
                'payment_attempt' => $attempt->public_id,
            ],
        ];

        if ($attempt->payment_type === FeeCalculator::ACH) {
            $params['payment_method_options'] = [
                'us_bank_account' => [
                    'verification_method' => 'automatic',
                    'financial_connections' => ['permissions' => ['payment_method']],
                ],
            ];
        }

        return $params;
    }

    private function store(PaymentAttempt $attempt, PaymentIntent $intent): void
    {
        $status = PaymentAttemptStatus::tryFrom($intent->status) ?? $attempt->status;
        $error = $intent->last_payment_error;

        $attempt->forceFill([
            'stripe_payment_intent_id' => $intent->id,
            'status' => $status,
            // Only needed while the customer can still pay.
            'client_secret' => $status->isFinal() ? null : ($intent->client_secret ?? $attempt->client_secret),
            'failure_code' => $error?->code ?? $error?->decline_code,
            'failure_message' => $error?->message,
            'last_synced_at' => now(),
            'succeeded_at' => $status === PaymentAttemptStatus::Succeeded
                ? ($attempt->succeeded_at ?? now())
                : null,
        ])->save();
    }

    private function dollars(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
