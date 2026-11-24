<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Catalog\Infrastructure\BrandQueries;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Marketing\Infrastructure\CouponQueries;
use App\Marketing\Infrastructure\ReelQueries;
use App\Media\Infrastructure\Persistence\MediaFileRecord;
use App\Media\Infrastructure\PrivateImageStorage;
use App\Shared\Application\Command\ImageUpload;
use App\Storefront\Application\Service\StorefrontRegistry;
use App\Storefront\Infrastructure\LayoutSettings;
use App\Storefront\Infrastructure\LayoutStore;
use App\Storefront\Infrastructure\StorefrontDeals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class LayoutController
{
    public function edit(Request $request, LayoutStore $store, StorefrontRegistry $registry, string $page = 'homepage'): Response
    {
        Gate::authorize('layouts.view');
        $definition = $registry->definition($page) ?? abort(404);
        $input = $request->validate(['kind' => ['nullable', Rule::in(array_keys(LayoutStore::SOURCES))], 'brand_id' => ['nullable', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:100'], 'selected' => ['nullable', 'array', 'max:100'], 'selected.*' => ['integer', 'min:1']]);
        $layout = $store->read($page);
        $shared = $definition['inherits'] ? $store->read('default') : null;
        $settings = app(LayoutSettings::class);
        $definitions = [];
        foreach ($registry->blocks() as $id => $type) {
            $definitions[$id] = [...$type->definition(), 'canManage' => Gate::allows($type->definition()['ability'] ?? 'layouts.manage')];
        }

        return Inertia::render('Admin/Layouts/Edit', [
            'navigation' => app(AdminNavigation::class)->forUser($request->user()),
            'breadcrumbs' => [['label' => __('navigation.dashboard'), 'href' => '/admin'], ['label' => __('layouts.title'), 'href' => null]],
            'layout' => $layout, 'sharedLayout' => $shared, 'definitions' => $definitions,
            'tabs' => $settings->tabs(), 'pageDefinition' => ['regions' => $definition['regions'], 'custom' => (bool) ($definition['custom'] ?? false)], 'pageKind' => $registry->contentPage($page),
            'pageSettings' => $settings->settings($page), 'pageOptions' => $settings->pageOptions(), 'theme' => $registry->activeTheme(config('storefront.theme')),
            'defaultLocale' => app(LocaleCatalog::class)->defaultCode(),
            'options' => fn () => $this->options($input, $layout, $shared),
        ]);
    }

    /** @param array<string,mixed> $input
     * @param array<string,mixed> $layout
     * @param array<string,mixed>|null $shared
     * @return array<string,list<array{id:string,name:string}>> */
    private function options(array $input, array $layout, ?array $shared): array
    {
        $options = [];
        $blocks = [...array_column($layout['placements'], 'block'), ...array_filter(array_column($layout['overrides'], 'block')), ...array_column($shared['placements'] ?? [], 'block')];
        $registry = app(StorefrontRegistry::class);
        foreach (LayoutStore::SOURCES as $source => $column) {
            $selected = ($input['kind'] ?? '') === $source ? ($input['selected'] ?? []) : [];
            foreach ($blocks as $b) {
                foreach ($registry->type($b['type'])?->definition()['fields'] ?? [] as $field) {
                    if (($field['source'] ?? '') === $source) {
                        $selected = [...$selected, ...($b['selections'][$field['key']] ?? [])];
                    }
                }
            }
            $selected = array_unique($selected);
            $search = ($input['kind'] ?? '') === $source ? ($input['search'] ?? '') : '';
            if ($source === 'reels') {
                $options[$source] = app(ReelQueries::class)->options($search, array_map(strval(...), $selected));

                continue;
            }
            if ($source === 'brands') {
                $options[$source] = app(BrandQueries::class)->options($search, array_map(strval(...), $selected));

                continue;
            }
            if ($source === 'products') {
                if (isset($input['brand_id'])) {
                    $options[$source] = app(BrandQueries::class)->productOptions((string) $input['brand_id'], $search, array_map(strval(...), $selected));

                    continue;
                }
                $baseOptions = app(CouponQueries::class)->options('product', $search, $selected);
                $owners = DB::table('products')->whereIn('id', array_column($baseOptions, 'id'))->pluck('brand_id', 'id');
                $options[$source] = array_map(fn ($p) => [...$p, 'brand_id' => isset($owners[$p['id']]) ? (string) $owners[$p['id']] : null], $baseOptions);

                continue;
            }
            if (in_array($source, ['coupons', 'promotions'], true)) {
                $options[$source] = app(StorefrontDeals::class)->options($source, $search, $selected);

                continue;
            }
            if ($source === 'search_terms') {
                // The most searched first; a search narrows the list.
                $q = DB::table('search_terms')->select('id', 'term as name');
                $chosen = (clone $q)->whereIn('id', $selected)->get();
                if ($search !== '') {
                    $q->where('term', 'ilike', '%'.addcslashes(mb_strtolower($search), '\\%_').'%');
                }
                $options[$source] = $chosen->concat($q->orderByDesc('searches')->orderBy('id')->limit(30)->get())->unique('id')->map(fn ($r) => ['id' => (string) $r->id, 'name' => $r->name])->values()->all();

                continue;
            }
            if ($source === 'categories') {
                $options[$source] = app(CouponQueries::class)->options('category', $search, $selected);

                continue;
            }
            $table = isset(LayoutStore::MENU_STYLES[$source]) ? 'website_menus' : $source;
            if ($source === 'newsletter_lists') {
                $default = app(LocaleCatalog::class)->active()->firstWhere('is_default', true)->id;
                $current = app(LocaleCatalog::class)->active()->firstWhere('code', app()->getLocale())->id ?? $default;
                $q = DB::table('newsletter_lists')->leftJoin('newsletter_list_translations as c', fn ($j) => $j->on('c.list_id', 'newsletter_lists.id')->where('c.locale_id', $current))->leftJoin('newsletter_list_translations as d', fn ($j) => $j->on('d.list_id', 'newsletter_lists.id')->where('d.locale_id', $default))->where('newsletter_lists.status', 'active')->selectRaw("newsletter_lists.id, coalesce(c.name, d.name, '') as name");
            } elseif (isset(LayoutStore::MENU_STYLES[$source])) {
                $default = app(LocaleCatalog::class)->active()->firstWhere('is_default', true)->id;
                $current = app(LocaleCatalog::class)->active()->firstWhere('code', app()->getLocale())->id ?? $default;
                $q = DB::table('website_menus')->leftJoin('website_menu_translations as c', fn ($j) => $j->on('c.menu_id', 'website_menus.id')->where('c.locale_id', $current))->leftJoin('website_menu_translations as d', fn ($j) => $j->on('d.menu_id', 'website_menus.id')->where('d.locale_id', $default))->where('website_menus.style', LayoutStore::MENU_STYLES[$source])->selectRaw("website_menus.id, coalesce(c.name, d.name, '') as name");
            } elseif ($source === 'pages') {
                $default = app(LocaleCatalog::class)->active()->firstWhere('is_default', true)->id;
                $current = app(LocaleCatalog::class)->active()->firstWhere('code', app()->getLocale())->id ?? $default;
                $q = DB::table('pages')->leftJoin('page_translations as c', fn ($j) => $j->on('c.page_id', 'pages.id')->where('c.locale_id', $current))->leftJoin('page_translations as d', fn ($j) => $j->on('d.page_id', 'pages.id')->where('d.locale_id', $default))->selectRaw("pages.id, coalesce(c.name, d.name, '') as name");
            } elseif ($source === 'blogs' || $source === 'blog_posts') {
                [$texts, $owner] = $source === 'blogs' ? ['blog_translations', 'blog_id'] : ['blog_post_translations', 'post_id'];
                $default = app(LocaleCatalog::class)->active()->firstWhere('is_default', true)->id;
                $current = app(LocaleCatalog::class)->active()->firstWhere('code', app()->getLocale())->id ?? $default;
                $q = DB::table($source)->leftJoin($texts.' as c', fn ($j) => $j->on('c.'.$owner, $source.'.id')->where('c.locale_id', $current))->leftJoin($texts.' as d', fn ($j) => $j->on('d.'.$owner, $source.'.id')->where('d.locale_id', $default))->selectRaw("$source.id, coalesce(c.name, d.name, '') as name");
            } elseif (in_array($source, ['banner_groups', 'variation_groups'], true)) {
                $q = DB::table($source)->select('id', 'name');
            } else {
                $default = app(LocaleCatalog::class)->active()->firstWhere('is_default', true)->id;
                $current = app(LocaleCatalog::class)->active()->firstWhere('code', app()->getLocale())->id ?? $default;
                $q = DB::table('banners')->leftJoin('banner_translations as c', fn ($j) => $j->on('c.banner_id', 'banners.id')->where('c.locale_id', $current))->leftJoin('banner_translations as d', fn ($j) => $j->on('d.banner_id', 'banners.id')->where('d.locale_id', $default))->selectRaw("banners.id, coalesce(c.name,d.name,'') as name");
            }
            $chosen = (clone $q)->whereIn($table.'.id', $selected)->get();
            if ($search !== '') {
                $like = '%'.addcslashes($search, '\\%_').'%';
                in_array($source, ['banner_groups', 'variation_groups'], true) ? $q->where('name', 'ilike', $like) : $q->where(fn ($q) => $q->where('c.name', 'ilike', $like)->orWhere('d.name', 'ilike', $like));
            }
            $options[$source] = $chosen->concat($q->orderBy($table.'.id')->limit(30)->get())->unique('id')->map(fn ($r) => ['id' => (string) $r->id, 'name' => $r->name])->values()->all();
        }

        return $options;
    }

    /** A picture for a block (product carousels); the block refers to it when the layout is saved. */
    public function upload(Request $request): JsonResponse
    {
        Gate::authorize('layouts.manage');
        $request->validate(['image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']]);
        try {
            $stored = app(PrivateImageStorage::class)->store(new ImageUpload($request->file('image')->getPathname()));
        } catch (ValidationException) {
            throw ValidationException::withMessages(['image' => __('layouts.side_image_invalid')]);
        }
        $id = (string) MediaFileRecord::create($stored)->id;
        // shortcut: a picture uploaded but never saved with a block stays stored; prune unreferenced media if it adds up.
        $request->session()->put('layouts.uploads', array_slice([...$request->session()->get('layouts.uploads', []), $id], -50));

        return response()->json(['id' => $id]);
    }

    /** Shows a block picture in the editor: one a block uses, or one this editor just uploaded. */
    public function media(Request $request, string $media): StreamedResponse
    {
        Gate::authorize('layouts.manage');
        abort_unless(in_array($media, $request->session()->get('layouts.uploads', []), true) || LayoutStore::shownImage($media), 404);
        $file = DB::table('media_files')->where('id', $media)->first();
        abort_unless($file !== null && $file->disk === 'local' && in_array($file->mime_type, ['image/png', 'image/jpeg', 'image/webp'], true) && Storage::disk('local')->exists($file->storage_key), 404);

        return Storage::disk('local')->response($file->storage_key, 'image', ['Content-Type' => $file->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function save(Request $request, LayoutStore $store, string $page): RedirectResponse
    {
        $store->save((string) $request->user()->getAuthIdentifier(), $page, $request->all());

        return redirect('/admin/layouts/'.$page)->with('status', __('layouts.saved'));
    }

    public function store(Request $request, LayoutSettings $settings): RedirectResponse
    {
        $key = $settings->create((string) $request->user()->getAuthIdentifier(), $request->all());

        return redirect('/admin/layouts/'.$key)->with('status', __('layouts.created'));
    }

    public function settings(Request $request, LayoutSettings $settings, string $page): RedirectResponse
    {
        $settings->save((string) $request->user()->getAuthIdentifier(), $page, $request->all());

        return redirect('/admin/layouts/'.$page)->with('status', __('layouts.settings_saved'));
    }

    public function destroy(Request $request, LayoutSettings $settings, string $page): RedirectResponse
    {
        $settings->delete((string) $request->user()->getAuthIdentifier(), $page, (int) $request->input('version'));

        return redirect('/admin/layouts/homepage')->with('status', __('layouts.deleted'));
    }
}
