<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Media\Infrastructure\Persistence\MediaFileRecord;
use App\Media\Infrastructure\PrivateImageStorage;
use App\Payment\Infrastructure\PaymentMethodRestrictions;
use App\Payment\Infrastructure\PaymentMethods;
use App\Payment\Infrastructure\PaymentQueries;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PaymentMethodController
{
    public function index(Request $request, PaymentQueries $queries): Response
    {
        Gate::authorize('payment-methods.manage');

        return Inertia::render('Admin/PaymentMethods/Index', [...$this->shell($request), 'methods' => $queries->methods()]);
    }

    public function edit(Request $request, PaymentQueries $queries, string $method): Response
    {
        Gate::authorize('payment-methods.manage');
        $editor = $queries->editor($method) ?? abort(404);

        return Inertia::render('Admin/PaymentMethods/Form', [
            ...$this->shell($request, $editor['name']), 'method' => $editor, 'statuses' => $queries->statuses(), 'defaultLocale' => app(LocaleCatalog::class)->defaultCode(),
            'categoryOptions' => app(PaymentMethodRestrictions::class)->categoryOptions(app()->getLocale(), app(LocaleCatalog::class)->defaultCode()),
        ]);
    }

    public function update(Request $request, PaymentMethods $methods, string $method): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $result = $methods->save((string) $request->user()->id, $method, (int) $data['version'], $request->except(['version']));
        $response = redirect('/admin/payment-methods/'.$method.'/edit')->with('status', $this->message('payments.saved', $result['verification']));

        return $result['activation'] === null ? $response : $response->withErrors(['is_active' => $result['activation']]);
    }

    public function image(string $method): StreamedResponse
    {
        Gate::authorize('payment-methods.manage');
        $media = DB::table('payment_methods')->where('code', $method)->value('image_media_id') ?? abort(404);

        return app(PrivateImageStorage::class)->response(MediaFileRecord::findOrFail($media));
    }

    public function active(Request $request, PaymentMethods $methods, string $method): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'active' => ['required', 'boolean']]);
        $methods->setActive((string) $request->user()->id, $method, (int) $data['version'], (bool) $data['active']);

        return redirect('/admin/payment-methods')->with('status', __('payments.saved'));
    }

    public function order(Request $request, PaymentMethods $methods): RedirectResponse
    {
        $data = $request->validate(['codes' => ['required', 'array', 'min:1', 'max:100'], 'codes.*' => ['required', 'string', 'regex:/^[a-z][a-z0-9_.-]{1,59}$/D']]);
        $methods->reorder((string) $request->user()->id, array_values($data['codes']));

        return redirect('/admin/payment-methods');
    }

    public function verify(Request $request, PaymentMethods $methods, string $method): RedirectResponse
    {
        $outcome = $methods->verify((string) $request->user()->id, $method);

        return redirect('/admin/payment-methods/'.$method.'/edit')->with('status', $this->message(null, $outcome));
    }

    private function message(?string $saved, ?string $verification): string
    {
        $parts = $saved === null ? [] : [__($saved)];
        if ($verification !== null) {
            $parts[] = match ($verification) {
                'ok' => __('payments.verified_ok'),
                'incomplete' => __('payments.verify_incomplete'),
                'credentials_rejected' => __('payments.verify_rejected'),
                default => __('payments.verify_unavailable'),
            };
        }

        return implode(' ', $parts);
    }

    /** @return array<string, mixed> */
    private function shell(Request $request, ?string $current = null): array
    {
        $breadcrumbs = [['label' => __('navigation.dashboard'), 'href' => '/admin'], ['label' => __('payments.title'), 'href' => $current === null ? null : '/admin/payment-methods']];
        if ($current !== null) {
            $breadcrumbs[] = ['label' => $current, 'href' => null];
        }

        return ['navigation' => app(AdminNavigation::class)->forUser($request->user()), 'breadcrumbs' => $breadcrumbs];
    }
}
