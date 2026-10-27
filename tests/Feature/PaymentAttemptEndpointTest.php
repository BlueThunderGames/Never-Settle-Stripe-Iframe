<?php

namespace Tests\Feature;

use App\Enums\PaymentAttemptStatus;
use App\Jobs\CreateStripePaymentIntent;
use App\Models\PaymentAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PaymentAttemptEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return $overrides + ['invoice_number' => 'INV-1042', 'amount' => '100.98', 'payment_type' => 'card'];
    }

    private function create(array $payload = [], ?string $key = 'default'): TestResponse
    {
        $headers = $key === null ? [] : ['Idempotency-Key' => $key === 'default' ? (string) Str::uuid() : $key];

        return $this->postJson('/payments/attempts', $this->payload($payload), $headers);
    }

    public function test_it_saves_the_attempt_queues_the_job_and_returns_immediately(): void
    {
        Queue::fake();

        $response = $this->create()->assertStatus(202)
            ->assertJsonPath('state', 'pending')
            ->assertJsonPath('amount_cents', 10098)
            ->assertJsonPath('fee_cents', 333)
            ->assertJsonPath('total_cents', 10431)
            ->assertJsonMissingPath('client_secret');

        $attempt = PaymentAttempt::firstOrFail();
        $this->assertSame($attempt->public_id, $response->json('id'));
        $this->assertSame('INV-1042', $attempt->invoice_number);
        $this->assertSame(PaymentAttemptStatus::Pending, $attempt->status);

        Queue::assertPushed(CreateStripePaymentIntent::class, fn ($job) => $job->paymentAttemptId === $attempt->id);
    }

    public function test_the_server_prices_the_attempt_itself(): void
    {
        Queue::fake();

        // Client-supplied totals are ignored.
        $this->create(['total_cents' => 1, 'fee_cents' => 0, 'amount_cents' => 1])
            ->assertJsonPath('total_cents', 10431);

        $this->assertSame(10431, PaymentAttempt::first()->total_cents);
    }

    public function test_the_same_idempotency_key_returns_the_same_attempt(): void
    {
        Queue::fake();
        $key = (string) Str::uuid();

        $first = $this->create(key: $key)->json('id');
        $second = $this->create(key: $key)->assertStatus(202)->json('id');

        $this->assertSame($first, $second);
        $this->assertSame(1, PaymentAttempt::count());
    }

    public function test_reusing_a_key_with_different_details_is_a_conflict(): void
    {
        Queue::fake();
        $key = (string) Str::uuid();

        $this->create(key: $key)->assertStatus(202);
        $this->create(['amount' => '200.00'], $key)->assertStatus(409);
        $this->create(['invoice_number' => 'INV-9'], $key)->assertStatus(409);
        $this->create(['payment_type' => 'ach'], $key)->assertStatus(409);

        $this->assertSame(1, PaymentAttempt::count());
    }

    public function test_it_does_not_queue_work_when_the_intent_exists_or_the_attempt_failed(): void
    {
        Queue::fake();

        $created = (string) Str::uuid();
        $this->create(key: $created);
        PaymentAttempt::firstOrFail()->update(['stripe_payment_intent_id' => 'pi_1']);
        Queue::fake();
        $this->create(key: $created)->assertStatus(202);
        Queue::assertNothingPushed();

        $failed = (string) Str::uuid();
        $this->create(['invoice_number' => 'INV-2'], $failed);
        PaymentAttempt::where('invoice_number', 'INV-2')->firstOrFail()->update(['failure_code' => 'api_error', 'status' => PaymentAttemptStatus::Failed]);
        Queue::fake();
        $this->create(['invoice_number' => 'INV-2'], $failed)->assertStatus(202);
        Queue::assertNothingPushed();
    }

    #[DataProvider('invalidPayloads')]
    public function test_it_validates_the_request(array $overrides, string $field, string $message): void
    {
        Queue::fake();

        $this->create($overrides)->assertUnprocessable()->assertJsonValidationErrors([$field => $message]);

        $this->assertSame(0, PaymentAttempt::count());
        Queue::assertNothingPushed();
    }

    public static function invalidPayloads(): array
    {
        return [
            'missing invoice number' => [['invoice_number' => ''], 'invoice_number', 'Enter your invoice number.'],
            'invoice number too long' => [['invoice_number' => str_repeat('A', 65)], 'invoice_number', 'The invoice number can be at most 64 characters.'],
            'control characters in invoice number' => [['invoice_number' => "INV\x00-1"], 'invoice_number', 'The invoice number contains invalid characters.'],
            'invalid amount' => [['amount' => 'abc'], 'amount', 'Enter a valid dollar amount, e.g. 125.50'],
            'amount below the minimum' => [['amount' => '0.49'], 'amount', 'The minimum payment is $0.50.'],
            'total over the limit once the fee is added' => [['amount' => '999999.99'], 'amount', 'That amount is too large once the processing fee is added. Please enter a smaller amount.'],
            'unknown payment type' => [['payment_type' => 'wire'], 'payment_type', 'Choose a valid payment method.'],
        ];
    }

    public function test_the_idempotency_key_header_is_required_and_must_be_a_uuid(): void
    {
        Queue::fake();

        $this->create(key: null)->assertUnprocessable()
            ->assertJsonValidationErrors(['idempotency_key' => 'Missing Idempotency-Key header.']);

        $this->create(key: 'not-a-uuid')->assertUnprocessable()
            ->assertJsonValidationErrors(['idempotency_key' => 'The Idempotency-Key header must be a UUID.']);

        $this->assertSame(0, PaymentAttempt::count());
    }

    private function attempt(array $attributes = []): PaymentAttempt
    {
        Queue::fake();
        $this->create(key: (string) Str::uuid());

        return tap(PaymentAttempt::firstOrFail())->update($attributes);
    }

    public function test_status_is_pending_until_the_intent_exists(): void
    {
        $attempt = $this->attempt();

        $this->getJson("/payments/attempts/{$attempt->public_id}")
            ->assertOk()
            ->assertJsonPath('state', 'pending')
            ->assertJsonMissingPath('client_secret');
    }

    public function test_status_returns_the_client_secret_once_ready(): void
    {
        $attempt = $this->attempt([
            'stripe_payment_intent_id' => 'pi_1',
            'status' => PaymentAttemptStatus::RequiresPaymentMethod,
            'client_secret' => 'pi_1_secret_abc',
        ]);

        $this->getJson("/payments/attempts/{$attempt->public_id}")
            ->assertOk()
            ->assertJsonPath('state', 'ready')
            ->assertJsonPath('client_secret', 'pi_1_secret_abc')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_status_reports_failure_without_leaking_stripe_details(): void
    {
        $attempt = $this->attempt(['status' => PaymentAttemptStatus::Failed, 'failure_code' => 'amount_too_small', 'failure_message' => 'Secret internal detail']);

        $response = $this->getJson("/payments/attempts/{$attempt->public_id}")
            ->assertOk()
            ->assertJsonPath('state', 'failed')
            ->assertJsonMissingPath('client_secret');

        $this->assertStringNotContainsString('Secret internal detail', $response->getContent());
        $this->assertStringNotContainsString('amount_too_small', $response->getContent());
    }

    public function test_status_is_done_and_hides_the_secret_once_the_payment_is_past_the_customer(): void
    {
        foreach ([PaymentAttemptStatus::Processing, PaymentAttemptStatus::Succeeded] as $status) {
            PaymentAttempt::query()->delete();

            $attempt = $this->attempt([
                'stripe_payment_intent_id' => 'pi_'.$status->value,
                'status' => $status,
                'client_secret' => 'should_not_be_returned',
            ]);

            $this->getJson("/payments/attempts/{$attempt->public_id}")
                ->assertOk()
                ->assertJsonPath('state', 'done')
                ->assertJsonMissingPath('client_secret');
        }
    }

    public function test_an_unknown_attempt_is_a_404(): void
    {
        $this->getJson('/payments/attempts/'.Str::ulid())->assertNotFound();
        $this->getJson('/payments/attempts/1')->assertNotFound();
    }

    public function test_the_create_endpoint_returns_no_client_secret_even_when_one_exists(): void
    {
        $key = (string) Str::uuid();
        Queue::fake();
        $this->create(key: $key);
        PaymentAttempt::first()->update([
            'stripe_payment_intent_id' => 'pi_1',
            'status' => PaymentAttemptStatus::RequiresPaymentMethod,
            'client_secret' => 'pi_1_secret_abc',
        ]);

        $this->create(key: $key)->assertJsonMissingPath('client_secret');
    }
}
