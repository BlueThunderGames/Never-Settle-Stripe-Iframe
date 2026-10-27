<?php

namespace App\Http\Controllers;

use App\Enums\PaymentAttemptStatus;
use App\Exceptions\IdempotencyKeyReused;
use App\Http\Requests\CreatePaymentAttemptRequest;
use App\Jobs\CreateStripePaymentIntent;
use App\Models\PaymentAttempt;
use App\Services\PaymentAttemptService;
use App\Services\StripePaymentService;
use Illuminate\Http\JsonResponse;

class PaymentAttemptController extends Controller
{
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
        if (! $attempt->stripe_payment_intent_id && $attempt->status !== PaymentAttemptStatus::Failed) {
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
     * @return array<string, mixed>
     */
    private function payload(PaymentAttempt $attempt, bool $withSecret = false): array
    {
        $state = $attempt->pollState();

        $payload = [
            'id' => $attempt->public_id,
            'state' => $state,
            'amount_cents' => $attempt->amount_cents,
            'fee_cents' => $attempt->fee_cents,
            'total_cents' => $attempt->total_cents,
        ];

        if ($state === 'ready' && $withSecret) {
            $payload['client_secret'] = $attempt->client_secret;
        }

        if ($state === 'failed') {
            // Stripe's own message stays in the logs and database, not in the browser.
            $payload['message'] = StripePaymentService::START_OVER_MESSAGE;
        }

        return $payload;
    }
}
