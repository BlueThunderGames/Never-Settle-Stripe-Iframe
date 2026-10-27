<?php

namespace Tests\Unit;

use App\Services\FeeCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FeeCalculatorTest extends TestCase
{
    private function calculator(?float $maxCardSurcharge = null): FeeCalculator
    {
        return new FeeCalculator([
            'card' => ['percentage' => 2.9, 'fixed_cents' => 30, 'max_surcharge_percentage' => $maxCardSurcharge],
            'ach' => ['percentage' => 0.8, 'cap_cents' => 500],
        ]);
    }

    #[DataProvider('knownQuotes')]
    public function test_it_adds_the_expected_fee(int $amount, string $type, int $fee): void
    {
        $this->assertSame(
            ['amount_cents' => $amount, 'fee_cents' => $fee, 'total_cents' => $amount + $fee],
            $this->calculator()->quote($amount, $type),
        );
    }

    public static function knownQuotes(): array
    {
        return [
            'card $100.98 rounds 104.3048 up to 104.31' => [10098, 'card', 333],
            'card $1,000.50' => [100050, 'card', 3020],
            'card minimum $0.50' => [50, 'card', 33],
            'card total divides exactly, so no extra cent' => [10651, 'card', 349],
            'ach $100.98 rounds 101.7943 up to 101.80' => [10098, 'ach', 82],
            'ach $100.00' => [10000, 'ach', 81],
            'ach $1,000.50 hits the cap' => [100050, 'ach', 500],
            'ach well past the cap' => [10000000, 'ach', 500],
            'ach 1 cent rounds up' => [1, 'ach', 1],
        ];
    }

    #[DataProvider('paymentTypes')]
    public function test_merchant_nets_the_full_invoice_after_stripe_fees(string $type): void
    {
        $calculator = $this->calculator();

        foreach ([...range(50, 2000, 7), ...range(2001, 400000, 997), 10000000] as $amount) {
            $total = $calculator->quote($amount, $type)['total_cents'];

            $stripeFee = $type === 'card'
                ? (int) round($total * 0.029 + 30)
                : min((int) round($total * 0.008), 500);

            // Fee is rounded up, so we may over-collect by a cent but never fall short.
            $net = $total - $stripeFee;
            $this->assertGreaterThanOrEqual($amount - 1, $net, "{$type} {$amount}");
            $this->assertLessThanOrEqual($amount + 1, $net, "{$type} {$amount}");
        }
    }

    public static function paymentTypes(): array
    {
        return ['card' => ['card'], 'ach' => ['ach']];
    }

    public function test_ach_fee_never_exceeds_the_cap(): void
    {
        foreach ([100000, 1000000, 99999999] as $amount) {
            $this->assertLessThanOrEqual(500, $this->calculator()->quote($amount, 'ach')['fee_cents']);
        }
    }

    public function test_an_optional_card_surcharge_ceiling_limits_the_fee(): void
    {
        $capped = $this->calculator(3.0);

        $this->assertSame(3001, $capped->quote(100050, 'card')['fee_cents']);
        $this->assertSame(30, $capped->quote(1000, 'card')['fee_cents']);
        $this->assertSame(7498, $capped->quote(250000, 'card')['fee_cents'], 'ceiling does not bind on large invoices');

        foreach ([...range(1, 5000), 230000, 99999999] as $amount) {
            $this->assertLessThanOrEqual($amount * 0.03, $capped->quote($amount, 'card')['fee_cents'], "card {$amount}");
        }
    }

    public function test_the_ceiling_does_not_apply_to_ach(): void
    {
        $this->assertSame(
            $this->calculator()->quote(10098, 'ach'),
            $this->calculator(3.0)->quote(10098, 'ach'),
        );
    }

    public function test_it_rejects_non_positive_amounts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator()->quote(0, 'card');
    }

    public function test_it_rejects_unknown_payment_types(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator()->quote(1000, 'wire');
    }
}
