<?php

namespace Ambresoft\CartReminders\Infrastructure;

use App\Shared\Application\Command\SettingField;
use App\Shared\Application\Contract\AddonSettings;

final class CartReminderSettings implements AddonSettings
{
    public function settings(): array
    {
        return [
            new SettingField('delay_hours', 'integer', 'cart-reminders::reminders.delay_hours', 'cart-reminders::reminders.delay_hours_hint', true, default: '3', min: '1', max: '720'),
            new SettingField('max_reminders', 'integer', 'cart-reminders::reminders.max_reminders', 'cart-reminders::reminders.max_reminders_hint', true, default: '1', min: '1', max: '10'),
            new SettingField('repeat_hours', 'integer', 'cart-reminders::reminders.repeat_hours', 'cart-reminders::reminders.repeat_hours_hint', true, default: '24', min: '1', max: '720'),
        ];
    }

    public function validateSettings(array $values): array
    {
        return [];
    }
}
