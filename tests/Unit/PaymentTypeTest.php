<?php

namespace Tests\Unit;

use App\Enums\PaymentType;
use PHPUnit\Framework\TestCase;

class PaymentTypeTest extends TestCase
{
    public function test_it_maps_each_option_to_the_stripe_payment_method(): void
    {
        $this->assertSame('card', PaymentType::Card->stripeMethodType());
        $this->assertSame('us_bank_account', PaymentType::Ach->stripeMethodType());
    }

    public function test_it_only_accepts_the_supported_values(): void
    {
        $this->assertSame(PaymentType::Card, PaymentType::tryFrom('card'));
        $this->assertSame(PaymentType::Ach, PaymentType::tryFrom('ach'));
        $this->assertNull(PaymentType::tryFrom('wire'));
        $this->assertNull(PaymentType::tryFrom('CARD'));
    }
}
