<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Catalog\Domain\Service\AvailabilityRules;
use App\Catalog\Infrastructure\AvailabilityStatuses;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class AvailabilityStatusController
{
    public function index(Request $request, AvailabilityStatuses $statuses): Response
    {
        Gate::authorize('availability-statuses.manage');

        return Inertia::render('Admin/AvailabilityStatuses/Index', [...$this->shell($request), 'statuses' => $statuses->options()]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('availability-statuses.manage');

        return Inertia::render('Admin/AvailabilityStatuses/Form', [...$this->shell($request), 'status' => null, 'options' => [], 'nameMax' => AvailabilityRules::NAME_MAX]);
    }

    public function edit(Request $request, AvailabilityStatuses $statuses, string $availability_status): Response
    {
        Gate::authorize('availability-statuses.manage');

        return Inertia::render('Admin/AvailabilityStatuses/Form', [...$this->shell($request), 'status' => $statuses->editor($availability_status), 'options' => $statuses->options(), 'nameMax' => AvailabilityRules::NAME_MAX]);
    }

    public function store(Request $request, AvailabilityStatuses $statuses): RedirectResponse
    {
        $id = $statuses->save((string) $request->user()->id, null, $request->all());

        return redirect('/admin/availability-statuses/'.$id.'/edit')->with('status', __('availability.saved'));
    }

    public function update(Request $request, AvailabilityStatuses $statuses, string $availability_status): RedirectResponse
    {
        $statuses->save((string) $request->user()->id, $availability_status, $request->all());

        return back()->with('status', __('availability.saved'));
    }

    public function destroy(Request $request, AvailabilityStatuses $statuses, string $availability_status): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'replacement' => ['nullable', 'integer', 'min:1']]);
        $statuses->delete((string) $request->user()->id, $availability_status, (int) $data['version'], isset($data['replacement']) ? (string) $data['replacement'] : null);

        return redirect('/admin/availability-statuses')->with('status', __('availability.deleted'));
    }

    /** @return array<string,mixed> */
    private function shell(Request $request): array
    {
        return ['navigation' => app(AdminNavigation::class)->forUser($request->user()), 'breadcrumbs' => [['label' => __('navigation.dashboard'), 'href' => '/admin'], ['label' => __('availability.title'), 'href' => '/admin/availability-statuses']]];
    }
}
