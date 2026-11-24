<?php

namespace Ambresoft\Sitemap\Infrastructure;

use Ambresoft\Sitemap\Infrastructure\Provider\BlogsProvider;
use Ambresoft\Sitemap\Infrastructure\Provider\BrandsProvider;
use Ambresoft\Sitemap\Infrastructure\Provider\CategoriesProvider;
use Ambresoft\Sitemap\Infrastructure\Provider\HomepageProvider;
use Ambresoft\Sitemap\Infrastructure\Provider\PagesProvider;
use Ambresoft\Sitemap\Infrastructure\Provider\PostsProvider;
use Ambresoft\Sitemap\Infrastructure\Provider\ProductsProvider;
use Ambresoft\Sitemap\Infrastructure\Provider\StorefrontProvider;
use App\Storefront\Application\Contract\StorefrontUrls;
use InvalidArgumentException;

final class Providers
{
    public const SECTIONS = ['homepage', 'products', 'categories', 'pages', 'brands', 'blogs', 'posts'];

    /** @var array<string,StorefrontProvider> */
    private array $providers = [];

    public function __construct(StorefrontUrls $source)
    {
        foreach ([HomepageProvider::class, ProductsProvider::class, CategoriesProvider::class, PagesProvider::class, BrandsProvider::class, BlogsProvider::class, PostsProvider::class] as $class) {
            $provider = new $class($source);
            $this->providers[$provider->section()] = $provider;
        }
    }

    public function get(string $section): StorefrontProvider
    {
        return $this->providers[$section] ?? throw new InvalidArgumentException('Unknown sitemap section.');
    }
}
