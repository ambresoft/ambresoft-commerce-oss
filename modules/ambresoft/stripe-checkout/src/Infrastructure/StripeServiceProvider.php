<?php

namespace Ambresoft\StripeCheckout\Infrastructure;

use Illuminate\Support\ServiceProvider;

/**
 * Registered only while the add-on is enabled. The payment method is declared in composer.json; the webhook endpoint is
 * the CMS's generic `POST /payments/webhooks/stripe_checkout`, which calls the method for signature verification.
 */
final class StripeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StripeClients::class);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../../lang', 'ambresoft-stripe');
    }
}
