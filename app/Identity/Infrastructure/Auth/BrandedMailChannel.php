<?php

namespace App\Identity\Infrastructure\Auth;

use App\Settings\Infrastructure\CompanySettings;
use App\Storefront\Infrastructure\StoreBrand;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Support\Facades\Storage;

/** Applies the shared store branding to Laravel's standard notification messages. */
final class BrandedMailChannel extends MailChannel
{
    /** @return string|array<string, mixed> */
    protected function buildView($message)
    {
        if ($message->view === null && $message->markdown === 'notifications::email') {
            $logo = app(CompanySettings::class)->emailLogo();
            $message->view(['html' => 'mail.notification', 'text' => 'mail.notification-text'], [
                'brand' => app(StoreBrand::class)->all(),
                'logoPath' => $logo === null ? null : Storage::disk('local')->path($logo->storage_key),
            ]);
        }

        return parent::buildView($message);
    }
}
