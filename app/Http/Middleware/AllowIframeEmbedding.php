<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets only the configured sites embed this app in an iframe, using the CSP
 * frame-ancestors directive (the modern replacement for X-Frame-Options).
 */
class AllowIframeEmbedding
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $ancestors = array_filter(
            (array) config('payments.embed.allowed_origins'),
            fn ($origin) => $this->isValidSource($origin),
        );

        $response->headers->set(
            'Content-Security-Policy',
            "frame-ancestors 'self' ".implode(' ', $ancestors),
        );

        // The older header can't express an allow-list and would override this one.
        $response->headers->remove('X-Frame-Options');

        return $response;
    }

    /**
     * Only plain host sources, so a bad value in .env can't inject other CSP directives
     * or header content. e.g. https://example.com, http://localhost:*, https://*.webflow.io
     */
    private function isValidSource(mixed $origin): bool
    {
        return is_string($origin)
            && preg_match('#^(https?://)?(\*\.)?[A-Za-z0-9.-]+(:(\d{1,5}|\*))?$#', $origin) === 1;
    }
}
