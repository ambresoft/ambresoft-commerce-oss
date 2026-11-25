<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Catalog\Infrastructure\CategoryHierarchy;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Media\Infrastructure\Persistence\MediaFileRecord;
use App\Media\Infrastructure\PrivateImageStorage;
use App\Website\Infrastructure\MenuAdministration;
use App\Website\Infrastructure\MenuQueries;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class MenuController
{
    public function index(Request $request, MenuQueries $q): Response
    {
        Gate::authorize('menus.view');
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(['active', 'disabled'])], 'placement' => ['nullable', Rule::in(['header', 'footer', 'other'])], 'source' => ['nullable', Rule::in(['manual', 'categories'])], 'style' => ['nullable', Rule::in(['standard', 'link_groups'])], 'page' => ['nullable', 'integer', 'min:1', 'max:1000000']]);

        return Inertia::render('Admin/Menus/Index', [...$this->shell($request), 'menus' => $q->listing($filters), 'filters' => (object) $filters]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('menus.manage');

        return Inertia::render('Admin/Menus/Form', [...$this->shell($request), 'menu' => null, 'defaultLocale' => app(LocaleCatalog::class)->defaultCode()]);
    }

    public function edit(Request $request, MenuQueries $q, string $menu): Response
    {
        Gate::authorize('menus.view');

        return Inertia::render('Admin/Menus/Form', [...$this->shell($request), 'menu' => $q->editor($menu), 'defaultLocale' => app(LocaleCatalog::class)->defaultCode()]);
    }

    public function save(Request $request, MenuAdministration $menus, ?string $menu = null): RedirectResponse
    {
        $id = $menus->save((string) $request->user()->id, $menu, $request->all());

        return redirect('/admin/menus/'.$id)->with('status', __('menus.saved'));
    }

    public function show(Request $request, MenuQueries $q, string $menu): Response
    {
        Gate::authorize('menus.view');
        $v = $request->validate(['parent' => ['nullable', 'integer', 'min:1'], 'category_parent' => ['nullable', 'integer', 'min:1'], 'generated_from' => ['nullable', 'integer', 'min:1'], 'page' => ['nullable', 'integer', 'min:1', 'max:1000000']]);
        $owner = $q->editor($menu);
        $parent = isset($v['parent']) ? (string) $v['parent'] : null;
        $parentItem = $parent ? $q->editor($menu, $parent) : null;
        $generatedFrom = isset($v['generated_from']) ? (string) $v['generated_from'] : ($parentItem && $parentItem['generate_children'] ? $parent : null);
        $generated = $owner['source'] === 'categories' || $generatedFrom !== null;
        $categoryParent = isset($v['category_parent']) ? (string) $v['category_parent'] : null;
        if ($generatedFrom !== null) {
            $source = $q->editor($menu, $generatedFrom);
            abort_unless($source['generate_children'], 422);
            $categoryParent ??= $source['category_id'];
            abort_unless(in_array($categoryParent, app(CategoryHierarchy::class)->descendantIds($source['category_id']), true), 422);
        }
        if ($categoryParent !== null) {
            abort_unless($generated && DB::table('categories')->where('id', $categoryParent)->whereNull('system_key')->exists(), 422);
        }
        $menuName = $owner['translations']->{app()->getLocale()}['name'] ?? $owner['translations']->{app(LocaleCatalog::class)->defaultCode()}['name'] ?? __('menus.title');

        return Inertia::render('Admin/Menus/Items', [...$this->shell($request), 'menu' => $owner, 'name' => $menuName, 'parent' => $parentItem,
            'generated' => $generated, 'generatedFrom' => $generatedFrom, 'categoryParent' => $categoryParent,
            'items' => $generated ? $q->categoryBranch($categoryParent) : $q->branch($menu, $parent)]);
    }

    public function itemForm(Request $request, MenuQueries $q, string $menu, ?string $item = null): Response
    {
        Gate::authorize('menus.view');
        if ($item === null) {
            Gate::authorize('menus.manage');
        }
        $v = $request->validate(['parent' => ['nullable', 'integer', 'min:1'], 'parent_search' => ['nullable', 'string', 'max:100'], 'category_search' => ['nullable', 'string', 'max:100']]);
        $owner = $q->editor($menu);
        abort_unless($owner['source'] === 'manual', 422);
        $row = $item === null ? null : $q->editor($menu, $item);
        $parent = $row['parent_id'] ?? (isset($v['parent']) ? (string) $v['parent'] : null);
        if ($parent !== null) {
            $p = $q->editor($menu, $parent);
            abort_if($p['generate_children'], 422);
        }
        $parents = $q->parents($menu, $item, $v['parent_search'] ?? '');
        if ($parent !== null && ! in_array($parent, array_column($parents, 'id'), true)) {
            $p = $q->editor($menu, $parent);
            $parents[] = ['id' => $parent, 'name' => $p['translations']->{app(LocaleCatalog::class)->defaultCode()}['name'] ?? '#'.$parent];
        }

        return Inertia::render('Admin/Menus/ItemForm', [...$this->shell($request), 'menu' => $owner, 'item' => $row, 'initialParent' => $parent,
            'parents' => $parents, 'categories' => $q->categories($v['category_search'] ?? '', $row['category_id'] ?? null), 'defaultLocale' => app(LocaleCatalog::class)->defaultCode()]);
    }

    public function itemSave(Request $request, MenuAdministration $menus, string $menu, ?string $item = null): RedirectResponse
    {
        Gate::authorize('menus.manage');
        $request->validate(['image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']]);
        /** @var UploadedFile|null $image */
        $image = $request->file('image');
        $menus->item((string) $request->user()->id, $menu, $item, $request->all(), $image);

        return redirect('/admin/menus/'.$menu)->with('status', __('menus.saved'));
    }

    public function destroy(Request $request, MenuAdministration $menus, string $menu, ?string $item = null): RedirectResponse
    {
        $menus->delete((string) $request->user()->id, $menu, $item, $request->all());

        return redirect($item === null ? '/admin/menus' : '/admin/menus/'.$menu)->with('status', __('menus.deleted'));
    }

    public function image(Request $request, string $menu, string $item): StreamedResponse
    {
        Gate::authorize('menus.view');
        $id = DB::table('website_menu_items')->where('menu_id', $menu)->where('id', $item)->firstOrFail()->image_media_id;
        abort_if($id === null, 404);

        return app(PrivateImageStorage::class)->response(MediaFileRecord::findOrFail($id));
    }

    /** @return array<string,mixed> */
    private function shell(Request $request): array
    {
        return ['navigation' => app(AdminNavigation::class)->forUser($request->user()), 'breadcrumbs' => [
            ['label' => __('navigation.dashboard'), 'href' => route('admin.dashboard', [], false)],
            ['label' => __('menus.title'), 'href' => route('admin.menus.index', [], false)],
        ]];
    }
}
