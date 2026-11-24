<?php

namespace Ambresoft\GdprCookies\Presentation\Http;

use Ambresoft\GdprCookies\Infrastructure\GdprCookies;
use App\Admin\Navigation\AdminNavigation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class GdprCookiesController
{
    public function index(Request $request, GdprCookies $cookies, AdminNavigation $navigation): Response
    {
        $canScripts = Gate::allows('ambresoft.gdpr-cookies.scripts');
        $consent = is_string($request->query('consent')) && Str::isUuid($request->query('consent')) ? $request->query('consent') : null;

        return Inertia::render('Admin/Addons/GdprCookies', [...$cookies->admin($canScripts),
            'navigation' => $navigation->forUser($request->user()),
            'breadcrumbs' => [['label' => __('navigation.dashboard'), 'href' => '/admin'], ['label' => __('addons.title'), 'href' => '/admin/addons'], ['label' => __('cms-gdpr::gdpr.title'), 'href' => null]],
            'canManage' => Gate::allows('ambresoft.gdpr-cookies.manage'), 'canScripts' => $canScripts,
            'lookup' => $consent === null ? null : ['id' => $consent, 'records' => $cookies->lookup($consent)]]);
    }

    public function save(Request $request, GdprCookies $cookies): RedirectResponse
    {
        $cookies->save((string) $request->user()->getAuthIdentifier(), $request->all());

        return back()->with('status', __('cms-gdpr::gdpr.saved'));
    }

    public function scripts(Request $request, GdprCookies $cookies): RedirectResponse
    {
        $cookies->saveScripts((string) $request->user()->getAuthIdentifier(), $request->all());

        return back()->with('status', __('cms-gdpr::gdpr.scripts_saved'));
    }

    public function renew(Request $request, GdprCookies $cookies): RedirectResponse
    {
        $cookies->renew((string) $request->user()->getAuthIdentifier());

        return back()->with('status', __('cms-gdpr::gdpr.renewed'));
    }
}
