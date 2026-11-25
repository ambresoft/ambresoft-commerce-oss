<?php

namespace Ambresoft\GoogleAnalytics\Infrastructure;

use Ambresoft\GoogleAnalytics\Infrastructure\Jobs\SendPurchase;
use App\Order\Application\Event\OrderPlaced;
use App\Shared\Application\Contract\AddonSettingValues;
use App\Shared\Application\Contract\AddonStatus;
use App\Storefront\Application\Service\StorefrontRegistry;
use App\Storefront\Infrastructure\StorefrontConsent;
use App\Storefront\Infrastructure\StorefrontTagManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class GoogleAnalyticsServiceProvider extends ServiceProvider
{
    public const NAME = 'ambresoft/google-analytics';

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../../lang', 'cms-ga');
        $this->app->make(StorefrontRegistry::class)->googleAnalytics(self::NAME, function (): ?array {
            $values = $this->app->make(AddonSettingValues::class)->values(self::NAME);

            return ($values['measurement_id'] ?? '') === '' ? null : ['measurementId' => $values['measurement_id'], 'ecommerce' => ($values['track_ecommerce'] ?? '0') === '1'];
        });
        Event::listen(OrderPlaced::class, function (OrderPlaced $order): void {
            // A provider outlives a disable in a long-running process.
            if (! $this->app->make(AddonStatus::class)->isEnabled(self::NAME)) {
                return;
            }
            $values = $this->app->make(AddonSettingValues::class)->values(self::NAME);
            // Without gtag's IDs the visitor blocked or never loaded Analytics: no purchase is sent on their behalf.
            $visitor = app(StorefrontConsent::class)->granted(request(), 'statistics') ? StorefrontTagManager::analyticsVisitor(request()) : null;
            if (($values['measurement_id'] ?? '') !== '' && ($values['track_ecommerce'] ?? '0') === '1' && ($values['api_secret'] ?? '') !== '' && $visitor !== null) {
                SendPurchase::dispatch($order, $visitor['clientId'], $visitor['sessionId']);
            }
        });
    }
}
