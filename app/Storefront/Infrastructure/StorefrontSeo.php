<?php

namespace App\Storefront\Infrastructure;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

/** SEO is a projection of resolved storefront content, never a second catalog/pricing query. */
final class StorefrontSeo
{
    /** @param array<string,mixed> $page
     * @return array<string,mixed> */
    public function present(array $page): array
    {
        $settings = View::shared('storefrontSeo', []);
        $settings['indexable'] = ($settings['indexable'] ?? true) && ! SearchEngineVisibility::storefrontIndexingDisabled(request());
        $seo = $this->build($page, $settings, request(), app(StoreBrand::class)->all());
        View::share('storefrontSeo', $seo);

        return $seo;
    }

    /** @param array<string,mixed> $page
     * @param array<string,mixed> $seo
     * @param array<string,mixed> $brand
     * @return array<string,mixed> */
    public function build(array $page, array $seo, Request $request, array $brand): array
    {
        $main = $this->main((array) ($page['regions'] ?? []));
        $listing = $main['listing'] ?? null;
        $entity = $main['product'] ?? $listing['category'] ?? $listing['brand'] ?? $main['category'] ?? $main['page'] ?? $main['post'] ?? $main['blog'] ?? null;
        $private = SearchEngineVisibility::applies($request) || $request->is('checkout', 'checkout/*', 'order-status', 'search', 'search/*') || isset($main['age_gate']) || ($entity['age_gate'] ?? null) !== null || isset($page['frame']);
        $preview = $request->query('preview') !== null;
        $indexable = (bool) ($seo['indexable'] ?? true) && ! $private && ! $preview;
        $base = rtrim((string) config('app.url'), '/');
        $canonical = $seo['canonical'] ?? $base.$request->getPathInfo();
        // Canonical origins are deployment configuration, never the request Host header.
        $canonical = $this->absolute($canonical, $base);
        $title = trim((string) ($seo['title'] ?? '')) ?: ($entity['name'] ?? $brand['name']);
        $number = (int) ($listing['page'] ?? $main['blog']['page'] ?? 1);
        if ($number > 1) {
            $title .= ' – '.__('storefront.page_number', ['page' => $number]);
        }
        if ($listing !== null && $indexable) {
            // Listings clamp out-of-range requests; canonicalize to the page actually displayed.
            $canonical = $this->absolute($base.$request->getPathInfo().($number > 1 ? '?page='.$number : ''), $base);
        }
        $description = trim((string) ($seo['description'] ?? ''));
        if ($description === '') {
            $description = $this->plain($entity['short_description'] ?? $entity['description'] ?? $entity['content'] ?? '');
        }
        if ($description === '' && $entity !== null) {
            $description = $this->plain($entity['name'] ?? $title);
        }
        if ($description === '') {
            $description = $this->plain($request->getPathInfo() === '/' ? $brand['name'] : $title);
        }
        $image = $entity['images'][0]['url'] ?? $entity['cover']['url'] ?? $entity['banner']['url'] ?? $entity['image']['url'] ?? $brand['logo'] ?? null;
        $image = $image === null ? null : $this->absolute($image, $base);
        $og = ['type' => isset($main['post']) ? 'article' : 'website', ...($seo['og'] ?? []), 'title' => $title, 'description' => $description, 'url' => $canonical, 'site_name' => $brand['name'], 'locale' => str_replace('-', '_', app()->getLocale())];
        if ($image !== null) {
            $og['image'] = $image;
        } else {
            unset($og['image']);
        }
        $graph = [];
        if ($indexable && $canonical !== null) {
            if (isset($main['product'])) {
                $graph[] = $this->product($main['product'], $canonical, $base);
            } else {
                $type = isset($main['post']) ? 'BlogPosting' : (($listing !== null || isset($main['category']) || isset($main['blog']) || isset($main['directory'])) ? 'CollectionPage' : 'WebPage');
                $node = ['@type' => $type, '@id' => $canonical.'#page', 'url' => $canonical, 'name' => $entity['name'] ?? $title, 'inLanguage' => app()->getLocale()];
                if ($description !== '') {
                    $node['description'] = $description;
                }
                if (isset($main['post'])) {
                    $node['headline'] = $main['post']['name'];
                    if (($main['post']['published_at'] ?? null) !== null) {
                        $node['datePublished'] = $main['post']['published_at'];
                    }
                    if ($image !== null) {
                        $node['image'] = $image;
                    }
                }
                $items = $listing['items'] ?? $main['category']['items'] ?? $main['blog']['posts'] ?? [];
                $list = [];
                foreach ($items as $item) {
                    $url = isset($item['url']) ? $this->absolute($item['url'], $base) : null;
                    if ($url !== null && ($item['name'] ?? '') !== '') {
                        $list[] = ['@type' => 'ListItem', 'position' => count($list) + 1, 'name' => $item['name'], 'url' => $url];
                    }
                }
                if ($list !== []) {
                    $node['mainEntity'] = ['@type' => 'ItemList', 'itemListElement' => $list];
                }
                $graph[] = $node;
            }
            $trail = $entity['category']['path'] ?? $entity['path'] ?? [];
            if (isset($main['post'])) {
                $trail = [$main['post']['blog']];
            }
            if ($request->getPathInfo() !== '/') {
                $crumbs = [['name' => __('storefront.home'), 'url' => $base.'/'], ...$trail];
                if (($crumbs[count($crumbs) - 1]['url'] ?? null) !== ($entity['url'] ?? $request->getPathInfo())) {
                    $crumbs[] = ['name' => $entity['name'] ?? $title, 'url' => $canonical];
                }
                $crumbs = array_values(array_filter($crumbs, fn (array $c): bool => ($c['name'] ?? '') !== '' && $this->absolute($c['url'] ?? '', $base) !== null));
                $graph[] = ['@type' => 'BreadcrumbList', '@id' => $canonical.'#breadcrumbs', 'itemListElement' => array_map(fn (array $c, int $i): array => ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $c['name'], 'item' => $this->absolute($c['url'], $base)], $crumbs, array_keys($crumbs))];
            } else {
                $organization = ['@type' => 'Organization', '@id' => $base.'/#organization', 'name' => $brand['name'], 'url' => $base.'/'];
                if (($brand['logo'] ?? null) !== null) {
                    $organization['logo'] = $this->absolute($brand['logo'], $base);
                }
                $graph[] = $organization;
                $graph[] = ['@type' => 'WebSite', '@id' => $base.'/#website', 'url' => $base.'/', 'name' => $brand['name'], 'publisher' => ['@id' => $base.'/#organization'], 'inLanguage' => app()->getLocale()];
            }
        }
        $result = [...$seo, 'title' => $title, 'description' => $description, 'canonical' => $canonical, 'indexable' => $indexable, 'og' => $og, 'graph' => $graph];
        $result['head'] = $this->head($result);

        return $result;
    }

    /** @param array<string,mixed> $product
     * @return array<string,mixed> */
    private function product(array $product, string $url, string $base): array
    {
        $node = ['@type' => 'Product', '@id' => $url.'#product', 'name' => $product['name'], 'url' => $url];
        $description = $this->plain(($product['short_description'] ?? '') ?: ($product['description'] ?? ''));
        if ($description !== '') {
            $node['description'] = $description;
        }
        $images = array_values(array_filter(array_map(fn (array $i): ?string => $this->absolute($i['url'], $base), $product['images'] ?? [])));
        if ($images !== []) {
            $node['image'] = $images;
        }
        if (($product['sku'] ?? '') !== '') {
            $node['sku'] = $product['sku'];
        }
        $brandName = $product['brand_name'] ?? $product['brand']['name'] ?? '';
        if ($brandName !== '') {
            $node['brand'] = ['@type' => 'Brand', 'name' => $brandName];
        }
        if (($product['category']['name'] ?? '') !== '') {
            $node['category'] = $product['category']['name'];
        }
        if (($product['price'] ?? null) !== null) {
            $availability = ! $product['available'] ? 'OutOfStock' : (($product['available_from'] ?? null) !== null ? 'PreOrder' : (($product['availability']['in_stock'] ?? false) ? 'InStock' : 'BackOrder'));
            $node['offers'] = ['@type' => 'Offer', 'url' => $url, 'price' => $product['price'], 'priceCurrency' => $product['currency_format']['code'], 'availability' => 'https://schema.org/'.$availability];
        }
        if (($product['reviews_enabled'] ?? true) && ($product['rating']['count'] ?? 0) > 0 && ($product['rating']['average'] ?? null) !== null) {
            $node['aggregateRating'] = ['@type' => 'AggregateRating', 'ratingValue' => $product['rating']['average'], 'reviewCount' => $product['rating']['count']];
        }

        return $node;
    }

    /** @param array<string,mixed> $grids
     * @return array<string,mixed> */
    private function main(array $grids): array
    {
        foreach ($grids as $nodes) {
            foreach ($nodes as $grid) {
                foreach ($grid['blocks'] ?? [] as $block) {
                    if ($block['template'] === 'main_content') {
                        return $block['content'];
                    }
                }
                $found = $this->main(['children' => $grid['children'] ?? []]);
                if ($found !== []) {
                    return $found;
                }
            }
        }

        return [];
    }

    public function plain(string $html): string
    {
        return Str::limit(trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'))), 160, '…');
    }

    private function absolute(string $url, string $base): ?string
    {
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            $url = $base.$url;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (app()->isProduction() && (in_array($host, ['localhost', 'app', 'nginx', 'host.docker.internal'], true) || ! str_contains($host, '.') || preg_match('/\.(test|local|localhost|invalid)$/D', $host) === 1 || (filter_var($host, FILTER_VALIDATE_IP) && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)))) {
            return null;
        }

        return filter_var($url, FILTER_VALIDATE_URL) && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true) ? $url : null;
    }

    /** @param array<string,mixed> $seo
     * @return list<string> */
    private function head(array $seo): array
    {
        $tags = [];
        $meta = function (string $key, string $attribute, string $name, ?string $value) use (&$tags): void {
            if ($value !== null && $value !== '') {
                $tags[] = '<meta '.$attribute.'="'.e($name).'" content="'.e($value).'" data-inertia="seo-'.$key.'">';
            }
        };
        $meta('description', 'name', 'description', $seo['description']);
        $meta('keywords', 'name', 'keywords', $seo['keywords'] ?? '');
        if (! $seo['indexable']) {
            $meta('robots', 'name', 'robots', 'noindex, nofollow');
        }
        if ($seo['canonical'] !== null) {
            $tags[] = '<link rel="canonical" href="'.e($seo['canonical']).'" data-inertia="seo-canonical">';
        }
        foreach ($seo['og'] as $key => $value) {
            $meta('og-'.$key, 'property', 'og:'.$key, $value);
        }
        $meta('twitter-card', 'name', 'twitter:card', isset($seo['og']['image']) ? 'summary_large_image' : 'summary');
        foreach (['title', 'description', 'image'] as $key) {
            $meta('twitter-'.$key, 'name', 'twitter:'.$key, $seo['og'][$key] ?? null);
        }
        if ($seo['graph'] !== []) {
            $tags[] = '<script type="application/ld+json" data-inertia="seo-schema">'.json_encode(['@context' => 'https://schema.org', '@graph' => $seo['graph']], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).'</script>';
        }

        return $tags;
    }
}
