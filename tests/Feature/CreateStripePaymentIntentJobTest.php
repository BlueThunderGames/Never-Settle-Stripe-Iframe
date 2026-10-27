<?php

namespace Tests\Feature;

use App\Enums\PaymentAttemptStatus;
use App\Exceptions\PaymentProviderException;
use App\Jobs\CreateStripePaymentIntent;
use App\Models\PaymentAttempt;
use App\Services\PaymentAttemptService;
use App\Services\StripePaymentService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\MockInterface;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\InvalidRequestException;
use Stripe\PaymentIntent;
use Stripe\Service\PaymentIntentService;
use Stripe\StripeClient;
use Tests\TestCase;

class CreateStripePaymentIntentJobTest extends TestCase
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

    private function attempt(): PaymentAttempt
    {
        return app(PaymentAttemptService::class)->findOrCreate('key-1', 'INV-1042', 10098, 'card');
    }

    private function intent(string $status = 'requires_payment_method'): PaymentIntent
    {
        return PaymentIntent::constructFrom([
            'id' => 'pi_123',
            'object' => 'payment_intent',
            'status' => $status,
            'client_secret' => 'pi_123_secret_abc',
            'last_payment_error' => null,
        ]);
    }

    private function job(PaymentAttempt $attempt): CreateStripePaymentIntent
    {
        return (new CreateStripePaymentIntent($attempt->id))->withFakeQueueInteractions();
    }

    public function test_it_creates_the_intent_and_stores_the_client_secret_encrypted(): void
    {
        $attempt = $this->attempt();
        $this->intents->shouldReceive('create')->once()->andReturn($this->intent());

        $job = $this->job($attempt);
        $job->handle(app(StripePaymentService::class));

        $job->assertNotFailed();

        $attempt->refresh();
        $this->assertSame('pi_123', $attempt->stripe_payment_intent_id);
        $this->assertSame('pi_123_secret_abc', $attempt->client_secret);

        $raw = DB::table('payment_attempts')->where('id', $attempt->id)->value('client_secret');
        $this->assertNotSame('pi_123_secret_abc', $raw);
        $this->assertStringNotContainsString('pi_123_secret_abc', $raw);
    }

    public function test_the_client_secret_is_never_serialized(): void
    {
        $attempt = $this->attempt();
        $attempt->update(['client_secret' => 'pi_123_secret_abc']);

        $this->assertArrayNotHasKey('client_secret', $attempt->fresh()->toArray());
    }

    public function test_the_client_secret_is_cleared_once_the_payment_is_final(): void
    {
        $attempt = $this->attempt();
        $attempt->update(['stripe_payment_intent_id' => 'pi_123', 'client_secret' => 'pi_123_secret_abc']);

        $this->intents->shouldReceive('retrieve')->once()->andReturn($this->intent('succeeded'));

        $this->job($attempt)->handle(app(StripePaymentService::class));

        $this->assertNull($attempt->fresh()->client_secret);
    }

    public function test_a_network_failure_is_rethrown_so_the_queue_retries_with_backoff(): void
    {
        $attempt = $this->attempt();
        $this->intents->shouldReceive('create')->once()->andThrow(new ApiConnectionException('timeout'));

        $job = $this->job($attempt);

        try {
            $job->handle(app(StripePaymentService::class));
            $this->fail('Expected the retryable exception to be rethrown');
        } catch (PaymentProviderException $e) {
            $this->assertTrue($e->retryable);
        }

        $job->assertNotFailed();
        $this->assertNull($attempt->fresh()->failure_code);
    }

    public function test_a_stripe_rejection_fails_the_job_without_retrying(): void
    {
        $attempt = $this->attempt();
        $this->intents->shouldReceive('create')->once()
            ->andThrow(InvalidRequestException::factory('Invalid amount', 400, null, null, null, 'amount_too_small'));

        $job = $this->job($attempt);
        $job->handle(app(StripePaymentService::class));

        $job->assertFailed();
        $this->assertSame('amount_too_small', $attempt->fresh()->failure_code);
        $this->assertSame(PaymentAttemptStatus::Failed, $attempt->fresh()->status);
    }

    public function test_once_retries_are_exhausted_the_attempt_is_marked_failed(): void
    {
        $attempt = $this->attempt();

        (new CreateStripePaymentIntent($attempt->id))->failed(new Exception('Stripe is down'));

        $attempt->refresh();
        $this->assertSame('provider_unavailable', $attempt->failure_code);
        $this->assertSame(PaymentAttemptStatus::Failed, $attempt->status);
        $this->assertSame('Stripe is down', $attempt->failure_message);
    }

    public function test_failure_handling_does_not_overwrite_an_existing_reason_or_a_created_intent(): void
    {
        $rejected = $this->attempt();
        $rejected->update(['failure_code' => 'amount_too_small', 'failure_message' => 'Invalid amount']);
        (new CreateStripePaymentIntent($rejected->id))->failed(new Exception('later'));
        $this->assertSame('amount_too_small', $rejected->fresh()->failure_code);

        $created = app(PaymentAttemptService::class)->findOrCreate('key-2', 'INV-2', 5000, 'card');
        $created->update(['stripe_payment_intent_id' => 'pi_999']);
        (new CreateStripePaymentIntent($created->id))->failed(new Exception('later'));
        $this->assertNull($created->fresh()->failure_code);
    }

    public function test_it_retries_five_times_with_growing_delays_and_guards_against_overlap(): void
    {
        $job = new CreateStripePaymentIntent(1);

        $this->assertSame(5, $job->tries);
        $this->assertSame([2, 5, 15, 45], $job->backoff());
        $this->assertInstanceOf(WithoutOverlapping::class, $job->middleware()[0]);
    }
}
