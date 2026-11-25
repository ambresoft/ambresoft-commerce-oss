<?php

namespace App\Order\Infrastructure;

use App\Order\Application\Contract\OrderMailTransport;
use App\Pricing\Application\Service\CurrencyDeletionGuards;
use App\Shared\Application\Contract\StoreMailer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

final class OrderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The method chosen in Settings → General → Emails: PHP mail(), SMTP or sendmail.
        $this->app->bind(OrderMailTransport::class, ConfiguredMailTransport::class);
        $this->app->bind(StoreMailer::class, BrandedStoreMailer::class);
    }

    public function boot(CurrencyDeletionGuards $guards): void
    {
        // Orders keep amounts in their own currency, so that currency's definition must stay.
        $guards->add(fn (string $code): ?string => DB::table('orders')->where('currency_code', $code)->exists() ? 'orders.currency_in_use' : null);
    }
}
