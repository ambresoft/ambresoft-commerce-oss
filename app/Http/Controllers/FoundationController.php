<?php

namespace App\Http\Controllers;

use App\Localization\Infrastructure\LocaleCatalog;
use App\Settings\Infrastructure\GeneralSettings;
use Inertia\Inertia;
use Inertia\Response;

final class FoundationController
{
    public function __invoke(GeneralSettings $settings): Response|\Symfony\Component\HttpFoundation\Response
    {
        $values = $settings->all();
        if (! $values['storefront_open']) {
            $messages = $values['closed_messages'];
            $message = $messages[app()->getLocale()] ?? $messages[app(LocaleCatalog::class)->defaultCode()] ?? __('settings.closed_default');

            return Inertia::render('StoreClosed', ['applicationName' => config('app.name'), 'message' => $message])->toResponse(request())->setStatusCode(503);
        }

        return Inertia::render('Foundation', ['applicationName' => config('app.name')]);
    }
}
