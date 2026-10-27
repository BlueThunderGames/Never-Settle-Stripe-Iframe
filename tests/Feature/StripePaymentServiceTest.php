<?php

namespace Tests\Feature;

use App\Enums\PaymentAttemptStatus;
use App\Exceptions\PaymentProviderException;
use App\Models\PaymentAttempt;
use App\Services\PaymentAttemptService;
use App\Services\StripePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\InvalidRequestException;
use Stripe\PaymentIntent;
use Stripe\Service\PaymentIntentService;
use Stripe\StripeClient;
use Tests\TestCase;

class StripePaymentServiceTest extends TestCase
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

    private function attempt(string $type = 'card', string $key = 'key-1'): PaymentAttempt
    {
        return app(PaymentAttemptService::class)->findOrCreate($key, 'INV-1042', 10098, $type);
    }

    private function intent(array $overrides = []): PaymentIntent
    {
        return PaymentIntent::constructFrom($overrides + [
            'id' => 'pi_123',
            'object' => 'payment_intent',
            'status' => 'requires_payment_method',
            'client_secret' => 'pi_123_secret_abc',
            'last_payment_error' => null,
        ]);
    }

    public function test_it_creates_a_card_intent_for_the_total_with_invoice_metadata(): void
    {
        $attempt = $this->attempt('card');

        $this->intents->shouldReceive('create')->once()
            ->withArgs(function (array $params, array $options) use ($attempt) {
                $this->assertSame(10431, $params['amount']);
                $this->assertSame('usd', $params['currency']);
                $this->assertSame(['card'], $params['allowed_payment_method_types']);
                $this->assertArrayNotHasKey('payment_method_types', $params);
                $this->assertSame('Invoice INV-1042', $params['description']);
                $this->assertSame([
                    'invoice_number' => 'INV-1042',
                    'invoice_amount' => '100.98',
                    'processing_fee' => '3.33',
                    'payment_type' => 'card',
                    'payment_attempt' => $attempt->public_id,
                ], $params['metadata']);
                $this->assertArrayNotHasKey('payment_method_options', $params);
                $this->assertSame('payment-attempt-'.$attempt->public_id, $options['idempotency_key']);

                return true;
            })
            ->andReturn($this->intent());

        $intent = app(StripePaymentService::class)->intentFor($attempt);

        $this->assertSame('pi_123_secret_abc', $intent->client_secret);

        $attempt->refresh();
        $this->assertSame('pi_123', $attempt->stripe_payment_intent_id);
        $this->assertSame(PaymentAttemptStatus::RequiresPaymentMethod, $attempt->status);
        $this->assertNotNull($attempt->last_synced_at);
    }

    public function test_it_creates_an_ach_intent_for_bank_accounts(): void
    {
        $attempt = $this->attempt('ach');

        $this->intents->shouldReceive('create')->once()
            ->withArgs(function (array $params) {
                $this->assertSame(10180, $params['amount']);
                $this->assertSame(['us_bank_account'], $params['allowed_payment_method_types']);
                $this->assertSame('automatic', $params['payment_method_options']['us_bank_account']['verification_method']);
                $this->assertSame('0.82', $params['metadata']['processing_fee']);

                return true;
            })
            ->andReturn($this->intent());

        app(StripePaymentService::class)->intentFor($attempt);
    }

    public function test_a_retry_fetches_the_existing_intent_instead_of_creating_another(): void
    {
        $this->intents->shouldReceive('create')->once()->andReturn($this->intent());
        $this->intents->shouldReceive('retrieve')->once()->with('pi_123')->andReturn($this->intent());

        $service = app(StripePaymentService::class);

        $first = $service->intentFor($this->attempt());
        $second = $service->intentFor($this->attempt());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, PaymentAttempt::count());
    }

    public function test_sync_records_a_successful_card_payment(): void
    {
        $attempt = $this->attempt();
        $attempt->update(['stripe_payment_intent_id' => 'pi_123']);

        $this->intents->shouldReceive('retrieve')->once()->andReturn($this->intent(['status' => 'succeeded']));

        app(StripePaymentService::class)->sync($attempt);

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);
        $this->assertNotNull($attempt->succeeded_at);
        $this->assertTrue($attempt->status->isFinal());
    }

    public function test_sync_leaves_ach_payments_processing_until_they_clear(): void
    {
        $attempt = $this->attempt('ach');
        $attempt->update(['stripe_payment_intent_id' => 'pi_123']);

        $this->intents->shouldReceive('retrieve')->once()->andReturn($this->intent(['status' => 'processing']));

        app(StripePaymentService::class)->sync($attempt);

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->status);
        $this->assertNull($attempt->succeeded_at);
        $this->assertFalse($attempt->status->isFinal());
    }

    public function test_sync_records_the_failure_reason_for_a_declined_payment(): void
    {
        $attempt = $this->attempt();
        $attempt->update(['stripe_payment_intent_id' => 'pi_123']);

        $this->intents->shouldReceive('retrieve')->once()->andReturn($this->intent([
            'status' => 'requires_payment_method',
            'last_payment_error' => ['code' => 'card_declined', 'message' => 'Your card was declined.'],
        ]));

        app(StripePaymentService::class)->sync($attempt);

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::RequiresPaymentMethod, $attempt->status);
        $this->assertSame('card_declined', $attempt->failure_code);
        $this->assertSame('Your card was declined.', $attempt->failure_message);
    }

    public function test_succeeded_at_is_not_overwritten_by_later_syncs(): void
    {
        $attempt = $this->attempt();
        $attempt->update(['stripe_payment_intent_id' => 'pi_123']);

        $this->intents->shouldReceive('retrieve')->twice()->andReturn($this->intent(['status' => 'succeeded']));

        $service = app(StripePaymentService::class);
        $service->sync($attempt);
        $first = $attempt->fresh()->succeeded_at;

        $this->travel(5)->minutes();
        $service->sync($attempt->fresh());

        $this->assertTrue($first->equalTo($attempt->fresh()->succeeded_at));
    }

    public function test_an_unknown_stripe_status_keeps_the_current_status(): void
    {
        $attempt = $this->attempt();
        $attempt->update(['stripe_payment_intent_id' => 'pi_123', 'status' => PaymentAttemptStatus::Processing]);

        $this->intents->shouldReceive('retrieve')->once()->andReturn($this->intent(['status' => 'some_future_status']));

        app(StripePaymentService::class)->sync($attempt);

        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->fresh()->status);
    }

    public function test_a_network_failure_is_retryable_and_reuses_the_same_idempotency_key(): void
    {
        $attempt = $this->attempt();
        $keys = [];

        $this->intents->shouldReceive('create')->twice()
            ->andReturnUsing(function (array $params, array $options) use (&$keys) {
                $keys[] = $options['idempotency_key'];

                return count($keys) === 1
                    ? throw new ApiConnectionException('Could not connect to Stripe')
                    : $this->intent();
            });

        $service = app(StripePaymentService::class);

        try {
            $service->intentFor($attempt);
            $this->fail('Expected PaymentProviderException');
        } catch (PaymentProviderException $e) {
            $this->assertTrue($e->retryable);
        }

        $this->assertNull($attempt->fresh()->failure_code, 'a network failure must not poison the attempt');
        $this->assertSame(PaymentAttemptStatus::Pending, $attempt->fresh()->status);

        $service->intentFor($attempt->fresh());

        $this->assertSame($keys[0], $keys[1]);
        $this->assertSame('pi_123', $attempt->fresh()->stripe_payment_intent_id);
    }

    public function test_a_rejected_request_is_recorded_and_the_attempt_cannot_be_retried(): void
    {
        $attempt = $this->attempt();

        $this->intents->shouldReceive('create')->once()
            ->andThrow(InvalidRequestException::factory('Invalid amount', 400, null, null, null, 'amount_too_small'));

        $service = app(StripePaymentService::class);

        try {
            $service->intentFor($attempt);
            $this->fail('Expected PaymentProviderException');
        } catch (PaymentProviderException $e) {
            $this->assertFalse($e->retryable);
        }

        $attempt->refresh();
        $this->assertSame('amount_too_small', $attempt->failure_code);
        $this->assertSame(PaymentAttemptStatus::Failed, $attempt->status);
        $this->assertSame('Invalid amount', $attempt->failure_message);
        $this->assertNull($attempt->stripe_payment_intent_id);

        // Stripe would replay the same failure for this key, so we don't call it again.
        $this->expectException(PaymentProviderException::class);
        $service->intentFor($attempt);
    }

    public function test_if_saving_fails_after_stripe_succeeds_a_retry_reconnects_to_the_same_intent(): void
    {
        $attempt = $this->attempt();
        $keys = [];

        $this->intents->shouldReceive('create')->twice()
            ->andReturnUsing(function (array $params, array $options) use (&$keys) {
                $keys[] = $options['idempotency_key'];

                return $this->intent();
            });

        PaymentAttempt::saving(fn () => throw new RuntimeException('database went away'));

        try {
            app(StripePaymentService::class)->intentFor($attempt);
            $this->fail('Expected the save failure to surface');
        } catch (RuntimeException $e) {
            $this->assertSame('database went away', $e->getMessage());
        }

        $this->assertNull(PaymentAttempt::first()->stripe_payment_intent_id);

        PaymentAttempt::flushEventListeners();

        // Same key means Stripe hands back the intent it already made; now we can save it.
        app(StripePaymentService::class)->intentFor(PaymentAttempt::first());

        $this->assertSame($keys[0], $keys[1]);
        $this->assertSame('pi_123', PaymentAttempt::first()->stripe_payment_intent_id);
        $this->assertSame(1, PaymentAttempt::count());
    }

    public function test_the_attempt_exists_before_stripe_is_ever_called(): void
    {
        $this->intents->shouldReceive('create')->once()->andReturnUsing(function (array $params) {
            $this->assertSame(1, PaymentAttempt::where('public_id', $params['metadata']['payment_attempt'])->count());

            return $this->intent();
        });

        app(StripePaymentService::class)->intentFor($this->attempt());
    }
}
