<?php

namespace App\Jobs;

use App\Enums\PaymentAttemptStatus;
use App\Exceptions\PaymentProviderException;
use App\Models\PaymentAttempt;
use App\Services\StripePaymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Creates the Stripe PaymentIntent for a saved attempt.
 *
 * Safe to run more than once: the service uses an idempotency key derived from the
 * attempt, and a second run just fetches the intent that already exists.
 */
class CreateStripePaymentIntent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public function __construct(public readonly int $paymentAttemptId) {}

    /**
     * Seconds to wait between tries, growing each time.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [2, 5, 15, 45];
    }

    /**
     * One run per attempt at a time; a duplicate that starts meanwhile is dropped.
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->paymentAttemptId))->expireAfter(120)->dontRelease()];
    }

    public function handle(StripePaymentService $stripe): void
    {
        $attempt = PaymentAttempt::findOrFail($this->paymentAttemptId);

        Log::withContext([
            'payment_attempt' => $attempt->public_id,
            'job_id' => $this->job?->getJobId(),
            'try' => $this->attempts(),
        ]);

        Log::info('Creating PaymentIntent');

        try {
            $intent = $stripe->intentFor($attempt);
        } catch (PaymentProviderException $e) {
            if ($e->retryable) {
                Log::warning('PaymentIntent creation failed, will retry', ['error' => $e->getMessage()]);

                throw $e;
            }

            // Stripe rejected it; retrying can't help.
            $this->fail($e);

            return;
        }

        Log::info('PaymentIntent ready', ['payment_intent' => $intent->id, 'status' => $intent->status]);
    }

    /**
     * Called once the job has permanently failed. Make sure the attempt says so, so the
     * form stops waiting.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('PaymentIntent creation failed permanently', [
            'payment_attempt_id' => $this->paymentAttemptId,
            'error' => $exception->getMessage(),
        ]);

        $attempt = PaymentAttempt::find($this->paymentAttemptId);

        if (! $attempt || $attempt->stripe_payment_intent_id) {
            return;
        }

        $attempt->forceFill([
            'status' => PaymentAttemptStatus::Failed,
            'failure_code' => $attempt->failure_code ?? 'provider_unavailable',
            'failure_message' => $attempt->failure_message ?? $exception->getMessage(),
        ])->save();
    }
}
