<?php

namespace Ambresoft\GoogleTagManager\Infrastructure;

use App\Shared\Application\Contract\AddonSettingValues;
use App\Storefront\Application\Service\StorefrontRegistry;
use Illuminate\Support\ServiceProvider;

final class GoogleTagManagerServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../../lang', 'cms-gtm');
        $this->app->make(StorefrontRegistry::class)->googleTagManager('ambresoft/google-tag-manager', function (): ?array {
            $values = $this->app->make(AddonSettingValues::class)->values('ambresoft/google-tag-manager');
            if (($values['container_id'] ?? '') === '') {
                return null;
            }
            $events = [];
            foreach (array_keys(GoogleTagManagerSettings::EVENTS) as $event) {
                if (($values[$event] ?? '0') === '1') {
                    $events[$event] = $values[$event.'_name'];
                }
            }

            return ['container' => $values['container_id'], 'dataLayer' => $values['data_layer'], 'events' => $events];
        });
    }
}
