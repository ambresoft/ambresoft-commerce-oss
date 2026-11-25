<?php

namespace Ambresoft\GoogleTagManager\Infrastructure;

use App\Shared\Application\Command\SettingField;
use App\Shared\Application\Contract\AddonSettings;

/** The CS-Cart Google Tag Manager settings, with its default event names so existing GTM triggers keep working. */
final class GoogleTagManagerSettings implements AddonSettings
{
    /** Storefront event => default data layer event name. */
    public const EVENTS = ['add_to_cart' => 'gtm-add-to-cart', 'remove_from_cart' => 'gtm-remove-to-cart', 'add_to_wishlist' => 'gtm-wish-list',
        'add_to_comparison' => 'gtm-comparison-list', 'call_request' => 'gtm-call-request'];

    private const T = 'cms-gtm::gtm.';

    public function settings(): array
    {
        $fields = [
            new SettingField('container_id', 'text', self::T.'container_id', self::T.'container_id_hint', maxLength: 16),
            new SettingField('data_layer', 'text', self::T.'data_layer', self::T.'data_layer_hint', true, default: 'dataLayer', maxLength: 64),
        ];
        foreach (self::EVENTS as $event => $name) {
            $fields[] = new SettingField($event, 'boolean', self::T.'enable_'.$event, default: '1');
            $fields[] = new SettingField($event.'_name', 'text', self::T.'name_'.$event, self::T.'name_hint', default: $name, maxLength: 64);
        }

        return $fields;
    }

    public function validateSettings(array $values): array
    {
        $errors = [];
        if (($values['container_id'] ?? '') !== '' && preg_match('/^GTM-[A-Z0-9]{4,12}$/D', (string) $values['container_id']) !== 1) {
            $errors['container_id'] = __(self::T.'invalid_container');
        }
        if (preg_match('/^[A-Za-z_$][A-Za-z0-9_$]{0,63}$/D', (string) ($values['data_layer'] ?? '')) !== 1) {
            $errors['data_layer'] = __(self::T.'invalid_data_layer');
        }
        foreach (array_keys(self::EVENTS) as $event) {
            if (($values[$event] ?? '0') === '1' && preg_match('/^[A-Za-z0-9_.:-]{1,64}$/D', (string) ($values[$event.'_name'] ?? '')) !== 1) {
                $errors[$event.'_name'] = __(self::T.'invalid_name');
            }
        }

        return $errors;
    }
}
