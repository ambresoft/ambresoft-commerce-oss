<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Website\Infrastructure\RedirectAdministration;
use App\Website\Infrastructure\RedirectQueries;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Website → SEO Rules: URL redirects only. Every change goes through RedirectAdministration. */
final class SeoRuleController
{
    public function index(Request $request, RedirectQueries $queries): Response
    {
        Gate::authorize('seo-rules.view');
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:200'], 'status' => ['nullable', Rule::in(['enabled', 'disabled'])], 'code' => ['nullable', Rule::in(['301', '302'])], 'page' => ['nullable', 'integer', 'between:1,1000000'], 'per_page' => ['nullable', Rule::in([25, 50, 100])]]);

        return Inertia::render('Admin/SeoRules/Index', [...$this->shell($request), 'rules' => $queries->listing($filters), 'filters' => (object) $filters]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('seo-rules.manage');

        return Inertia::render('Admin/SeoRules/Form', [...$this->shell($request, __('seo_rules.create')), 'rule' => null]);
    }

    public function edit(Request $request, RedirectQueries $queries, string $rule): Response
    {
        Gate::authorize('seo-rules.view');

        return Inertia::render('Admin/SeoRules/Form', [...$this->shell($request, __('seo_rules.edit')), 'rule' => $queries->editor($rule) ?? abort(404)]);
    }

    public function store(Request $request, RedirectAdministration $redirects): RedirectResponse
    {
        Gate::authorize('seo-rules.manage');
        $id = $redirects->save((string) $request->user()->id, null, null, $request->only(['source_path', 'destination', 'status_code', 'enabled']));

        return redirect('/admin/seo-rules/'.$id.'/edit')->with('status', __('seo_rules.saved'));
    }

    public function update(Request $request, RedirectAdministration $redirects, string $rule): RedirectResponse
    {
        Gate::authorize('seo-rules.manage');
        $version = (int) $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];
        $redirects->save((string) $request->user()->id, $rule, $version, $request->only(['source_path', 'destination', 'status_code', 'enabled']));

        return redirect('/admin/seo-rules/'.$rule.'/edit')->with('status', __('seo_rules.saved'));
    }

    public function status(Request $request, RedirectAdministration $redirects, string $rule): RedirectResponse
    {
        Gate::authorize('seo-rules.manage');
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'enabled' => ['required', 'boolean']]);
        $redirects->setEnabled((string) $request->user()->id, $rule, (int) $data['version'], (bool) $data['enabled']);

        return back()->with('status', __('seo_rules.saved'));
    }

    public function destroy(Request $request, RedirectAdministration $redirects, string $rule): RedirectResponse
    {
        Gate::authorize('seo-rules.manage');
        $version = (int) $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];
        $redirects->delete((string) $request->user()->id, $rule, $version);

        return redirect('/admin/seo-rules')->with('status', __('seo_rules.deleted'));
    }

    /** @return array<string, mixed> */
    private function shell(Request $request, ?string $current = null): array
    {
        $breadcrumbs = [['label' => __('navigation.dashboard'), 'href' => '/admin'], ['label' => __('seo_rules.title'), 'href' => $current === null ? null : '/admin/seo-rules']];
        if ($current !== null) {
            $breadcrumbs[] = ['label' => $current, 'href' => null];
        }

        return ['navigation' => app(AdminNavigation::class)->forUser($request->user()), 'breadcrumbs' => $breadcrumbs];
    }
}
