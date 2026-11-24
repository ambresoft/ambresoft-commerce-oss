<?php

namespace App\Storefront\Presentation\Http;

use App\Catalog\Domain\Service\ProductRules;
use App\Catalog\Infrastructure\ProductRatings;
use App\Settings\Infrastructure\GeneralSettings;
use App\Storefront\Infrastructure\AgeGates;
use App\Storefront\Infrastructure\CatalogLandingPages;
use App\Storefront\Infrastructure\CategoryLanding;
use App\Storefront\Infrastructure\CategoryListing;
use App\Storefront\Infrastructure\LayoutRenderer;
use App\Storefront\Infrastructure\LayoutSettings;
use App\Storefront\Infrastructure\ProductPage;
use App\Storefront\Infrastructure\RecentlyViewedProducts;
use App\Storefront\Infrastructure\StorefrontBlogs;
use App\Storefront\Infrastructure\StorefrontPages;
use App\Storefront\Infrastructure\StorefrontSeo;
use App\Storefront\Infrastructure\StorefrontWidgets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Storefront product pages at their category path plus slug (e.g. /phones-tablets/smartphones/iphone-15). The page
 * uses the Products layout; its protected main content block shows the product. A landing page category's own path
 * (e.g. /phones-tablets) shows its subcategories with the Category landing layout; any other category's path
 * (e.g. /phones-tablets/smartphones) lists its products with the Category layout.
 */
final class ProductPageController
{
    public function __invoke(Request $request, LayoutRenderer $renderer, ProductPage $pages): Response
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            abort(404);
        }
        abort_if($request->is('checkout', 'checkout/*') && ! (bool) app(GeneralSettings::class)->all()['cart_enabled'], 404);
        if (($closed = StoreClosed::response($request)) !== null) {
            return $closed;
        }
        $locale = app()->getLocale();
        $customer = $request->user() === null ? null : DB::table('customers')->where('user_id', $request->user()->getAuthIdentifier())->where('status', 'active')->value('id');
        $customer = $customer === null ? null : (string) $customer;
        $path = '/'.$request->path();
        if (($category = app(CategoryLanding::class)->resolve($path, $locale, $customer)) !== null) {
            return $this->category($request, $renderer, $category, $locale, $customer, true);
        }
        if (($category = app(CategoryLanding::class)->resolve($path, $locale, $customer, false)) !== null) {
            return $this->category($request, $renderer, $category, $locale, $customer, false);
        }
        // Static pages: `?preview=1` lets staff who may view pages see one whatever its state.
        $preview = $request->boolean('preview') && $request->user()?->can('pages.view') === true;
        if (($found = app(StorefrontPages::class)->resolve($path, $customer, $preview)) !== null) {
            return $this->staticPage($request, $renderer, $found, $locale, $customer, $preview);
        }
        $blogPreview = $request->boolean('preview') && $request->user()?->can('blog.view') === true;
        if (($found = app(StorefrontBlogs::class)->resolve($path, $blogPreview)) !== null) {
            return $this->blog($request, $renderer, $found, $locale, $customer, $blogPreview);
        }
        if (($landing = app(CatalogLandingPages::class)->resolve($path, $locale)) !== null) {
            // A brand (or legacy feature option) page lists that brand's products with filters, under its picture and texts.
            $landing['seo']['description'] = ($landing['seo']['description'] ?? '') ?: (app(StorefrontSeo::class)->plain($landing['description']) ?: $landing['name']);
            $seo = app(LayoutSettings::class)->seo('catalog_landing', $locale, array_filter($landing['seo'], fn (string $text): bool => $text !== ''));
            unset($landing['seo']);
            $scope = $landing['source'] === 'brand' ? ['brand' => $landing['id']] : ['option' => $landing['id'], 'feature' => (string) DB::table('feature_options')->where('id', $landing['id'])->value('feature_id')];

            return $this->respond($request, $renderer, 'catalog_landing', 'Storefront/Page', $seo, $landing['url'], ['brand' => $landing], $scope, false, $locale, $customer);
        }
        if (($frame = app(StorefrontWidgets::class)->page($path)) !== null) {
            return $this->framePage($request, $renderer, $frame, $locale, $customer, $path);
        }
        $found = $pages->resolve($path, $locale, $customer) ?? $this->notFound($path);
        if (isset($found['redirect'])) {
            return redirect($found['redirect'], 301);
        }
        $gate = app(AgeGates::class)->forCategory(DB::table('products')->where('id', $found['product'])->value('category_id'), $locale, AgeVerificationController::confirmed($request));
        if ($gate !== null) {
            return $this->ageGate($request, $renderer, $gate, $locale, $customer, $path);
        }
        $history = app(RecentlyViewedProducts::class);
        $product = $pages->data($found['product'], $locale, $customer, $history->ids($request)) ?? abort(404);
        $history->track($request, $found['product']);
        $texts = DB::table('product_translations as t')->join('locales as l', 'l.id', '=', 't.locale_id')->where('t.product_id', $product['id'])->where('l.code', $locale)->first(['t.seo_title', 't.seo_description', 't.meta_keywords']);
        // The product's own SEO texts win over the Products layout settings.
        $seo = app(LayoutSettings::class)->seo('products', $locale, [
            'title' => ($texts->seo_title ?? '') !== '' ? $texts->seo_title : $product['name'],
            'description' => ($texts->seo_description ?? '') !== '' ? $texts->seo_description : Str::limit(trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($product['short_description'] !== '' ? $product['short_description'] : $product['description'])))), 300, ''),
            'keywords' => $texts->meta_keywords ?? null,
        ]);
        $base = rtrim((string) config('app.url'), '/');
        $image = $product['images'][0]['url'] ?? null;
        View::share('storefrontSeo', [...$seo, 'canonical' => $base.$product['url'], 'og' => [
            'type' => DB::table('products')->where('id', $product['id'])->value('facebook_type') ?: ProductRules::FACEBOOK_TYPE_DEFAULT,
            'title' => $seo['title'], 'description' => $seo['description'], 'url' => $base.$product['url'], 'image' => $image === null ? null : $base.$image,
        ]]);
        // The first picture is requested with the HTML, like the homepage's first banner.
        View::share('storefrontPreloads', isset($product['images'][0]) ? [['href' => $product['images'][0]['url'], 'media' => 'all']] : []);
        $page = $renderer->page('products', $locale, $customer, $path, ['product' => $product]);
        $reviews = app(ProductRatings::class)->approved($product['id'], max(1, (int) $request->query('reviews_page', 1)), 10);
        $response = Inertia::render('Storefront/Product', ['storefront' => $page, 'seo' => app(StorefrontSeo::class)->present($page), 'reviews' => $reviews])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        if (! $seo['indexable']) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }

    /**
     * A product in an age-restricted category before the visitor confirms the age: the Products layout with only the
     * gate in its main content block. Nothing of the product is sent, and search engines do not index the gate.
     *
     * @param  array{age:int,message:string}  $gate
     */
    private function ageGate(Request $request, LayoutRenderer $renderer, array $gate, string $locale, ?string $customer, string $path): Response
    {
        $title = __('storefront.age_gate_title');
        View::share('storefrontSeo', [...app(LayoutSettings::class)->seo('products', $locale, ['title' => $title]), 'indexable' => false]);
        $page = $renderer->page('products', $locale, $customer, $path, ['age_gate' => $gate]);
        $response = Inertia::render('Storefront/Product', ['storefront' => $page, 'seo' => app(StorefrontSeo::class)->present($page)])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    /**
     * A static page in the layout linked to it: the layout's main content block shows the page (title, content,
     * questions) wherever staff placed it. The page's SEO texts win over the layout's.
     *
     * @param  array{page:string,layout:string}  $found
     */
    private function staticPage(Request $request, LayoutRenderer $renderer, array $found, string $locale, ?string $customer, bool $preview): Response
    {
        $data = app(StorefrontPages::class)->data($found['page'], $locale);
        $seo = app(LayoutSettings::class)->seo($found['layout'], $locale, array_filter(['title' => $data['seo']['title'], 'description' => $data['seo']['description'] ?: (app(StorefrontSeo::class)->plain($data['content']) ?: $data['name']), 'keywords' => $data['seo']['keywords']], fn (string $v): bool => $v !== ''));
        View::share('storefrontSeo', [...$seo, 'canonical' => rtrim((string) config('app.url'), '/').$data['url']]);
        $page = $renderer->page($found['layout'], $locale, $customer, $data['url'], ['page' => $data]);
        $response = Inertia::render('Storefront/Page', ['storefront' => $page, 'seo' => app(StorefrontSeo::class)->present($page)])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        if (! $seo['indexable'] || $preview) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }

    /**
     * An add-on's full page: the shared header and navigation around its frame. It is an application, so never indexed.
     *
     * @param  array{addon: string, src: string, title: string}  $frame
     */
    private function framePage(Request $request, LayoutRenderer $renderer, array $frame, string $locale, ?string $customer, string $path): Response
    {
        View::share('storefrontSeo', [...app(LayoutSettings::class)->seo('default', $locale, ['title' => $frame['title']]), 'indexable' => false, 'canonical' => rtrim((string) config('app.url'), '/').$path]);
        $page = $renderer->page('default', $locale, $customer, $path) + ['frame' => $frame];
        $response = Inertia::render('Storefront/Page', ['storefront' => $page, 'seo' => app(StorefrontSeo::class)->present($page)])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    /**
     * A blog (Blog layout: the blog's description and one page of its posts) or one of its posts (Blog post layout).
     * The blog's or post's SEO texts win over the layout's; a post also describes itself for social sharing.
     *
     * @param  array{blog:string,post?:string}  $found
     */
    private function blog(Request $request, LayoutRenderer $renderer, array $found, string $locale, ?string $customer, bool $preview): Response
    {
        $blogs = app(StorefrontBlogs::class);
        $base = rtrim((string) config('app.url'), '/');
        if (isset($found['post'])) {
            $layout = 'blog_post';
            $data = $blogs->post($found['post'], $locale);
            $main = ['post' => $data];
            $canonical = $base.$data['url'];
        } else {
            $number = (string) $request->query('page', '1');
            abort_unless(preg_match('/^[1-9][0-9]{0,5}$/D', $number) === 1, 404);
            $layout = 'blog';
            $data = $blogs->blog($found['blog'], $locale, (int) $number);
            abort_if($data['page'] > $data['last_page'] && $data['page'] > 1, 404);
            $main = ['blog' => $data];
            $canonical = $base.$data['url'].($data['page'] > 1 ? '?page='.$data['page'] : '');
        }
        $data['seo']['description'] = $data['seo']['description'] ?: (app(StorefrontSeo::class)->plain($data['content']) ?: $data['name']);
        $seo = app(LayoutSettings::class)->seo($layout, $locale, array_filter($data['seo'], fn (string $v): bool => $v !== ''));
        $image = $data['cover']['url'] ?? null;
        View::share('storefrontSeo', [...$seo, 'canonical' => $canonical, 'og' => [
            'type' => $data['facebook_type'], 'title' => $data['seo']['title'], 'description' => $seo['description'], 'url' => $canonical, 'image' => $image === null ? null : $base.$image,
        ]]);
        $page = $renderer->page($layout, $locale, $customer, $data['url'], $main);
        $response = Inertia::render('Storefront/Page', ['storefront' => $page, 'seo' => app(StorefrontSeo::class)->present($page)])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        if (! $seo['indexable'] || $preview) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }

    /**
     * A category page with the category's own SEO texts: a landing page shows its subcategories (Category landing
     * layout); any other category lists its products with filters (Category layout).
     */
    private function category(Request $request, LayoutRenderer $renderer, string $id, string $locale, ?string $customer, bool $landing): Response
    {
        $category = app(CategoryLanding::class)->data($id, ['locale' => $locale, 'customer' => $customer, 'age' => AgeVerificationController::confirmed($request)]);
        $layout = $landing ? 'category_landing' : 'category';
        $texts = DB::table('category_translations as t')->join('locales as l', 'l.id', '=', 't.locale_id')->where('t.category_id', $id)->where('l.code', $locale)->first(['t.seo_title', 't.seo_description', 't.meta_keywords']);
        $seo = app(LayoutSettings::class)->seo($layout, $locale, [
            'title' => ($texts->seo_title ?? '') !== '' ? $texts->seo_title : $category['name'],
            'description' => ($texts->seo_description ?? '') !== '' ? $texts->seo_description : (app(StorefrontSeo::class)->plain($category['description']) ?: $category['name']),
            'keywords' => $texts->meta_keywords ?? null,
        ]);

        return $this->respond($request, $renderer, $layout, 'Storefront/Category', $seo, (string) $category['url'], ['category' => $category], $landing ? null : ['category' => $id], $category['age_gate'] !== null, $locale, $customer);
    }

    /**
     * A category or brand page. With a scope, its main content lists the scope's products with filters
     * (CategoryListing); filtered or sorted listings are not indexed and point search engines at the plain address.
     *
     * @param  array<string,mixed>  $seo
     * @param  array<string,mixed>  $page  ['category' => …] or ['brand' => …]: what the page shows above the products.
     * @param  array<string,mixed>|null  $scope
     */
    private function respond(Request $request, LayoutRenderer $renderer, string $layout, string $component, array $seo, string $url, array $page, ?array $scope, bool $gated, string $locale, ?string $customer): Response
    {
        $query = $request->query();
        $pageNumber = is_string($query['page'] ?? null) && preg_match('/^[1-9][0-9]{0,5}$/D', $query['page']) === 1 && $query['page'] !== '1' ? '?page='.$query['page'] : '';
        $filtered = $scope !== null && array_diff_key($query, ['page' => true]) !== [];
        View::share('storefrontSeo', [...$seo, 'indexable' => $seo['indexable'] && ! $filtered && ! $gated, 'canonical' => rtrim((string) config('app.url'), '/').$url.($scope === null || $filtered ? '' : $pageNumber)]);
        $listing = app(CategoryListing::class);
        $main = $scope === null ? $page : ['listing' => $listing->prepare($page, $scope, $query, ['locale' => $locale, 'customer' => $customer], $this->filterGroups($listing->filterSettings($layout)))];
        $storefront = $renderer->page($layout, $locale, $customer, $url, $main);
        $response = Inertia::render($component, ['storefront' => $storefront, 'seo' => app(StorefrontSeo::class)->present($storefront)])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        if (! $seo['indexable'] || $gated || $filtered) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }

    /** Which optional filter groups the layout's filters block offers.
     * @param array<string,mixed> $settings
     * @return array{shopping:bool,delivery:bool,rating:bool,stock:bool} */
    private function filterGroups(array $settings): array
    {
        return ['shopping' => (bool) ($settings['show_shopping'] ?? false), 'delivery' => (bool) ($settings['show_delivery'] ?? false),
            'rating' => (bool) ($settings['show_rating'] ?? false) && app(GeneralSettings::class)->all()['reviews_enabled'], 'stock' => (bool) ($settings['show_stock'] ?? false)];
    }

    /**
     * A store-wide listing: /new (created within the "new products" period), /bestsellers (everything sold, most sold
     * first) or /offers (products on an effective sale), each on its own layout with every filter plus the products'
     * own categories.
     */
    public function collection(Request $request, LayoutRenderer $renderer, string $collection): Response
    {
        if (($closed = StoreClosed::response($request)) !== null) {
            return $closed;
        }
        $locale = app()->getLocale();
        $customer = $request->user() === null ? null : DB::table('customers')->where('user_id', $request->user()->getAuthIdentifier())->where('status', 'active')->value('id');
        $layout = ['new' => 'new_products', 'bestsellers' => 'bestsellers', 'offers' => 'offers'][$collection];
        $title = __('storefront.page_'.$collection);
        $url = '/'.$collection;
        $seo = app(LayoutSettings::class)->seo($layout, $locale, ['title' => $title]);
        $page = ['category' => ['id' => $collection, 'name' => $title, 'description' => '', 'url' => $url, 'path' => [['name' => $title, 'url' => $url]],
            'banner' => null, 'banner_link' => null, 'banner_alt' => '', 'items' => [], 'faqs' => [], 'age_gate' => null]];

        return $this->respond($request, $renderer, $layout, 'Storefront/Category', $seo, $url, $page, ['collection' => $collection], false, $locale, $customer === null ? null : (string) $customer);
    }

    /** Not a product: an address that other routes serve with other methods (for example POST-only) answers 405, as before. */
    private function notFound(string $path): never
    {
        $allowed = [];
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            try {
                if (! app('router')->getRoutes()->match(Request::create($path, $method))->isFallback) {
                    $allowed[] = $method;
                }
            } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
            }
        }
        if ($allowed !== []) {
            throw new MethodNotAllowedHttpException($allowed);
        }
        abort(404);
    }
}
