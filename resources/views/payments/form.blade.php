@extends('layouts.iframe')

@section('title', 'Pay an Invoice')

@php
    $cardPercent = config('payments.fees.card.percentage');
    $cardFixed = config('payments.fees.card.fixed_cents') / 100;
    $achPercent = config('payments.fees.ach.percentage');
    $achCap = config('payments.fees.ach.cap_cents') / 100;

    $paymentTypes = [
        'card' => [
            'label' => 'Credit / Debit Card',
            'fee' => rtrim(rtrim(number_format($cardPercent, 2), '0'), '.').'% + $'.number_format($cardFixed, 2),
            'icon' => 'M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z',
        ],
        'ach' => [
            'label' => 'Bank Transfer (ACH)',
            'fee' => rtrim(rtrim(number_format($achPercent, 2), '0'), '.').'%, max $'.number_format($achCap, 2),
            'icon' => 'M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6m-1.5 12V10.332A48.36 48.36 0 0 0 12 9.75c-2.551 0-5.056.2-7.5.582V21M3 21h18M12 6.75h.008v.008H12V6.75Z',
        ],
    ];

    $selectedType = old('payment_type', 'card');
@endphp

@push('head')
    <script src="https://js.stripe.com/v3/" defer></script>
@endpush

@push('scripts')
    @vite('resources/js/payment-form.js')
@endpush

@section('content')
    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
        <header class="border-b border-slate-100 px-6 py-5">
            <h1 class="text-lg font-semibold tracking-tight text-slate-900">Pay an Invoice</h1>
            <p class="mt-1 text-sm text-slate-500">
                Enter your invoice details below. A processing fee is added to cover payment costs.
            </p>
        </header>

        <form
            id="payment-form"
            method="POST"
            novalidate
            class="space-y-6 px-6 py-6"
            data-payment-form
            data-stripe-key="{{ config('services.stripe.key') }}"
            data-quote-url="{{ url('/payments/quote') }}"
            data-intent-url="{{ url('/payments/attempts') }}"
            data-return-url="{{ url('/payments/return') }}"
        >
            @csrf

            {{-- Populated by payment-form.js for client-side and API errors --}}
            <div
                role="alert"
                aria-live="assertive"
                data-form-error
                @class([
                    'rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700',
                    'hidden' => ! $errors->any(),
                ])
            >
                @if ($errors->any())
                    {{ $errors->first() }}
                @endif
            </div>

            {{-- Step 1: invoice details --}}
            <fieldset class="space-y-5" data-step="details">
                <legend class="sr-only">Invoice details</legend>

                <div>
                    <label for="invoice_number" class="block text-sm font-medium text-slate-700">
                        Invoice number
                    </label>
                    <input
                        type="text"
                        id="invoice_number"
                        name="invoice_number"
                        value="{{ old('invoice_number') }}"
                        required
                        maxlength="64"
                        autocomplete="off"
                        spellcheck="false"
                        placeholder="INV-10042"
                        aria-describedby="invoice_number-error"
                        @class([
                            'mt-1.5 block w-full rounded-lg border bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-xs placeholder:text-slate-400 focus:outline-none focus:ring-2',
                            'border-slate-300 focus:border-indigo-500 focus:ring-indigo-500/20' => ! $errors->has('invoice_number'),
                            'border-red-400 focus:border-red-500 focus:ring-red-500/20' => $errors->has('invoice_number'),
                        ])
                    >
                    <p id="invoice_number-error" class="mt-1.5 text-xs text-red-600" data-field-error="invoice_number">
                        @error('invoice_number') {{ $message }} @enderror
                    </p>
                </div>

                <div>
                    <label for="amount" class="block text-sm font-medium text-slate-700">
                        Invoice amount
                    </label>
                    <div class="relative mt-1.5">
                        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-sm text-slate-500">$</span>
                        <input
                            type="text"
                            id="amount"
                            name="amount"
                            value="{{ old('amount') }}"
                            required
                            inputmode="decimal"
                            autocomplete="off"
                            pattern="\d{1,3}(,?\d{3})*(\.\d{1,2})?"
                            placeholder="0.00"
                            aria-describedby="amount-error"
                            @class([
                                'block w-full rounded-lg border bg-white py-2.5 pl-7 pr-14 text-sm tabular-nums text-slate-900 shadow-xs placeholder:text-slate-400 focus:outline-none focus:ring-2',
                                'border-slate-300 focus:border-indigo-500 focus:ring-indigo-500/20' => ! $errors->has('amount'),
                                'border-red-400 focus:border-red-500 focus:ring-red-500/20' => $errors->has('amount'),
                            ])
                        >
                        <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3.5 text-xs font-medium text-slate-400">USD</span>
                    </div>
                    <p id="amount-error" class="mt-1.5 text-xs text-red-600" data-field-error="amount">
                        @error('amount') {{ $message }} @enderror
                    </p>
                </div>

                <div>
                    <span class="block text-sm font-medium text-slate-700" id="payment_type-label">Payment method</span>
                    <div class="mt-1.5 grid grid-cols-1 gap-3 sm:grid-cols-2" role="radiogroup" aria-labelledby="payment_type-label">
                        @foreach ($paymentTypes as $value => $type)
                            <label
                                class="relative flex cursor-pointer items-start gap-3 rounded-lg border border-slate-300 bg-white p-4 shadow-xs transition hover:border-slate-400 has-checked:border-indigo-600 has-checked:bg-indigo-50/50 has-checked:ring-1 has-checked:ring-indigo-600 has-focus-visible:ring-2 has-focus-visible:ring-indigo-500/40"
                            >
                                <input
                                    type="radio"
                                    name="payment_type"
                                    value="{{ $value }}"
                                    class="peer sr-only"
                                    data-payment-type
                                    @checked($selectedType === $value)
                                >
                                <svg class="size-5 shrink-0 text-slate-400 peer-checked:text-indigo-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="{{ $type['icon'] }}" />
                                </svg>
                                <span class="flex flex-col">
                                    <span class="text-sm font-medium text-slate-900">{{ $type['label'] }}</span>
                                    <span class="mt-0.5 text-xs text-slate-500">Fee: {{ $type['fee'] }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    <p class="mt-1.5 text-xs text-red-600" data-field-error="payment_type">
                        @error('payment_type') {{ $message }} @enderror
                    </p>
                </div>
            </fieldset>

            {{-- Fee breakdown, values filled in by payment-form.js from the quote endpoint --}}
            <section aria-labelledby="summary-heading" class="rounded-lg bg-slate-50 px-4 py-4" data-summary>
                <h2 id="summary-heading" class="sr-only">Payment summary</h2>
                <dl class="space-y-2 text-sm">
                    <div class="flex items-center justify-between">
                        <dt class="text-slate-600">Invoice amount</dt>
                        <dd class="tabular-nums text-slate-900" data-summary-amount>—</dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-slate-600">Processing fee</dt>
                        <dd class="tabular-nums text-slate-900" data-summary-fee>—</dd>
                    </div>
                    <div class="flex items-center justify-between border-t border-slate-200 pt-2">
                        <dt class="font-medium text-slate-900">Total due</dt>
                        <dd class="text-base font-semibold tabular-nums text-slate-900" data-summary-total>—</dd>
                    </div>
                </dl>
            </section>

            {{-- Step 2: Stripe Payment Element, mounted once a quote / PaymentIntent exists --}}
            <div class="hidden space-y-3" data-step="payment">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-medium text-slate-700">Payment details</h2>
                    <button type="button" class="text-xs font-medium text-indigo-600 hover:text-indigo-500" data-edit-details>
                        Edit invoice details
                    </button>
                </div>
                <div id="payment-element" class="min-h-10" data-payment-element></div>
            </div>

            <button
                type="submit"
                class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-indigo-600 px-4 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:cursor-not-allowed disabled:opacity-60"
                data-submit
            >
                <svg class="hidden size-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true" data-submit-spinner>
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z"></path>
                </svg>
                <span data-submit-label>Continue to payment</span>
            </button>
        </form>

        <footer class="flex items-center justify-center gap-1.5 border-t border-slate-100 px-6 py-4 text-xs text-slate-400">
            <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
            </svg>
            Payments are securely processed by Stripe
        </footer>
    </div>
@endsection
