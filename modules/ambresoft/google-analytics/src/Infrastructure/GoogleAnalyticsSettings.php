<?php

namespace Ambresoft\GoogleAnalytics\Infrastructure;

use App\Shared\Application\Command\SettingField;
use App\Shared\Application\Contract\AddonSettings;

/** The CS-Cart settings for GA4: a measurement ID replaces the retired UA account number, and purchases need an API secret. */
final class GoogleAnalyticsSettings implements AddonSettings
{
    private const T = 'cms-ga::ga.';

    public function settings(): array
    {
        return [
            new SettingField('measurement_id', 'text', self::T.'measurement_id', self::T.'measurement_id_hint', maxLength: 20),
            new SettingField('track_ecommerce', 'boolean', self::T.'track_ecommerce', self::T.'track_ecommerce_hint', default: '1'),
            new SettingField('api_secret', 'secret', self::T.'api_secret', self::T.'api_secret_hint', maxLength: 100),
        ];
    }

    public function validateSettings(array $values): array
    {
        $id = (string) ($values['measurement_id'] ?? '');
        if (str_starts_with(strtoupper($id), 'UA-')) {
            return ['measurement_id' => __(self::T.'universal_retired')];
        }
        if ($id !== '' && preg_match('/^G-[A-Z0-9]{4,15}$/D', $id) !== 1) {
            return ['measurement_id' => __(self::T.'invalid_measurement_id')];
        }
        if (($values['api_secret'] ?? '') !== '' && preg_match('/^[A-Za-z0-9_-]{8,100}$/D', (string) $values['api_secret']) !== 1) {
            return ['api_secret' => __(self::T.'invalid_api_secret')];
        }

        return [];
    }
}
