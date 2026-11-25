<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Media\Infrastructure\PrivateImageStorage;
use App\Settings\Infrastructure\CompanySettings;
use App\Shared\Infrastructure\Geography\Countries;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CompanySettingsController
{
    public function show(Request $request, AdminNavigation $navigation, CompanySettings $settings): Response
    {
        Gate::authorize('settings.manage');
        /** @var UserRecord $user */
        $user = $request->user();

        return Inertia::render('Admin/Settings/Company', [
            'navigation' => $navigation->forUser($user),
            'breadcrumbs' => [
                ['label' => __('navigation.dashboard'), 'href' => route('admin.dashboard', [], false)],
                ['label' => __('company.title'), 'href' => null],
            ],
            'settings' => $settings->all(),
            'countries' => app(Countries::class)->options(),
        ]);
    }

    public function update(Request $request, CompanySettings $settings): RedirectResponse
    {
        $settings->save((string) $request->user()->getAuthIdentifier(), $request->only(['version', ...CompanySettings::FIELDS]));

        return back()->with('status', __('company.saved'));
    }

    public function logos(Request $request, CompanySettings $settings): RedirectResponse
    {
        Gate::authorize('settings.manage');
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'logo' => ['nullable', 'file'], 'logo_mobile' => ['nullable', 'file'], 'favicon' => ['nullable', 'file'], 'logo_email' => ['nullable', 'file'],
            'remove_logo' => ['sometimes', 'boolean'], 'remove_logo_mobile' => ['sometimes', 'boolean'], 'remove_favicon' => ['sometimes', 'boolean'], 'remove_logo_email' => ['sometimes', 'boolean'],
        ]);
        $settings->saveLogos(
            (string) $request->user()->getAuthIdentifier(), (int) $data['version'],
            $request->file('logo'), $request->file('logo_mobile'),
            (bool) ($data['remove_logo'] ?? false), (bool) ($data['remove_logo_mobile'] ?? false),
            $request->file('favicon'), (bool) ($data['remove_favicon'] ?? false),
            $request->file('logo_email'), (bool) ($data['remove_logo_email'] ?? false),
        );

        return back()->with('status', __('company.logo_saved'));
    }

    public function addPaymentIcon(Request $request, CompanySettings $settings): RedirectResponse
    {
        Gate::authorize('settings.manage');
        $data = $request->validate(['icon' => ['required', 'file'], 'name' => ['nullable', 'string', 'max:80']]);
        $settings->addPaymentIcon((string) $request->user()->getAuthIdentifier(), $request->file('icon'), (string) ($data['name'] ?? ''));

        return back()->with('status', __('company.payment_icon_added'));
    }

    public function reorderPaymentIcons(Request $request, CompanySettings $settings): RedirectResponse
    {
        Gate::authorize('settings.manage');
        $data = $request->validate(['ids' => ['present', 'array', 'max:'.CompanySettings::MAX_PAYMENT_ICONS], 'ids.*' => ['required', 'integer', 'distinct', 'min:1']]);
        $settings->reorderPaymentIcons((string) $request->user()->getAuthIdentifier(), array_map(strval(...), array_values($data['ids'])));

        return back();
    }

    public function removePaymentIcon(Request $request, CompanySettings $settings, string $icon): RedirectResponse
    {
        Gate::authorize('settings.manage');
        $settings->removePaymentIcon((string) $request->user()->getAuthIdentifier(), $icon);

        return back()->with('status', __('company.payment_icon_removed'));
    }

    public function paymentIconImage(CompanySettings $settings, string $icon): StreamedResponse
    {
        Gate::authorize('settings.manage');

        return app(PrivateImageStorage::class)->response($settings->paymentIconMedia($icon) ?? abort(404));
    }

    public function storeLink(Request $request, CompanySettings $settings): RedirectResponse
    {
        return $this->saveLink($request, $settings, null);
    }

    public function updateLink(Request $request, CompanySettings $settings, string $link): RedirectResponse
    {
        return $this->saveLink($request, $settings, $link);
    }

    private function saveLink(Request $request, CompanySettings $settings, ?string $link): RedirectResponse
    {
        Gate::authorize('settings.manage');
        $data = $request->validate([
            'kind' => [$link === null ? 'required' : 'prohibited', 'string'], 'preset' => ['nullable', 'string'], 'name' => ['nullable', 'string'], 'url' => ['required', 'string'],
            'image' => ['nullable', 'file'], 'remove_image' => ['sometimes', 'boolean'],
        ]);
        $settings->saveLink((string) $request->user()->getAuthIdentifier(), $link, $data, $request->file('image'), (bool) ($data['remove_image'] ?? false));

        return back()->with('status', __($link === null ? 'company.link_added' : 'company.link_saved'));
    }

    public function reorderLinks(Request $request, CompanySettings $settings): RedirectResponse
    {
        Gate::authorize('settings.manage');
        $data = $request->validate(['kind' => ['required', 'string'], 'ids' => ['present', 'array', 'max:'.CompanySettings::MAX_LINKS], 'ids.*' => ['required', 'integer', 'distinct', 'min:1']]);
        $settings->reorderLinks((string) $request->user()->getAuthIdentifier(), $data['kind'], array_map(strval(...), array_values($data['ids'])));

        return back();
    }

    public function removeLink(Request $request, CompanySettings $settings, string $link): RedirectResponse
    {
        Gate::authorize('settings.manage');
        $settings->removeLink((string) $request->user()->getAuthIdentifier(), $link);

        return back()->with('status', __('company.link_removed'));
    }

    public function linkImage(CompanySettings $settings, string $link): StreamedResponse
    {
        Gate::authorize('settings.manage');

        return app(PrivateImageStorage::class)->response($settings->linkMedia($link) ?? abort(404));
    }

    public function logo(CompanySettings $settings, string $kind): StreamedResponse
    {
        Gate::authorize('settings.manage');
        abort_unless(in_array($kind, ['desktop', 'mobile', 'email', 'favicon'], true), 404);
        $media = $settings->logoMedia($kind) ?? abort(404);

        return app(PrivateImageStorage::class)->response($media);
    }
}
