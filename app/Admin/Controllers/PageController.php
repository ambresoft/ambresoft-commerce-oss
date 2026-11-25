<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Website\Infrastructure\FaqQueries;
use App\Website\Infrastructure\PageAdministration;
use App\Website\Infrastructure\PageQueries;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class PageController
{
    public function index(Request $request, PageQueries $queries): Response
    {
        Gate::authorize('pages.view');

        return Inertia::render('Admin/Pages/Index', [...$this->shell($request), 'pages' => $queries->tree()]);
    }

    public function create(Request $request, PageQueries $queries): Response
    {
        Gate::authorize('pages.manage');
        $parent = $request->validate(['parent' => ['nullable', 'integer', 'min:1']])['parent'] ?? null;

        return Inertia::render('Admin/Pages/Form', [...$this->shell($request, __('pages.create')), ...$this->editorProps($queries), 'page' => null, 'initialParent' => $parent === null ? null : (string) $parent]);
    }

    public function store(Request $request, PageAdministration $pages): RedirectResponse
    {
        Gate::authorize('pages.manage');
        $id = $pages->save((string) $request->user()->id, null, null, $request->all());

        return redirect('/admin/pages/'.$id.'/edit')->with('status', __('pages.saved'));
    }

    public function edit(Request $request, PageQueries $queries, string $page): Response
    {
        Gate::authorize('pages.view');
        $editor = $queries->editor($page) ?? abort(404);

        return Inertia::render('Admin/Pages/Form', [...$this->shell($request, __('pages.edit')), ...$this->editorProps($queries), 'page' => $editor, 'faqOptions' => Gate::allows('faqs.view') ? app(FaqQueries::class)->options('page', $editor['faq_ids']) : [], 'initialParent' => null]);
    }

    public function update(Request $request, PageAdministration $pages, string $page): RedirectResponse
    {
        Gate::authorize('pages.manage');
        $version = (int) $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];
        $pages->save((string) $request->user()->id, $page, $version, $request->all());

        return redirect('/admin/pages/'.$page.'/edit')->with('status', __('pages.saved'));
    }

    public function destroy(Request $request, PageAdministration $pages, string $page): RedirectResponse
    {
        Gate::authorize('pages.manage');
        $version = (int) $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];
        $pages->delete((string) $request->user()->id, $page, $version);

        return redirect('/admin/pages')->with('status', __('pages.deleted'));
    }

    public function bulk(Request $request, PageAdministration $pages): RedirectResponse
    {
        Gate::authorize('pages.manage');
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['integer', 'min:1'], 'action' => ['required', Rule::in(['active', 'hidden', 'disabled', 'delete'])]]);
        $count = $pages->bulk((string) $request->user()->id, array_map('strval', $data['ids']), $data['action']);

        return back()->with('status', trans_choice('pages.bulk_done', $count, ['count' => $count]));
    }

    /** @return array<string, mixed> */
    private function editorProps(PageQueries $queries): array
    {
        return ['faqOptions' => Gate::allows('faqs.view') ? app(FaqQueries::class)->options('page') : [], 'tree' => $queries->tree(), 'customerGroups' => $queries->customerGroups(), 'facebookTypes' => $queries->facebookTypes(), 'defaultLocale' => app(LocaleCatalog::class)->defaultCode()];
    }

    /** @return array<string, mixed> */
    private function shell(Request $request, ?string $current = null): array
    {
        $breadcrumbs = [['label' => __('navigation.dashboard'), 'href' => '/admin'], ['label' => __('pages.title'), 'href' => $current === null ? null : '/admin/pages']];
        if ($current !== null) {
            $breadcrumbs[] = ['label' => $current, 'href' => null];
        }

        return ['navigation' => app(AdminNavigation::class)->forUser($request->user()), 'breadcrumbs' => $breadcrumbs];
    }
}
