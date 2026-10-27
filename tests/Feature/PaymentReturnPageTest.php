<?php

namespace Tests\Feature;

use App\Enums\PaymentAttemptStatus;
use App\Models\PaymentAttempt;
use App\Services\PaymentAttemptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use Stripe\Exception\ApiConnectionException;
use Stripe\PaymentIntent;
use Stripe\Service\PaymentIntentService;
use Stripe\StripeClient;
use Tests\TestCase;

class PaymentReturnPageTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $intents;

    protected function setUp(): void
    {
        parent::setUp();

        $this->intents = Mockery::mock(PaymentIntentService::class);

        $client = Mockery::mock(StripeClient::class);
        $client->shouldReceive('getService')->with('paymentIntents')->andReturn($this->intents);

        $this->app->instance(StripeClient::class, $client);
    }

    private function attempt(string $type = 'card', array $attributes = []): PaymentAttempt
    {
        $attempt = app(PaymentAttemptService::class)->findOrCreate((string) Str::uuid(), 'INV-1042', 10098, $type);
        $attempt->update($attributes + ['stripe_payment_intent_id' => 'pi_123', 'status' => PaymentAttemptStatus::RequiresPaymentMethod]);

        return $attempt;
    }

    private function stripeReturns(string $status, ?array $error = null, ?array $nextAction = null): void
    {
        $this->intents->shouldReceive('retrieve')->once()->with('pi_123')->andReturn(PaymentIntent::constructFrom([
            'id' => 'pi_123',
            'object' => 'payment_intent',
            'status' => $status,
            'client_secret' => 'pi_123_secret',
            'last_payment_error' => $error,
            'next_action' => $nextAction,
        ]));
    }

    private function microdeposits(string $url = 'https://payments.stripe.com/microdeposit/pacs_test_123'): array
    {
        return [
            'type' => 'verify_with_microdeposits',
            'verify_with_microdeposits' => [
                'arrival_date' => strtotime('2026-10-28 12:00:00 UTC'),
                'hosted_verification_url' => $url,
                'microdeposit_type' => 'descriptor_code',
            ],
        ];
    }

    public function test_an_ach_account_awaiting_microdeposits_asks_the_customer_to_verify_it(): void
    {
        $attempt = $this->attempt('ach');
        $this->stripeReturns('requires_action', null, $this->microdeposits());

        $this->get("/payments/return/{$attempt->public_id}")
            ->assertOk()
            ->assertSee('Verify your bank account')
            ->assertSee('October 28, 2026')
            ->assertSee('Your payment is not complete until then.')
            ->assertSee('href="https://payments.stripe.com/microdeposit/pacs_test_123"', false)
            ->assertDontSee('Payment unsuccessful')
            ->assertDontSee('Try again');

        $this->assertSame(PaymentAttemptStatus::RequiresAction, $attempt->fresh()->status);
    }

    public function test_the_verification_link_is_only_shown_for_https_urls(): void
    {
        $attempt = $this->attempt('ach');
        $this->stripeReturns('requires_action', null, $this->microdeposits('javascript:alert(1)'));

        $this->get("/payments/return/{$attempt->public_id}")
            ->assertSee('Verify your bank account')
            ->assertDontSee('javascript:alert(1)', false)
            ->assertDontSee('Verify bank account');
    }

    public function test_verification_still_shows_without_details_when_stripe_is_unreachable(): void
    {
        $attempt = $this->attempt('ach', ['status' => PaymentAttemptStatus::RequiresAction]);
        $this->intents->shouldReceive('retrieve')->once()->andThrow(new ApiConnectionException('timeout'));

        $this->get("/payments/return/{$attempt->public_id}")
            ->assertSee('Verify your bank account')
            ->assertSee('within 1–2 business days')
            ->assertDontSee('Verify bank account');
    }

    public function test_a_card_payment_stuck_awaiting_action_is_not_treated_as_bank_verification(): void
    {
        $attempt = $this->attempt('card');
        $this->stripeReturns('requires_action');

        $this->get("/payments/return/{$attempt->public_id}")
            ->assertSee('Payment unsuccessful')
            ->assertDontSee('Verify your bank account');
    }

    public function test_a_successful_card_payment_shows_a_success_message(): void
    {
        $attempt = $this->attempt('card');
        $this->stripeReturns('succeeded');

        $this->get("/payments/return/{$attempt->public_id}")
            ->assertOk()
            ->assertSee('Payment successful')
            ->assertSee('$104.31')
            ->assertSee('INV-1042')
            ->assertSee('Make another payment')
            ->assertDontSee('Try again');

        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->fresh()->status);
    }

    public function test_a_submitted_ach_payment_says_it_may_take_a_few_days(): void
    {
        $attempt = $this->attempt('ach');
        $this->stripeReturns('processing');

        $this->get("/payments/return/{$attempt->public_id}")
            ->assertOk()
            ->assertSee('Bank payment submitted')
            ->assertSee('$101.80')
            ->assertSee('a few business days')
            ->assertDontSee('Payment successful');

        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->fresh()->status);
    }

    public function test_an_ach_payment_that_has_already_cleared_shows_received(): void
    {
        $attempt = $this->attempt('ach');
        $this->stripeReturns('succeeded');

        $this->get("/payments/return/{$attempt->public_id}")
            ->assertSee('Bank payment received')
            ->assertDontSee('a few business days');
    }

    public function test_a_declined_payment_shows_the_error_and_a_way_to_retry(): void
    {
        $attempt = $this->attempt('card');
        $this->stripeReturns('requires_payment_method', ['code' => 'card_declined', 'message' => 'Your card was declined.']);

        $this->get("/payments/return/{$attempt->public_id}")
            ->assertOk()
            ->assertSee('Payment unsuccessful')
            ->assertSee('Your card was declined.')
            ->assertSee('Try again')
            ->assertDontSee('Payment successful');
    }

    public function test_a_payment_the_customer_never_completed_shows_a_generic_error(): void
    {
        $attempt = $this->attempt('card');
        $this->stripeReturns('requires_payment_method');

        $this->get("/payments/return/{$attempt->public_id}")
            ->assertSee('Payment unsuccessful')
            ->assertSee("We couldn't complete your payment.");
    }

    public function test_internal_creation_errors_are_never_shown(): void
    {
        $attempt = app(PaymentAttemptService::class)->findOrCreate((string) Str::uuid(), 'INV-1', 5000, 'card');
        $attempt->update(['status' => PaymentAttemptStatus::Failed, 'failure_code' => 'api_error', 'failure_message' => 'Secret internal detail']);

        $this->get("/payments/return/{$attempt->public_id}")
            ->assertSee('Payment unsuccessful')
            ->assertDontSee('Secret internal detail');
    }

    public function test_a_final_attempt_does_not_call_stripe_again(): void
    {
        $attempt = $this->attempt('card', ['status' => PaymentAttemptStatus::Succeeded]);
        $this->intents->shouldNotReceive('retrieve');

        $this->get("/payments/return/{$attempt->public_id}")->assertSee('Payment successful');
    }

    public function test_it_falls_back_to_the_last_known_status_when_stripe_is_unreachable(): void
    {
        $attempt = $this->attempt('ach', ['status' => PaymentAttemptStatus::Processing]);
        $this->intents->shouldReceive('retrieve')->once()->andThrow(new ApiConnectionException('timeout'));

        $this->get("/payments/return/{$attempt->public_id}")
            ->assertOk()
            ->assertSee('Bank payment submitted');
    }

    public function test_the_invoice_number_is_escaped(): void
    {
        $attempt = $this->attempt('card', ['status' => PaymentAttemptStatus::Succeeded, 'invoice_number' => '<script>alert(1)</script>']);

        $this->get("/payments/return/{$attempt->public_id}")
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;', false);
    }

    public function test_an_unknown_attempt_is_a_404(): void
    {
        $this->get('/payments/return/'.Str::ulid())->assertNotFound();
    }
}
