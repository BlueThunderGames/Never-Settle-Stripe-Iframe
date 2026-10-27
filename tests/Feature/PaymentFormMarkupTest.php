<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * resources/js/payment-form.js finds its elements by these hooks. The JavaScript tests use
 * a copy of the markup, so this keeps that copy honest: if a hook disappears from the real
 * template, this fails before the form breaks in a browser.
 */
class PaymentFormMarkupTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_form_has_every_hook_the_script_uses(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $hooks = [
            'data-payment-form', 'data-stripe-key=', 'data-quote-url=', 'data-intent-url=', 'data-return-url=',
            'data-form-error', 'data-step="details"', 'data-step="payment"', 'data-payment-element',
            'data-edit-details', 'data-submit', 'data-submit-label', 'data-submit-spinner',
            'data-summary-amount', 'data-summary-fee', 'data-summary-total', 'data-payment-type',
            'data-field-error="invoice_number"', 'data-field-error="amount"', 'data-field-error="payment_type"',
            'id="invoice_number"', 'id="amount"', 'name="csrf-token"',
        ];

        foreach ($hooks as $hook) {
            $this->assertStringContainsString($hook, $html, "The form no longer contains [{$hook}], which payment-form.js needs.");
        }
    }

    public function test_the_script_posts_to_the_urls_the_routes_define(): void
    {
        $html = $this->get('/')->getContent();

        $this->assertStringContainsString('data-quote-url="'.route('payments.quote').'"', $html);
        $this->assertStringContainsString('data-intent-url="'.route('payments.attempts.store').'"', $html);
        $this->assertStringContainsString('data-return-url="'.url('/payments/return').'"', $html);
    }

    public function test_the_payment_method_options_match_the_supported_types(): void
    {
        $html = $this->get('/')->getContent();

        foreach (['card', 'ach'] as $value) {
            $this->assertStringContainsString('value="'.$value.'"', $html);
        }
    }
}
