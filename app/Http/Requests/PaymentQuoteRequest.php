<?php

namespace App\Http\Requests;

use App\Enums\PaymentType;
use App\Rules\PaymentAmount;
use App\Services\FeeCalculator;
use App\Services\MoneyParser;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'payment_type' => ['bail', 'required', Rule::enum(PaymentType::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'Enter an amount.',
            'payment_type.required' => 'Choose a payment method.',
            'payment_type.enum' => 'Choose a valid payment method.',
        ];
    }

    /**
     * Checks that need the amount and the payment type together, once each is individually valid.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $data = $validator->getData();
            $quote = app(FeeCalculator::class)->quote(
                app(MoneyParser::class)->toCents($data['amount']),
                $data['payment_type'],
            );

            if ($quote['total_cents'] > config('payments.limits.max_total_cents')) {
                $validator->errors()->add('amount', 'That amount is too large once the processing fee is added. Please enter a smaller amount.');
            }
        }];
    }

    /**
     * The validated amount in integer cents.
     */
    public function amountCents(): int
    {
        return app(MoneyParser::class)->toCents($this->validated('amount'));
    }

    public function paymentType(): PaymentType
    {
        return PaymentType::from($this->validated('payment_type'));
    }
}
