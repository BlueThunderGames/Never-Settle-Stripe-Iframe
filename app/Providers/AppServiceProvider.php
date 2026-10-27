<?php

namespace App\Providers;

use App\Services\FeeCalculator;
use Illuminate\Support\ServiceProvider;
use RuntimeException;
use Stripe\StripeClient;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(FeeCalculator::class, fn () => new FeeCalculator(config('payments.fees')));

        $this->app->singleton(StripeClient::class, function () {
            $secret = config('services.stripe.secret');

            if (blank($secret)) {
                throw new RuntimeException('STRIPE_SECRET is not set. Add your Stripe secret key to .env.');
            }

            // Retry transient network failures. The SDK reuses the same idempotency key, so this can't double-create.
            return new StripeClient(['api_key' => $secret, 'max_network_retries' => 2]);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
