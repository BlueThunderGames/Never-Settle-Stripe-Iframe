<?php

namespace App\Enums;

enum PaymentType: string
{
    case Card = 'card';
    case Ach = 'ach';

    /**
     * The Stripe payment method type that pays for this option.
     */
    public function stripeMethodType(): string
    {
        return match ($this) {
            self::Card => 'card',
            self::Ach => 'us_bank_account',
        };
    }
}
