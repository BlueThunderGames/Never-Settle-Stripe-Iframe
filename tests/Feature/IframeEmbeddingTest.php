<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IframeEmbeddingTest extends TestCase
{
    use RefreshDatabase;

    private function ancestors(string $uri = '/'): string
    {
        $csp = $this->get($uri)->headers->get('Content-Security-Policy');

        $this->assertStringStartsWith('frame-ancestors ', (string) $csp);

        return $csp;
    }

    public function test_it_allows_the_app_localhost_and_webflow_by_default(): void
    {
        $csp = $this->ancestors();

        $this->assertSame("frame-ancestors 'self' http://localhost:* http://127.0.0.1:* https://*.webflow.io", $csp);
    }

    public function test_the_allowed_sites_can_be_swapped_through_config(): void
    {
        config(['payments.embed.allowed_origins' => ['https://pay.example.com', 'http://localhost:3000']]);

        $this->assertSame("frame-ancestors 'self' https://pay.example.com http://localhost:3000", $this->ancestors());
    }

    public function test_only_self_is_allowed_when_no_sites_are_configured(): void
    {
        config(['payments.embed.allowed_origins' => []]);

        $this->assertSame("frame-ancestors 'self' ", $this->ancestors());
    }

    public function test_malformed_entries_are_dropped_so_they_cannot_inject_directives(): void
    {
        config(['payments.embed.allowed_origins' => [
            'https://good.example.com',
            "https://evil.com; script-src 'unsafe-inline'",
            'https://evil.com/path',
            "https://a.com\r\nX-Injected: 1",
            "'none'",
            '*',
            '',
        ]]);

        $csp = $this->ancestors();

        $this->assertSame("frame-ancestors 'self' https://good.example.com", $csp);
        $this->assertStringNotContainsString('script-src', $csp);
    }

    public function test_the_return_page_and_json_endpoints_get_the_header_too(): void
    {
        $this->post('/payments/quote', ['amount' => '10', 'payment_type' => 'card'], ['Accept' => 'application/json'])
            ->assertHeader('Content-Security-Policy');

        $this->get('/payments/return/01hzzzzzzzzzzzzzzzzzzzzzzz')
            ->assertNotFound()
            ->assertHeader('Content-Security-Policy');
    }

    public function test_it_does_not_send_the_conflicting_x_frame_options_header(): void
    {
        $this->get('/')->assertHeaderMissing('X-Frame-Options');
    }

    public function test_the_default_origins_come_from_the_environment_setting(): void
    {
        $origins = config('payments.embed.allowed_origins');

        $this->assertContains('http://localhost:*', $origins);
        $this->assertContains('https://*.webflow.io', $origins);
    }
}
