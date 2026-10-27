<?php

namespace App\Http\Requests;

use App\Rules\PaymentAmount;
use App\Services\FeeCalculator;
use App\Services\MoneyParser;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'amount' => ['bail', 'required', new PaymentAmount],
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
}
