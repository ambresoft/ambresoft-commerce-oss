<?php

namespace App\Storefront\Infrastructure;

use App\Shared\Infrastructure\Modules\AddonRuntime;
use App\Storefront\Application\Service\StorefrontRegistry;

final class StorefrontDocumentPalette
{
    /** Last enabled contribution wins, following stylesheet registration order.
     * @return array{scheme: string, background: string}|null */
    public function current(): ?array
    {
        if (request()->is('admin', 'admin/*')) {
            return null;
        }
        $palette = null;
        $runtime = app(AddonRuntime::class);
        foreach (app(StorefrontRegistry::class)->documentPalettes() as $addon => $candidate) {
            if ($runtime->isLoaded($addon) && $runtime->isEnabled($addon)) {
                $palette = $candidate;
            }
        }

        return $palette;
    }
}
