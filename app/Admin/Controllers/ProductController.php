<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Admin\Requests\SaveProductRequest;
use App\Audit\Domain\Model\AuditEvent;
use App\Audit\Infrastructure\AuditLogger;
use App\Catalog\Application\Command\SaveProductData;
use App\Catalog\Application\Contract\ProductAdministration;
use App\Catalog\Infrastructure\AvailabilityStatuses;
use App\Catalog\Infrastructure\BrandQueries;
use App\Catalog\Infrastructure\FeatureImpactRequired;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Catalog\Infrastructure\ProductCompanions;
use App\Catalog\Infrastructure\ProductFeatures;
use App\Catalog\Infrastructure\ProductFiles;
use App\Catalog\Infrastructure\ProductPackages;
use App\Catalog\Infrastructure\ProductPriceHistory;
use App\Catalog\Infrastructure\ProductQueries;
use App\Catalog\Infrastructure\VariationGroupQueries;
use App\Catalog\Infrastructure\VariationJoinOutcome;
use App\Customer\Infrastructure\CustomerQueries;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Marketing\Infrastructure\StickerQueries;
use App\Media\Infrastructure\Persistence\MediaFileRecord;
use App\Media\Infrastructure\PrivateImageStorage;
use App\Pricing\Application\Service\PriceDisplay;
use App\Settings\Infrastructure\GeneralSettings;
use App\Shared\Application\Command\ImageUpload;
use App\Website\Domain\Service\PageRules;
use App\Website\Infrastructure\FaqQueries;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class ProductController
{
    public function index(Request $request, ProductQueries $queries): Response
    {
        $trash = $request->routeIs('admin.products.trash');
        Gate::authorize($trash ? 'products.trash-view' : 'products.view');
        $categorySearch = $request->validate(['category_search' => ['nullable', 'string', 'max:100']]);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(['active', 'disabled', 'hidden'])], 'type' => ['nullable', Rule::in(['physical', 'digital'])], 'category_id' => ['nullable', 'integer', 'min:1'], 'brand_id' => ['nullable', 'integer', 'min:1'], 'incomplete' => ['nullable', Rule::in(['0', '1'])], 'variation' => ['nullable', Rule::in(['grouped', 'standalone'])], 'stock_action' => ['nullable', Rule::in(['general', 'custom'])], 'per_page' => ['nullable', Rule::in([25, 50, 100])], 'sort' => ['nullable', Rule::in(['name', 'sku', 'type', 'price', 'quantity', 'status', 'updated_at'])], 'direction' => ['nullable', Rule::in(['asc', 'desc'])], 'page' => ['nullable', 'integer', 'min:1', 'max:1000000']]);

        return Inertia::render('Admin/Products/Index', [...$this->shell($request), 'products' => $queries->listing($filters, $trash), 'currency' => $this->currency(), 'filters' => (object) [...$filters, ...$categorySearch], 'brands' => app(BrandQueries::class)->options('', isset($filters['brand_id']) ? [(string) $filters['brand_id']] : []), 'trash' => $trash, 'catalogCount' => ($trash ? ProductRecord::onlyTrashed() : ProductRecord::query())->count(), 'categories' => $queries->categories($categorySearch['category_search'] ?? '', isset($filters['category_id']) ? (string) $filters['category_id'] : null), 'bulkResults' => $request->session()->get('product_bulk_results', [])]);
    }

    public function store(Request $request, ProductAdministration $products): RedirectResponse
    {
        $id = $products->draft((string) $request->user()->id);

        return redirect('/admin/products/'.$id.'/edit?return_to='.urlencode($this->returnTo($request)));
    }

    public function edit(Request $request, ProductQueries $queries, string $product): Response
    {
        Gate::authorize('products.update');
        $p = ProductRecord::findOrFail($product);
        $search = $request->validate(['category_search' => ['nullable', 'string', 'max:100'], 'extra_category_search' => ['nullable', 'string', 'max:100'], 'kind' => ['nullable', Rule::in(['stickers', 'companions', 'brands'])], 'search' => ['nullable', 'string', 'max:100'], 'selected' => ['nullable', 'array', 'max:100'], 'selected.*' => ['integer', 'min:1']]);

        $editor = $queries->editor($p);
        $texts = (array) $editor['translations'];
        $name = $texts[app()->getLocale()]['name'] ?? $texts[app(LocaleCatalog::class)->defaultCode()]['name'] ?? $p->sku;
        if (! $request->header('X-Inertia-Partial-Data')) {
            app(AuditLogger::class)->record(new AuditEvent('product.opened', 'product', $product, entityLabel: $name));
        }

        return Inertia::render('Admin/Products/Form', [...$this->shell($request), 'product' => $editor, 'facebookTypes' => PageRules::FACEBOOK_TYPES, 'options' => fn () => ['brands' => app(BrandQueries::class)->options(($search['kind'] ?? '') === 'brands' ? ($search['search'] ?? '') : '', array_values(array_filter([$editor['brand_id'], ...(($search['kind'] ?? '') === 'brands' ? ($search['selected'] ?? []) : [])]))), 'stickers' => Gate::allows('stickers.view') ? app(StickerQueries::class)->manualOptions($search['search'] ?? '', array_values(array_unique([...$editor['sticker_ids'], ...($search['selected'] ?? [])]))) : []], 'companions' => fn () => app(ProductCompanions::class)->describe(array_values(array_unique([...$editor['companion_ids'], ...array_map(strval(...), ($search['kind'] ?? '') === 'companions' ? ($search['selected'] ?? []) : [])]))), 'companionOptions' => fn () => ($search['kind'] ?? '') === 'companions' ? app(ProductCompanions::class)->search((string) $p->id, $search['search'] ?? '') : [], 'saleCustomerGroups' => app(CustomerQueries::class)->groupOptions(array_values(array_filter(array_column($editor['sales'], 'customer_group_id')))), 'availabilityStatuses' => app(AvailabilityStatuses::class)->options(), 'featureEditor' => app(ProductFeatures::class)->editor($p), 'featureImpact' => $request->session()->get('feature_impact'), 'defaultLocale' => app(LocaleCatalog::class)->defaultCode(), 'returnTo' => $this->returnTo($request), 'categories' => fn () => $queries->categories($search['category_search'] ?? '', $p->category_id === null ? null : (string) $p->category_id), 'extraCategoryOptions' => fn () => $queries->extraCategories($search['extra_category_search'] ?? '', $editor['extra_category_ids']), 'faqOptions' => fn () => Gate::allows('faqs.view') ? app(FaqQueries::class)->options('product', $editor['faq_ids'] ?? []) : [], 'units' => ['weight' => app(GeneralSettings::class)->all()['weight_unit'], 'dimension' => config('products.dimension_unit')], 'currency' => $this->currency(), 'conversions' => $queries->conversions($p), 'priceHistory' => app(ProductPriceHistory::class)->series((string) $p->id), 'customerGroups' => app(CustomerQueries::class)->groupOptions($editor['customer_groups']), 'variation' => app(VariationGroupQueries::class)->editor($p), 'variationNotice' => $request->session()->get('variation_notice'), 'packageAssignment' => ['id' => $packageId = app(ProductPackages::class)->assigned((string) $p->id), 'options' => app(ProductPackages::class)->options(), 'contents' => $packageId === null ? [] : app(ProductCompanions::class)->describe(app(ProductPackages::class)->items($packageId))]]);
    }

    public function update(SaveProductRequest $request, ProductAdministration $products, ProductFiles $files, PrivateImageStorage $images, string $product): RedirectResponse
    {
        $data = $request->validated();
        // Multipart encoding omits empty arrays; this endpoint saves the full gallery/file selection.
        $data['images'] ??= [];
        $data['files'] ??= [];
        if ($request->boolean('sales_submitted')) {
            $data['sales'] ??= [];
        }
        if ($request->boolean('taxes_submitted')) {
            $data['tax_ids'] ??= [];
        }
        if ($request->boolean('customer_groups_submitted')) {
            $data['customer_groups'] ??= [];
        }
        $current = ProductRecord::findOrFail($product);
        if ($current->lock_version !== (int) $data['version']) {
            throw ValidationException::withMessages(['version' => __('products.stale')]);
        }
        $data['staged_files'] = [];
        $data['staged_images'] = [];
        foreach ($request->file('file_uploads', []) as $upload) {
            $data['staged_files'][] = $files->stage($upload);
        }
        foreach ($request->file('image_uploads', []) as $upload) {
            $image = $images->store(new ImageUpload($upload->getRealPath()));
            try {
                $record = MediaFileRecord::create($image);
            } catch (Throwable $e) {
                $images->discard($image['storage_key']);
                throw $e;
            }
            $data['images'][] = (string) $record->id;
            $data['staged_images'][] = (string) $record->id;
        }
        unset($data['image_uploads'], $data['file_uploads']);
        try {
            $products->save((string) $request->user()->id, $product, new SaveProductData((int) $data['version'], $data));
        } catch (Throwable $exception) {
            foreach (MediaFileRecord::whereIn('id', $data['staged_images'])->get() as $record) {
                $record->delete();
                $images->discard($record->storage_key);
            }
            if ($exception instanceof FeatureImpactRequired) {
                return back()->with('feature_impact', ['impact' => $exception->impact, 'token' => $exception->token])->withErrors(['features' => __('features.confirm_impact')]);
            }
            throw $exception;
        }

        $redirect = redirect('/admin/products/'.$product.'/edit?return_to='.urlencode($this->returnTo($request)))->with('status', __('products.saved'));
        // Result of joining the group a "Create variation" product was prepared for.
        $outcome = app(VariationJoinOutcome::class)->outcome;

        return $outcome['status'] === 'none' ? $redirect : $redirect->with('variation_notice', $outcome);
    }

    public function duplicate(Request $request, ProductAdministration $products, string $product): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $id = $products->duplicate((string) $request->user()->id, $product, (int) $data['version']);

        return redirect('/admin/products/'.$id.'/edit?return_to='.urlencode($this->returnTo($request)));
    }

    public function action(Request $request, ProductAdministration $products, string $product): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'action' => ['required', Rule::in(['trash', 'restore', 'purge', 'active', 'disabled', 'hidden'])], 'confirmation' => ['nullable', 'string', 'max:250']]);
        $products->lifecycle((string) $request->user()->id, $product, (int) $data['version'], $data['action'], $data['confirmation'] ?? '');

        return redirect($this->returnTo($request))->with('status', __('products.saved'));
    }

    public function bulk(Request $request, ProductAdministration $products): RedirectResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:100'], 'ids.*' => ['required', 'integer', 'min:1', 'distinct'], 'status' => ['required', Rule::in(['active', 'disabled', 'hidden'])]]);
        $results = $products->bulk((string) $request->user()->id, array_map(strval(...), $data['ids']), $data['status']);

        return redirect($this->returnTo($request))->with('product_bulk_results', $results);
    }

    public function image(string $media, PrivateImageStorage $images): StreamedResponse
    {
        abort_unless(Gate::any(['products.view', 'products.update', 'products.trash-view']), 403);

        abort_unless(DB::table('product_images')->where('media_file_id', $media)->exists(), 404);

        return $images->response(MediaFileRecord::findOrFail($media));
    }

    /** @return array{code: string, name: string, symbol: string, decimals: int} */
    private function currency(): array
    {
        $primary = app(PriceDisplay::class)->primary();

        return ['code' => $primary->code, 'name' => $primary->name, 'symbol' => $primary->symbol, 'decimals' => $primary->decimals];
    }

    private function returnTo(Request $request): string
    {
        $return = $request->input('return_to', '/admin/products');

        return is_string($return) && preg_match('~^/admin/products(?:/trash)?(?:\?[^\r\n#]*)?$~D', $return) ? $return : '/admin/products';
    }

    /** @return array<string, mixed> */
    private function shell(Request $request): array
    {
        return ['navigation' => app(AdminNavigation::class)->forUser($request->user()), 'breadcrumbs' => [['label' => __('navigation.dashboard'), 'href' => '/admin'], ['label' => __('products.title'), 'href' => '/admin/products']]];
    }
}
