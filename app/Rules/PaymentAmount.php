<?php

namespace App\Rules;

use App\Services\MoneyParser;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

class PaymentAmount implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            $fail('Enter a valid dollar amount, e.g. 125.50');

            return;
        }

        try {
            $cents = app(MoneyParser::class)->toCents($value);
        } catch (InvalidArgumentException $e) {
            $fail($e->getMessage());

            return;
        }

        $min = config('payments.limits.min_amount_cents');
        $max = config('payments.limits.max_amount_cents');

        if ($cents < $min) {
            $fail('The minimum payment is $'.number_format($min / 100, 2).'.');
        } elseif ($cents > $max) {
            $fail('The maximum payment is $'.number_format($max / 100, 2).'.');
        }
    }
}
