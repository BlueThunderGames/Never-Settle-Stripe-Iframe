<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PaymentQuoteTest extends TestCase
{
    public function test_it_quotes_a_card_payment(): void
    {
        $this->postJson('/payments/quote', ['amount' => '1,000.50', 'payment_type' => 'card'])
            ->assertOk()
            ->assertExactJson(['amount_cents' => 100050, 'fee_cents' => 3019, 'total_cents' => 103069]);
    }

    public function test_it_applies_the_optional_card_surcharge_ceiling(): void
    {
        config(['payments.fees.card.max_surcharge_percentage' => 3.0]);

        $this->postJson('/payments/quote', ['amount' => '1,000.50', 'payment_type' => 'card'])
            ->assertOk()
            ->assertJsonPath('fee_cents', 3001);
    }

    public function test_it_quotes_an_ach_payment_with_the_fee_cap(): void
    {
        $this->postJson('/payments/quote', ['amount' => '1,000.50', 'payment_type' => 'ach'])
            ->assertOk()
            ->assertExactJson(['amount_cents' => 100050, 'fee_cents' => 500, 'total_cents' => 100550]);
    }

    public function test_it_accepts_a_json_number_amount(): void
    {
        $this->postJson('/payments/quote', ['amount' => 19.99, 'payment_type' => 'card'])
            ->assertOk()
            ->assertJsonPath('amount_cents', 1999);
    }

    public function test_it_honors_configured_rates(): void
    {
        config(['payments.fees.ach.cap_cents' => 100]);

        $this->postJson('/payments/quote', ['amount' => '1000', 'payment_type' => 'ach'])
            ->assertOk()
            ->assertJsonPath('fee_cents', 100);
    }

    #[DataProvider('invalidPayloads')]
    public function test_it_rejects_invalid_input(array $payload, string $field, string $message): void
    {
        $this->postJson('/payments/quote', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field => $message]);
    }

    public static function invalidPayloads(): array
    {
        return [
            'missing amount' => [['payment_type' => 'card'], 'amount', 'Enter an amount.'],
            'non-numeric amount' => [['amount' => 'abc', 'payment_type' => 'card'], 'amount', 'Enter a valid dollar amount, e.g. 125.50'],
            'too many decimals' => [['amount' => '12.345', 'payment_type' => 'card'], 'amount', 'Enter a valid dollar amount, e.g. 125.50'],
            'array amount' => [['amount' => ['1'], 'payment_type' => 'card'], 'amount', 'Enter a valid dollar amount, e.g. 125.50'],
            'below minimum' => [['amount' => '0.49', 'payment_type' => 'ach'], 'amount', 'The minimum payment is $0.50.'],
            'above maximum' => [['amount' => '1000000', 'payment_type' => 'ach'], 'amount', 'The maximum payment is $999,999.99.'],
            'missing payment type' => [['amount' => '10'], 'payment_type', 'Choose a payment method.'],
            'unknown payment type' => [['amount' => '10', 'payment_type' => 'wire'], 'payment_type', 'Choose a valid payment method.'],
        ];
    }

    public function test_the_total_including_the_fee_must_stay_within_stripes_maximum(): void
    {
        $tooLarge = 'That amount is too large once the processing fee is added. Please enter a smaller amount.';

        // $999,999.99 is a valid invoice, but the card fee pushes the total past $999,999.99.
        $this->postJson('/payments/quote', ['amount' => '999,999.99', 'payment_type' => 'card'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount' => $tooLarge]);

        // ACH adds a flat $5.00 here, so the largest invoice is $999,994.99 (total exactly $999,999.99).
        $this->postJson('/payments/quote', ['amount' => '999,994.99', 'payment_type' => 'ach'])
            ->assertOk()
            ->assertJsonPath('total_cents', 99999999);

        $this->postJson('/payments/quote', ['amount' => '999,995.00', 'payment_type' => 'ach'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount' => $tooLarge]);
    }

    public function test_it_honors_a_configured_total_limit(): void
    {
        config(['payments.limits.max_total_cents' => 100000]);

        $this->postJson('/payments/quote', ['amount' => '1000', 'payment_type' => 'ach'])
            ->assertUnprocessable();

        $this->postJson('/payments/quote', ['amount' => '990', 'payment_type' => 'ach'])
            ->assertOk();
    }

    public function test_invalid_input_never_returns_a_quote(): void
    {
        $this->postJson('/payments/quote', ['amount' => 'abc', 'payment_type' => 'card'])
            ->assertJsonMissing(['total_cents']);
    }

    public function test_the_form_page_renders(): void
    {
        $this->get('/')->assertOk()->assertSee('data-quote-url', false);
    }
}
