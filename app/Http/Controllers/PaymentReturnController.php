<?php

namespace App\Http\Controllers;

use App\Models\PaymentAttempt;
use App\Services\StripePaymentService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;

class PaymentReturnController extends Controller
{
    /**
     * Where Stripe sends the customer after paying. With no webhooks, this is where we
     * ask Stripe for the outcome and record it.
     */
    public function __invoke(PaymentAttempt $attempt, StripePaymentService $stripe): View
    {
        $intent = null;

        if ($attempt->stripe_payment_intent_id && ! $attempt->status->isFinal()) {
            try {
                $intent = $stripe->sync($attempt);
            } catch (ApiErrorException $e) {
                // Show what we last knew rather than an error page.
                Log::warning('Could not refresh payment status on return', [
                    'payment_attempt' => $attempt->public_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $outcome = $attempt->returnOutcome();

        return view('payments.return', [
            'attempt' => $attempt,
            'outcome' => $outcome,
            'verification' => $outcome === 'verification' ? $this->verification($intent) : null,
        ]);
    }

    /**
     * Where and when the customer can confirm their bank account, if Stripe told us.
     *
     * @return array{url: ?string, arrival: ?Carbon}
     */
    private function verification(?PaymentIntent $intent): array
    {
        $details = $intent?->next_action?->verify_with_microdeposits;
        $url = $details?->hosted_verification_url;

        return [
            // Only ever link to an https address.
            'url' => is_string($url) && str_starts_with($url, 'https://') ? $url : null,
            'arrival' => $details?->arrival_date ? Carbon::createFromTimestamp($details->arrival_date) : null,
        ];
    }
}
