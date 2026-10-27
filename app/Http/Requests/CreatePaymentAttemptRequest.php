<?php

namespace App\Http\Requests;

class CreatePaymentAttemptRequest extends PaymentQuoteRequest
{
    /**
     * The idempotency key travels in a header, as is conventional; validate it with the body.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function rules(): array
    {
        return [
            ...parent::rules(),
            'invoice_number' => ['bail', 'required', 'string', 'max:64', 'regex:/^[^\x00-\x1F\x7F]+$/'],
            'idempotency_key' => ['bail', 'required', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'invoice_number.required' => 'Enter your invoice number.',
            'invoice_number.max' => 'The invoice number can be at most 64 characters.',
            'invoice_number.regex' => 'The invoice number contains invalid characters.',
            'idempotency_key.required' => 'Missing Idempotency-Key header.',
            'idempotency_key.uuid' => 'The Idempotency-Key header must be a UUID.',
        ];
    }

    public function invoiceNumber(): string
    {
        return $this->validated('invoice_number');
    }

    public function idempotencyKey(): string
    {
        return $this->validated('idempotency_key');
    }
}
