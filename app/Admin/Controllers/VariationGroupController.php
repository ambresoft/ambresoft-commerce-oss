<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Catalog\Application\Command\VariationGroupData;
use App\Catalog\Application\Contract\VariationGroupAdministration;
use App\Catalog\Infrastructure\CategoryQueries;
use App\Catalog\Infrastructure\VariationGroupQueries;
use App\Catalog\Infrastructure\VariationImpactRequired;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class VariationGroupController
{
    public function index(Request $request, VariationGroupQueries $queries): Response
    {
        Gate::authorize('products.view');
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'category_id' => ['nullable', 'integer', 'min:1'], 'type' => ['nullable', Rule::in(['physical', 'digital'])], 'feature_id' => ['nullable', 'integer', 'min:1'], 'members' => ['nullable', Rule::in(['single', 'multiple'])], 'sort' => ['nullable', Rule::in(['id', 'name', 'members'])], 'direction' => ['nullable', Rule::in(['asc', 'desc'])], 'per_page' => ['nullable', Rule::in([25, 50, 100])], 'page' => ['nullable', 'integer', 'min:1', 'max:1000000']]);

        return Inertia::render('Admin/VariationGroups/Index', [...$this->shell($request), 'groups' => $queries->listing($filters), 'filters' => (object) $filters, 'features' => $queries->variationFeatures(), 'categories' => $this->categories()]);
    }

    public function create(Request $request, VariationGroupQueries $queries): Response
    {
        Gate::authorize('products.view');
        Gate::authorize('products.update');
        $v = $request->validate(['product_search' => ['nullable', 'string', 'max:100'], 'product_id' => ['nullable', 'integer', 'min:1']]);

        return Inertia::render('Admin/VariationGroups/Create', [...$this->shell($request, __('variations.create')), 'creation' => $queries->creation($v['product_search'] ?? '', isset($v['product_id']) ? (string) $v['product_id'] : null), 'filters' => (object) $v]);
    }

    public function store(Request $request, VariationGroupAdministration $groups): RedirectResponse
    {
        $id = $groups->create((string) $request->user()->id, new VariationGroupData($request->only(['product_id', 'product_version', 'features', 'name'])));

        return redirect('/admin/variation-groups/'.$id)->with('status', __('variations.created'));
    }

    public function show(Request $request, VariationGroupQueries $queries, string $group): Response
    {
        Gate::authorize('products.view');
        $v = $request->validate(['candidate_search' => ['nullable', 'string', 'max:100']]);
        $detail = $queries->detail($group);

        return Inertia::render('Admin/VariationGroups/Show', [...$this->shell($request, $detail['name']), 'group' => $detail,
            'candidates' => fn () => Gate::allows('products.update') ? $queries->candidates($group, $v['candidate_search'] ?? '') : [],
            'targets' => fn () => Gate::allows('products.update') ? $queries->transferTargets($group) : [],
            'availableFeatures' => $queries->variationFeatures(),
            'impactPreview' => $request->session()->get('variation_impact'),
            'candidateSearch' => $v['candidate_search'] ?? '']);
    }

    public function listing(Request $request, VariationGroupAdministration $groups, string $group): RedirectResponse
    {
        $groups->listing((string) $request->user()->id, $group, new VariationGroupData($request->only(['version', 'single_listing', 'single_carousel', 'single_search', 'view_product', 'color_swatches', 'clickable_colors', 'card_features', 'card_labels'])));

        return back()->with('status', __('variations.saved'));
    }

    public function rename(Request $request, VariationGroupAdministration $groups, string $group): RedirectResponse
    {
        $groups->rename((string) $request->user()->id, $group, new VariationGroupData($request->only(['version', 'name', 'translations'])));

        return back()->with('status', __('variations.saved'));
    }

    public function configure(Request $request, VariationGroupAdministration $groups, string $group): RedirectResponse
    {
        try {
            $groups->configure((string) $request->user()->id, $group, new VariationGroupData($request->only(['version', 'features', 'confirmed', 'impact_token'])));
        } catch (VariationImpactRequired $e) {
            return back()->with('variation_impact', ['impact' => $e->impact, 'token' => $e->token, 'features' => $request->input('features')]);
        }

        return back()->with('status', __('variations.saved'));
    }

    public function reorder(Request $request, VariationGroupAdministration $groups, string $group): RedirectResponse
    {
        $groups->reorder((string) $request->user()->id, $group, new VariationGroupData($request->only(['version', 'features'])));

        return back()->with('status', __('variations.saved'));
    }

    public function add(Request $request, VariationGroupAdministration $groups, string $group): RedirectResponse
    {
        $groups->add((string) $request->user()->id, $group, new VariationGroupData($request->only(['version', 'products'])));

        return redirect('/admin/variation-groups/'.$group)->with('status', __('variations.members_added'));
    }

    public function remove(Request $request, VariationGroupAdministration $groups, string $group, string $product): RedirectResponse
    {
        $groups->remove((string) $request->user()->id, $group, $product, new VariationGroupData($request->only(['version', 'product_version'])));

        return $this->afterMembershipChange($group, __('variations.member_removed'));
    }

    public function transfer(Request $request, VariationGroupAdministration $groups, string $group, string $product): RedirectResponse
    {
        $groups->transfer((string) $request->user()->id, $group, $product, new VariationGroupData($request->only(['version', 'product_version', 'target', 'target_version'])));

        return $this->afterMembershipChange($group, __('variations.member_transferred'));
    }

    public function disband(Request $request, VariationGroupAdministration $groups, string $group): RedirectResponse
    {
        $groups->disband((string) $request->user()->id, $group, new VariationGroupData($request->only(['version'])));

        return redirect('/admin/variation-groups')->with('status', __('variations.disbanded'));
    }

    public function variation(Request $request, VariationGroupAdministration $groups, string $group): RedirectResponse
    {
        $id = $groups->createVariation((string) $request->user()->id, $group, new VariationGroupData($request->only(['version', 'source', 'source_version', 'options'])));

        return redirect('/admin/products/'.$id.'/edit')->with('status', __('variations.variation_created'));
    }

    // An emptied group is disbanded automatically; continue at the list instead of a missing page.
    private function afterMembershipChange(string $group, string $status): RedirectResponse
    {
        $exists = DB::table('variation_groups')->where('id', $group)->exists();

        return redirect($exists ? '/admin/variation-groups/'.$group : '/admin/variation-groups')->with('status', $exists ? $status : __('variations.auto_disbanded'));
    }

    /** @return list<array{id: string, name: string}> */
    private function categories(): array
    {
        $ids = DB::table('variation_group_members as m')->join('products as p', 'p.id', '=', 'm.product_id')->whereNotNull('p.category_id')->distinct()->pluck('p.category_id')->map(strval(...))->all();
        $paths = app(CategoryQueries::class)->paths($ids);

        return array_map(fn (string $id): array => ['id' => $id, 'name' => implode(' › ', $paths[$id] ?? [])], $ids);
    }

    /** @return array<string, mixed> */
    private function shell(Request $request, ?string $current = null): array
    {
        $breadcrumbs = [['label' => __('navigation.dashboard'), 'href' => '/admin'], ['label' => __('variations.title'), 'href' => $current === null ? null : '/admin/variation-groups']];
        if ($current !== null) {
            $breadcrumbs[] = ['label' => $current, 'href' => null];
        }

        return ['navigation' => app(AdminNavigation::class)->forUser($request->user()), 'breadcrumbs' => $breadcrumbs];
    }
}
