<?php

namespace App\Storefront\Presentation\Http;

use App\Catalog\Infrastructure\PublicCatalog;
use App\Settings\Infrastructure\GeneralSettings;
use App\Storefront\Infrastructure\BlockContent;
use App\Storefront\Infrastructure\CategoryListing;
use App\Storefront\Infrastructure\CurrentCart;
use App\Storefront\Infrastructure\LayoutRenderer;
use App\Storefront\Infrastructure\LayoutSettings;
use App\Storefront\Infrastructure\ProductSearch;
use App\Storefront\Infrastructure\RecentlyViewedProducts;
use App\Storefront\Infrastructure\StorefrontAccess;
use App\Storefront\Infrastructure\StorefrontSeo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Product search: the results page (/search, the Search layout with the listing's filters beside the protected main
 * content block) and the header's suggestions (/search/suggest, JSON). Both read only validated parameters; neither
 * exposes the search engine, its keys or its documents. Results pages are never indexed by search engines.
 */
final class SearchController
{
    public function __construct(private ProductSearch $search, private CurrentCart $current) {}

    public function show(Request $request, LayoutRenderer $renderer): Response
    {
        if (($closed = StoreClosed::response($request)) !== null) {
            return $closed;
        }
        $locale = app()->getLocale();
        $customer = $this->current->customer($request);
        $listings = app(CategoryListing::class);
        $filters = $listings->filterSettings('search');
        $offered = ['delivery' => (bool) ($filters['show_delivery'] ?? false), 'rating' => (bool) ($filters['show_rating'] ?? false) && app(GeneralSettings::class)->all()['reviews_enabled'], 'stock' => (bool) ($filters['show_stock'] ?? false)];
        $listing = $this->search->listing($request->query(), ['locale' => $locale, 'customer' => $customer], $offered, $listings->blockSettings('search', 'main_content'));
        $text = $listing['page']['search']['query'];
        $title = $text === '' ? __('storefront.search_title') : __('storefront.search_results_for', ['query' => $text]);
        $seo = app(LayoutSettings::class)->seo('search', $locale, ['title' => $title]);
        View::share('storefrontSeo', [...$seo, 'indexable' => false]);
        $page = $renderer->page('search', $locale, $customer, '/search', ['listing' => $listing]);
        $response = Inertia::render('Storefront/Category', ['storefront' => $page, 'seo' => app(StorefrontSeo::class)->present($page)])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        // An unavailable search engine is a temporary failure, not a page without results.
        if (in_array($listing['page']['search']['state'], ['unavailable', 'not_ready'], true) && ! $request->header('X-Inertia')) {
            $response->setStatusCode(503);
            $response->headers->set('Retry-After', '60');
        }

        return $response;
    }

    /** A visitor's own history, without depending on the search engine or recording another view. */
    public function recentlyViewed(Request $request, RecentlyViewedProducts $history, BlockContent $content): JsonResponse
    {
        $items = [];
        $ids = $history->ids($request);
        if ($ids !== [] && app(StorefrontAccess::class)->allowed(request())) {
            $context = ['locale' => app()->getLocale(), 'customer' => $this->current->customer($request)];
            $cards = $content->productCards($ids, $context, app(PublicCatalog::class)->listed($context['customer'], 'search'), 'search')['cards'];
            foreach ($ids as $id) {
                if (! empty($cards[$id]['url'])) {
                    $items[] = array_intersect_key($cards[$id], array_flip(['id', 'name', 'url', 'image']));
                }
            }
        }

        return response()->json(['items' => $items])->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function suggest(Request $request): JsonResponse
    {
        $text = $this->search->text($request->query('q'));
        $limit = is_string($request->query('limit')) && preg_match('/^(10|[1-9])$/D', $request->query('limit')) === 1 ? (int) $request->query('limit') : 6;
        $result = $this->search->suggest($text, ['locale' => app()->getLocale(), 'customer' => $this->current->customer($request)], $limit);

        return response()->json(['query' => $text, ...$result], $result['state'] === 'unavailable' ? 503 : 200)
            ->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
