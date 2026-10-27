@extends('layouts.iframe')

@php
    $isAch = $attempt->payment_type === \App\Enums\PaymentType::Ach;
    $total = '$'.number_format($attempt->total_cents / 100, 2);

    $content = match (true) {
        $outcome === 'success' => [
            'title' => $isAch ? 'Bank payment received' : 'Payment successful',
            'message' => "Thank you. We received your payment of {$total} for invoice {$attempt->invoice_number}.",
            'tone' => 'success',
        ],
        $outcome === 'processing' => [
            'title' => $isAch ? 'Bank payment submitted' : 'Payment processing',
            'message' => $isAch
                ? "Your bank payment of {$total} for invoice {$attempt->invoice_number} has been submitted. It can take a few business days to clear."
                : "Your payment of {$total} for invoice {$attempt->invoice_number} is being processed.",
            'tone' => 'pending',
        ],
        $outcome === 'verification' => [
            'title' => 'Verify your bank account',
            'message' => "Your bank account was added, but it must be verified before your payment of {$total} for invoice {$attempt->invoice_number} can be submitted. "
                .(($verification['arrival'] ?? null)
                    ? 'Stripe will send a small deposit to your bank by '.$verification['arrival']->format('F j, Y').'. '
                    : 'Stripe will send a small deposit to your bank within 1–2 business days. ')
                .'Once it arrives, confirm it using the link below. Your payment is not complete until then.',
            'tone' => 'pending',
        ],
        default => [
            'title' => 'Payment unsuccessful',
            'message' => ($attempt->stripe_payment_intent_id && $attempt->failure_message)
                ? $attempt->failure_message
                : "We couldn't complete your payment. You can try again.",
            'tone' => 'error',
        ],
    };

    $tones = [
        'success' => ['ring' => 'bg-emerald-100 text-emerald-600', 'icon' => 'M4.5 12.75l6 6 9-13.5'],
        'pending' => ['ring' => 'bg-indigo-100 text-indigo-600', 'icon' => 'M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z'],
        'error' => ['ring' => 'bg-red-100 text-red-600', 'icon' => 'M6 18L18 6M6 6l12 12'],
    ][$content['tone']];
@endphp

@section('title', $content['title'])

@section('content')
    <div class="rounded-2xl border border-slate-200 bg-white px-6 py-10 text-center shadow-sm">
        <div class="mx-auto flex size-12 items-center justify-center rounded-full {{ $tones['ring'] }}">
            <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $tones['icon'] }}" />
            </svg>
        </div>

        <h1 class="mt-5 text-lg font-semibold tracking-tight text-slate-900">{{ $content['title'] }}</h1>

        <p
            class="mt-2 text-sm text-slate-600"
            @if ($content['tone'] === 'error') role="alert" @else role="status" @endif
        >
            {{ $content['message'] }}
        </p>

        @if (($verification['url'] ?? null) && $outcome === 'verification')
            <a
                href="{{ $verification['url'] }}"
                target="_blank"
                rel="noopener noreferrer"
                class="mt-8 inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
            >
                Verify bank account
            </a>
        @endif

        <div>
            <a
                href="{{ route('payments.show') }}"
                @class([
                    'inline-flex items-center justify-center rounded-lg px-4 py-2.5 text-sm font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600',
                    'mt-8 bg-indigo-600 text-white shadow-sm hover:bg-indigo-500' => ! ($outcome === 'verification' && ($verification['url'] ?? null)),
                    'mt-3 text-slate-600 hover:text-slate-900' => $outcome === 'verification' && ($verification['url'] ?? null),
                ])
            >
                {{ $content['tone'] === 'error' ? 'Try again' : 'Make another payment' }}
            </a>
        </div>
    </div>
@endsection
