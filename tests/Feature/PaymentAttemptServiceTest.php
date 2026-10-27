<?php

namespace Tests\Feature;

use App\Enums\PaymentAttemptStatus;
use App\Exceptions\IdempotencyKeyReused;
use App\Models\PaymentAttempt;
use App\Services\PaymentAttemptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentAttemptServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): PaymentAttemptService
    {
        return app(PaymentAttemptService::class);
    }

    public function test_it_creates_a_priced_pending_attempt(): void
    {
        $attempt = $this->service()->findOrCreate('key-1', ' INV-1 ', 10098, 'card');

        $this->assertTrue($attempt->wasRecentlyCreated);
        $this->assertSame('INV-1', $attempt->invoice_number);
        $this->assertSame(10098, $attempt->amount_cents);
        $this->assertSame(333, $attempt->fee_cents);
        $this->assertSame(10431, $attempt->total_cents);
        $this->assertSame('usd', $attempt->currency);
        $this->assertSame(PaymentAttemptStatus::Pending, $attempt->status);
        $this->assertNotEmpty($attempt->public_id);
    }

    public function test_it_prices_ach_with_the_ach_rules(): void
    {
        $attempt = $this->service()->findOrCreate('key-ach', 'INV-1', 10098, 'ach');

        $this->assertSame(82, $attempt->fee_cents);
        $this->assertSame(10180, $attempt->total_cents);
    }

    public function test_the_same_key_and_details_return_the_same_attempt(): void
    {
        $first = $this->service()->findOrCreate('key-1', 'INV-1', 10098, 'card');
        $second = $this->service()->findOrCreate('key-1', 'INV-1', 10098, 'card');

        $this->assertFalse($second->wasRecentlyCreated);
        $this->assertTrue($first->is($second));
        $this->assertSame(1, PaymentAttempt::count());
    }

    public function test_a_retry_keeps_the_original_pricing_even_if_rates_change(): void
    {
        $first = $this->service()->findOrCreate('key-1', 'INV-1', 10098, 'card');

        config(['payments.fees.card.percentage' => 5.0]);
        app()->forgetInstance(\App\Services\FeeCalculator::class);

        $second = $this->service()->findOrCreate('key-1', 'INV-1', 10098, 'card');

        $this->assertSame($first->total_cents, $second->total_cents);
    }

    public function test_a_reused_key_with_different_details_is_rejected(): void
    {
        $this->service()->findOrCreate('key-1', 'INV-1', 10098, 'card');

        foreach ([['INV-2', 10098, 'card'], ['INV-1', 10099, 'card'], ['INV-1', 10098, 'ach']] as [$invoice, $amount, $type]) {
            try {
                $this->service()->findOrCreate('key-1', $invoice, $amount, $type);
                $this->fail("Expected IdempotencyKeyReused for {$invoice} {$amount} {$type}");
            } catch (IdempotencyKeyReused) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(1, PaymentAttempt::count());
    }

    public function test_different_keys_create_separate_attempts_for_the_same_invoice(): void
    {
        $a = $this->service()->findOrCreate('key-1', 'INV-1', 10098, 'card');
        $b = $this->service()->findOrCreate('key-2', 'INV-1', 10098, 'card');

        $this->assertFalse($a->is($b));
        $this->assertNotSame($a->public_id, $b->public_id);
    }
}
