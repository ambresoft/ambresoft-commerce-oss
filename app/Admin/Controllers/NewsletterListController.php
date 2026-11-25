<?php

namespace App\Admin\Controllers;

use App\Localization\Infrastructure\LocaleCatalog;
use App\Marketing\Infrastructure\NewsletterLists;
use App\Marketing\Infrastructure\NewsletterQueries;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Mailing lists and campaigns. */
final class NewsletterListController
{
    use NewsletterShell;

    public function index(Request $request, NewsletterQueries $queries): Response
    {
        Gate::authorize('newsletters.view');

        return Inertia::render('Admin/Newsletters/Lists', [...$this->shell($request, 'lists_title', '/admin/newsletter-lists'), 'lists' => $queries->lists()]);
    }

    public function create(Request $request, NewsletterQueries $queries): Response
    {
        Gate::authorize('newsletters.manage');

        return $this->form($request, $queries, null, __('newsletters.create_list'));
    }

    public function store(Request $request, NewsletterLists $lists): RedirectResponse
    {
        $id = $lists->save((string) $request->user()->id, null, null, $request->all());

        return redirect('/admin/newsletter-lists/'.$id.'/edit')->with('status', __('newsletters.saved'));
    }

    public function edit(Request $request, NewsletterQueries $queries, string $list): Response
    {
        Gate::authorize('newsletters.manage');
        $editor = $queries->listEditor($list) ?? abort(404);

        return $this->form($request, $queries, $editor, $editor['translations']->{app()->getLocale()}['name'] ?? $editor['translations']->{app(LocaleCatalog::class)->defaultCode()}['name'] ?? '#'.$list);
    }

    public function update(Request $request, NewsletterLists $lists, string $list): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $lists->save((string) $request->user()->id, $list, (int) $data['version'], $request->except('version'));

        return redirect('/admin/newsletter-lists/'.$list.'/edit')->with('status', __('newsletters.saved'));
    }

    public function destroy(Request $request, NewsletterLists $lists, string $list): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $lists->delete((string) $request->user()->id, $list, (int) $data['version']);

        return redirect('/admin/newsletter-lists')->with('status', __('newsletters.deleted'));
    }

    public function campaigns(Request $request, NewsletterQueries $queries): Response
    {
        Gate::authorize('newsletters.view');

        return Inertia::render('Admin/Newsletters/Campaigns', [...$this->shell($request, 'campaigns_title', '/admin/newsletter-campaigns'), 'campaigns' => $queries->campaigns()]);
    }

    public function storeCampaign(Request $request, NewsletterLists $lists): RedirectResponse
    {
        $lists->saveCampaign((string) $request->user()->id, null, null, $request->all());

        return back()->with('status', __('newsletters.saved'));
    }

    public function updateCampaign(Request $request, NewsletterLists $lists, string $campaign): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $lists->saveCampaign((string) $request->user()->id, $campaign, (int) $data['version'], $request->except('version'));

        return back()->with('status', __('newsletters.saved'));
    }

    public function destroyCampaign(Request $request, NewsletterLists $lists, string $campaign): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $lists->deleteCampaign((string) $request->user()->id, $campaign, (int) $data['version']);

        return back()->with('status', __('newsletters.deleted'));
    }

    /** @param array<string, mixed>|null $editor */
    private function form(Request $request, NewsletterQueries $queries, ?array $editor, string $title): Response
    {
        return Inertia::render('Admin/Newsletters/ListForm', [...$this->shell($request, 'lists_title', '/admin/newsletter-lists', $title), 'item' => $editor,
            'autoresponders' => $queries->autoresponderChoices(), 'defaultLocale' => app(LocaleCatalog::class)->defaultCode()]);
    }
}
