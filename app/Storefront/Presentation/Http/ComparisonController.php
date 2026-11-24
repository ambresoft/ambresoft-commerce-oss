<?php

namespace App\Storefront\Presentation\Http;

use App\Storefront\Infrastructure\LayoutRenderer;
use App\Storefront\Infrastructure\LayoutSettings;
use App\Storefront\Infrastructure\ProductComparison;
use App\Storefront\Infrastructure\StorefrontSeo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The product comparison (/comparison) for guests and customers: the Comparison layout, whose protected main content
 * block shows the compared products, and the small JSON calls that change the list. Everything is a 404 while the
 * store setting turns comparison off.
 */
final class ComparisonController
{
    public function show(Request $request, LayoutRenderer $renderer, ProductComparison $comparison): Response
    {
        abort_unless($comparison->enabled(), 404);
        if (($closed = StoreClosed::response($request)) !== null) {
            return $closed;
        }
        $locale = app()->getLocale();
        $customer = $this->customer($request);
        $data = $comparison->page(['locale' => $locale, 'customer' => $customer]);
        $seo = app(LayoutSettings::class)->seo('comparison', $locale, ['title' => __('storefront.comparison_title')]);
        // The list belongs to one visitor's session: never indexed, whatever the layout setting says.
        View::share('storefrontSeo', [...$seo, 'indexable' => false]);
        $page = $renderer->page('comparison', $locale, $customer, '/comparison', ['comparison' => $data]);
        $response = Inertia::render('Storefront/Comparison', ['storefront' => $page, 'seo' => app(StorefrontSeo::class)->present($page)])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function add(Request $request, ProductComparison $comparison, string $product): JsonResponse
    {
        abort_unless($comparison->enabled(), 404);

        return $this->reply($comparison, $comparison->add($product, $this->customer($request)));
    }

    public function remove(ProductComparison $comparison, string $product): JsonResponse
    {
        abort_unless($comparison->enabled(), 404);

        return $this->reply($comparison, $comparison->remove($product));
    }

    public function clear(ProductComparison $comparison): JsonResponse
    {
        abort_unless($comparison->enabled(), 404);
        $comparison->clear();

        return $this->reply($comparison, []);
    }

    /** @param list<string> $ids */
    private function reply(ProductComparison $comparison, array $ids): JsonResponse
    {
        return response()->json(['ids' => $ids, 'max' => $comparison->max()])->header('Cache-Control', 'private, no-store');
    }

    private function customer(Request $request): ?string
    {
        $id = $request->user() === null ? null : DB::table('customers')->where('user_id', $request->user()->getAuthIdentifier())->where('status', 'active')->value('id');

        return $id === null ? null : (string) $id;
    }
}
