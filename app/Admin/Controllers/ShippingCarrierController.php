<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Settings\Infrastructure\GeneralSettings;
use App\Shipping\Application\Command\ShippingDocument;
use App\Shipping\Application\Contract\ManifestsShipments;
use App\Shipping\Infrastructure\CarrierSettings;
use App\Shipping\Infrastructure\Manifests;
use App\Shipping\Infrastructure\ShippingCarriers;
use App\Shipping\Infrastructure\ShippingQueries;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** Carrier providers: their settings and named accounts (`shipping-carriers.manage`) and pickup lists (`orders.shipments`). */
final class ShippingCarrierController
{
    public function index(Request $request, CarrierSettings $carriers): Response
    {
        abort_unless(Gate::any(['shipping-carriers.manage', 'orders.shipments']), 403);

        return Inertia::render('Admin/ShippingCarriers/Index', [...$this->shell($request), 'carriers' => array_map(fn ($p): array => [
            'id' => $p->id(), 'name' => $p->name(), 'accounts' => DB::table('shipping_carrier_accounts')->where('provider', $p->id())->count(),
            'manifests' => $p instanceof ManifestsShipments,
        ], $carriers->providers())]);
    }

    public function show(Request $request, ShippingQueries $queries, string $provider): Response
    {
        Gate::authorize('shipping-carriers.manage');
        $carrier = $queries->carrier($provider) ?? abort(404);

        return Inertia::render('Admin/ShippingCarriers/Show', [...$this->shell($request, $carrier['name']), 'carrier' => $carrier]);
    }

    public function settings(Request $request, ShippingCarriers $carriers, string $provider): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $carriers->saveSettings((string) $request->user()->id, $provider, (int) $data['version'], $request->except('version'));

        return redirect('/admin/shipping-carriers/'.$provider)->with('status', __('shipping.carrier_saved'));
    }

    public function storeAccount(Request $request, ShippingCarriers $carriers, string $provider): RedirectResponse
    {
        $carriers->saveAccount((string) $request->user()->id, $provider, null, null, $request->all());

        return redirect('/admin/shipping-carriers/'.$provider.'?tab=accounts')->with('status', __('shipping.account_saved'));
    }

    public function updateAccount(Request $request, ShippingCarriers $carriers, string $provider, string $account): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $carriers->saveAccount((string) $request->user()->id, $provider, $account, (int) $data['version'], $request->except('version'));

        return redirect('/admin/shipping-carriers/'.$provider.'?tab=accounts')->with('status', __('shipping.account_saved'));
    }

    public function destroyAccount(Request $request, ShippingCarriers $carriers, string $provider, string $account): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $carriers->deleteAccount((string) $request->user()->id, $account, (int) $data['version']);

        return redirect('/admin/shipping-carriers/'.$provider.'?tab=accounts')->with('status', __('shipping.account_deleted'));
    }

    public function verifyAccount(Request $request, ShippingCarriers $carriers, string $provider, string $account): RedirectResponse
    {
        $result = $carriers->verifyAccount((string) $request->user()->id, $account);
        $message = match ($result['outcome']) {
            'ok' => __('shipping.verify_ok'), 'incomplete' => __('shipping.verify_incomplete'),
            'rejected' => __('shipping.verify_rejected', ['message' => (string) $result['message']]), default => __('shipping.verify_unavailable'),
        };

        return redirect('/admin/shipping-carriers/'.$provider.'?tab=accounts')->with('status', $message);
    }

    public function manifests(Request $request, Manifests $manifests, CarrierSettings $carriers, string $provider): Response
    {
        Gate::authorize('orders.shipments');
        $instance = $carriers->provider($provider);
        abort_unless($instance instanceof ManifestsShipments, 404);
        $accounts = DB::table('shipping_carrier_accounts')->where('provider', $provider)->orderBy('position')->orderBy('id')->get()
            ->filter(fn (object $a): bool => $carriers->accountIssues($instance, $a) === [])->map(fn (object $a): array => ['id' => (string) $a->id, 'name' => $a->name])->values()->all();
        $filters = $request->validate(['account' => ['nullable', 'integer', 'min:1'], 'date' => ['nullable', 'date_format:Y-m-d'], 'list' => ['nullable', 'regex:/^[A-Za-z0-9-]{1,60}$/']]);
        $account = (string) ($filters['account'] ?? ($accounts[0]['id'] ?? ''));
        $date = $filters['date'] ?? now(app(GeneralSettings::class)->all()['timezone'])->format('Y-m-d');
        $actor = (string) $request->user()->id;
        $day = null;
        $contents = null;
        $error = null;
        if ($account !== '' && in_array($account, array_column($accounts, 'id'), true)) {
            try {
                $day = $manifests->day($actor, $provider, $account, $date);
                $contents = isset($filters['list']) ? $manifests->contents($actor, $provider, $account, $filters['list'], $date) : null;
            } catch (ValidationException $e) {
                $error = collect($e->errors())->flatten()->first();
            }
        }

        return Inertia::render('Admin/ShippingCarriers/Manifests', [...$this->shell($request, __($instance->manifestLabel())),
            'carrier' => ['id' => $instance->id(), 'name' => $instance->name(), 'manifest_label' => __($instance->manifestLabel())],
            'accounts' => $accounts, 'account' => $account, 'date' => $date, 'day' => $day, 'list' => $filters['list'] ?? null, 'contents' => $contents, 'error' => $error]);
    }

    public function issue(Request $request, Manifests $manifests, string $provider): RedirectResponse
    {
        $data = $request->validate(['account' => ['required', 'integer', 'min:1'], 'date' => ['required', 'date_format:Y-m-d'], 'request_key' => ['required', 'uuid']]);
        $result = $manifests->issue((string) $request->user()->id, $provider, (string) $data['account'], $data['date'], $data['request_key']);

        return redirect('/admin/shipping-carriers/'.$provider.'/manifests?'.http_build_query(['account' => $data['account'], 'date' => $data['date'], 'list' => $result['number']]))
            ->with('status', __('shipping.manifest_issued', ['number' => $result['number']]));
    }

    public function print(Request $request, Manifests $manifests, string $provider, string $number): HttpResponse
    {
        $data = $request->validate(['account' => ['required', 'integer', 'min:1'], 'date' => ['required', 'date_format:Y-m-d']]);

        return self::pdf($manifests->print((string) $request->user()->id, $provider, (string) $data['account'], $number, $data['date']));
    }

    /** Serves a carrier PDF for printing without letting the browser interpret it as anything else. */
    public static function pdf(ShippingDocument $document): HttpResponse
    {
        return response($document->content, 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$document->filename.'"', 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    /** @return array<string, mixed> */
    private function shell(Request $request, ?string $current = null): array
    {
        $breadcrumbs = [['label' => __('navigation.dashboard'), 'href' => '/admin'], ['label' => __('shipping.carriers_title'), 'href' => $current === null ? null : '/admin/shipping-carriers']];
        if ($current !== null) {
            $breadcrumbs[] = ['label' => $current, 'href' => null];
        }

        return ['navigation' => app(AdminNavigation::class)->forUser($request->user()), 'breadcrumbs' => $breadcrumbs];
    }
}
