<?php

namespace App\Http\Controllers;

use App\Enums\PaymentAttemptStatus;
use App\Exceptions\IdempotencyKeyReused;
use App\Http\Requests\CreatePaymentAttemptRequest;
use App\Http\Requests\PaymentQuoteRequest;
use App\Jobs\CreateStripePaymentIntent;
use App\Models\PaymentAttempt;
use App\Services\FeeCalculator;
use App\Services\PaymentAttemptService;
use App\Services\StripePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Stripe\Exception\ApiErrorException;

class PaymentController extends Controller
{
    public function show(): View
    {
        return view('payments.form');
    }

    /**
     * Price an invoice amount for the chosen payment type, fee included.
     */
    public function quote(PaymentQuoteRequest $request, FeeCalculator $fees): JsonResponse
    {
        return response()->json(
            $fees->quote($request->amountCents(), $request->paymentType())
        );
    }

    /**
     * Save the attempt and queue the Stripe work. Returns before Stripe is called;
     * the form polls status() until the PaymentIntent is ready.
     */
    public function store(CreatePaymentAttemptRequest $request, PaymentAttemptService $attempts): JsonResponse
    {
        try {
            $attempt = $attempts->findOrCreate(
                $request->idempotencyKey(),
                $request->invoiceNumber(),
                $request->amountCents(),
                $request->paymentType(),
            );
        } catch (IdempotencyKeyReused $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        // Also covers a retry whose first job was lost; a duplicate run is harmless.
        if (! $attempt->stripe_payment_intent_id && ! $attempt->failure_code) {
            CreateStripePaymentIntent::dispatch($attempt->id);
        }

        return response()->json($this->payload($attempt), 202);
    }

    /**
     * What the form polls: waiting, ready (with the client secret), failed, or done.
     */
    public function status(PaymentAttempt $attempt): JsonResponse
    {
        return response()->json($this->payload($attempt, withSecret: true))
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Where Stripe sends the customer after paying. With no webhooks, this is where we
     * ask Stripe for the outcome and record it.
     */
    public function complete(PaymentAttempt $attempt, StripePaymentService $stripe): View
    {
        if ($attempt->stripe_payment_intent_id && ! $attempt->status->isFinal()) {
            try {
                $stripe->sync($attempt);
            } catch (ApiErrorException $e) {
                // Show what we last knew rather than an error page.
                Log::warning('Could not refresh payment status on return', [
                    'payment_attempt' => $attempt->public_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return view('payments.return', [
            'attempt' => $attempt,
            'outcome' => $this->outcome($attempt),
        ]);
    }

    /**
     * success: money received. processing: submitted but not yet cleared (typical for ACH).
     * failed: nothing was charged, or the payment was not completed.
     */
    private function outcome(PaymentAttempt $attempt): string
    {
        return match ($attempt->status) {
            PaymentAttemptStatus::Succeeded => 'success',
            PaymentAttemptStatus::Processing => 'processing',
            default => 'failed',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(PaymentAttempt $attempt, bool $withSecret = false): array
    {
        $payload = [
            'id' => $attempt->public_id,
            'state' => $this->state($attempt),
            'amount_cents' => $attempt->amount_cents,
            'fee_cents' => $attempt->fee_cents,
            'total_cents' => $attempt->total_cents,
        ];

        if ($payload['state'] === 'ready' && $withSecret) {
            $payload['client_secret'] = $attempt->client_secret;
        }

        if ($payload['state'] === 'failed') {
            // Stripe's own message stays in the logs and database, not in the browser.
            $payload['message'] = StripePaymentService::START_OVER_MESSAGE;
        }

        return $payload;
    }

    private function state(PaymentAttempt $attempt): string
    {
        if (! $attempt->stripe_payment_intent_id) {
            return $attempt->failure_code ? 'failed' : 'pending';
        }

        $awaitingCustomer = [
            PaymentAttemptStatus::RequiresPaymentMethod,
            PaymentAttemptStatus::RequiresConfirmation,
            PaymentAttemptStatus::RequiresAction,
        ];

        return in_array($attempt->status, $awaitingCustomer, true) && $attempt->client_secret
            ? 'ready'
            : 'done';
    }
}
