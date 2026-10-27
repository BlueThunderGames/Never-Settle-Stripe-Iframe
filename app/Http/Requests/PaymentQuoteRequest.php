<?php

namespace App\Http\Requests;

use App\Services\FeeCalculator;
use App\Services\MoneyParser;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class PaymentQuoteRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'amount' => ['bail', 'required', $this->validAmount(...)],
            'payment_type' => ['bail', 'required', Rule::in([FeeCalculator::CARD, FeeCalculator::ACH])],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'Enter an amount.',
            'payment_type.required' => 'Choose a payment method.',
            'payment_type.in' => 'Choose a valid payment method.',
        ];
    }

    /**
     * The validated amount in integer cents.
     */
    public function amountCents(): int
    {
        return app(MoneyParser::class)->toCents($this->validated('amount'));
    }

    public function paymentType(): string
    {
        return $this->validated('payment_type');
    }

    private function validAmount(string $attribute, mixed $value, Closure $fail): void
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
