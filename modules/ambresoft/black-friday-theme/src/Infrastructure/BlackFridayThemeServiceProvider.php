<?php

namespace Ambresoft\BlackFridayTheme\Infrastructure;

use App\Storefront\Application\Service\StorefrontRegistry;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class BlackFridayThemeServiceProvider extends ServiceProvider
{
    public function boot(StorefrontRegistry $storefront): void
    {
        $css = file_get_contents(__DIR__.'/../../resources/theme.css');
        if ($css === false) {
            throw new RuntimeException('Black Friday Theme stylesheet is missing.');
        }
        $storefront->stylesheet('ambresoft/black-friday-theme', $css);
        $storefront->documentPalette('ambresoft/black-friday-theme', 'dark', '#12151b');
    }
}
