<?php

namespace App\Storefront\Presentation\Http;

use App\Media\Infrastructure\PrivateImageStorage;
use App\Settings\Infrastructure\CompanySettings;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The storefront favicon: the merchant's upload, or the platform icon until one is uploaded. */
final class FaviconController
{
    public function __invoke(CompanySettings $settings): StreamedResponse|RedirectResponse
    {
        $media = $settings->logoMedia('favicon');
        if ($media === null) {
            return redirect('/favicon-32x32.png');
        }
        $response = app(PrivateImageStorage::class)->response($media);
        $response->headers->set('Cache-Control', 'public, max-age=86400');

        return $response;
    }
}
