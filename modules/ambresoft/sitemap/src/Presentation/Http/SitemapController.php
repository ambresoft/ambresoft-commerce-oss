<?php

namespace Ambresoft\Sitemap\Presentation\Http;

use Ambresoft\Sitemap\Infrastructure\Providers;
use Ambresoft\Sitemap\Infrastructure\Sitemap;
use App\Admin\Navigation\AdminNavigation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class SitemapController
{
    public function index(Request $request, Sitemap $sitemap, AdminNavigation $navigation): Response
    {
        $tab = $request->query('tab') === 'urls' ? 'urls' : 'overview';
        $section = is_string($request->query('section')) && in_array($request->query('section'), Providers::SECTIONS, true) ? $request->query('section') : 'homepage';

        return Inertia::render('Admin/Addons/Sitemap', [...$sitemap->overview(), 'tab' => $tab, 'activeSection' => $section,
            'navigation' => $navigation->forUser($request->user()),
            'breadcrumbs' => [['label' => __('navigation.dashboard'), 'href' => '/admin'], ['label' => __('cms-sitemap::ui.title'), 'href' => null]],
            'entries' => $tab === 'urls' ? $sitemap->listing($section, max(1, (int) $request->query('page', 1))) : null,
            'canManage' => Gate::allows('ambresoft.sitemap.manage'), 'canPublish' => Gate::allows('ambresoft.sitemap.publish')]);
    }

    public function settings(Request $request, Sitemap $sitemap): RedirectResponse
    {
        $sitemap->saveSettings((string) $request->user()->getAuthIdentifier(), $request->all());

        return back()->with('status', __('cms-sitemap::ui.saved'));
    }

    public function draft(Request $request, Sitemap $sitemap): RedirectResponse
    {
        $sitemap->saveDraft((string) $request->user()->getAuthIdentifier(), $request->all());

        return back()->with('status', __('cms-sitemap::ui.saved'));
    }

    public function generate(Request $request, Sitemap $sitemap): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'section' => ['nullable', Rule::in(Providers::SECTIONS)]]);
        $sitemap->requestRefresh((string) $request->user()->getAuthIdentifier(), (int) $data['version'], isset($data['section']) ? [$data['section']] : Providers::SECTIONS);

        return back()->with('status', __('cms-sitemap::ui.queued'));
    }

    public function publish(Request $request, Sitemap $sitemap): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'confirmed' => ['required', 'accepted']]);
        $sitemap->publish((string) $request->user()->getAuthIdentifier(), (int) $data['version']);

        return back()->with('status', __('cms-sitemap::ui.published'));
    }
}
