<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Website\Infrastructure\SocialLoginAdministration;
use App\Website\Infrastructure\SocialLoginQueries;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Social login providers for the future storefront (`social-login.view` / `social-login.manage`). */
final class SocialLoginController
{
    public function index(Request $request, SocialLoginQueries $queries): Response
    {
        Gate::authorize('social-login.view');

        return Inertia::render('Admin/SocialLogin/Index', [...$this->shell($request), 'providers' => $queries->listing()]);
    }

    public function create(Request $request, SocialLoginQueries $queries): Response
    {
        Gate::authorize('social-login.manage');

        return Inertia::render('Admin/SocialLogin/Form', [...$this->shell($request, __('social_login.create')), 'provider' => null, 'catalog' => $queries->catalog()]);
    }

    public function store(Request $request, SocialLoginAdministration $providers): RedirectResponse
    {
        Gate::authorize('social-login.manage');
        $id = $providers->save((string) $request->user()->id, null, null, $request->all());

        return redirect('/admin/social-login/'.$id.'/edit')->with('status', __('social_login.saved'));
    }

    public function edit(Request $request, SocialLoginQueries $queries, string $provider): Response
    {
        Gate::authorize('social-login.view');
        $editor = $queries->editor($provider) ?? abort(404);

        return Inertia::render('Admin/SocialLogin/Form', [...$this->shell($request, __('social_login.edit')), 'provider' => $editor, 'catalog' => $queries->catalog()]);
    }

    public function update(Request $request, SocialLoginAdministration $providers, string $provider): RedirectResponse
    {
        Gate::authorize('social-login.manage');
        $version = $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];
        $providers->save((string) $request->user()->id, $provider, (int) $version, $request->except('version'));

        return redirect('/admin/social-login/'.$provider.'/edit')->with('status', __('social_login.saved'));
    }

    public function active(Request $request, SocialLoginAdministration $providers, string $provider): RedirectResponse
    {
        Gate::authorize('social-login.manage');
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'is_active' => ['required', 'boolean']]);
        $providers->setActive((string) $request->user()->id, $provider, (int) $data['version'], (bool) $data['is_active']);

        return back()->with('status', __('social_login.saved'));
    }

    public function destroy(Request $request, SocialLoginAdministration $providers, string $provider): RedirectResponse
    {
        Gate::authorize('social-login.manage');
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $providers->delete((string) $request->user()->id, $provider, (int) $data['version']);

        return redirect('/admin/social-login')->with('status', __('social_login.deleted'));
    }

    /** @return array<string, mixed> */
    private function shell(Request $request, ?string $current = null): array
    {
        $breadcrumbs = [['label' => __('navigation.dashboard'), 'href' => '/admin'], ['label' => __('social_login.title'), 'href' => $current === null ? null : '/admin/social-login']];
        if ($current !== null) {
            $breadcrumbs[] = ['label' => $current, 'href' => null];
        }

        return ['navigation' => app(AdminNavigation::class)->forUser($request->user()), 'breadcrumbs' => $breadcrumbs];
    }
}
