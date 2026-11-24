<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Customer\Domain\Model\CallRequestStatus;
use App\Customer\Infrastructure\CallRequestQueries;
use App\Customer\Infrastructure\CallRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class CallRequestController
{
    public function index(Request $request, CallRequestQueries $queries): Response
    {
        Gate::authorize('call-requests.view');
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:200'], 'status' => ['nullable', Rule::in(CallRequestStatus::values())], 'responsible' => ['nullable', 'regex:/^(?:unassigned|[1-9][0-9]{0,17})$/D'], 'created_from' => ['nullable', 'date_format:Y-m-d'], 'created_to' => ['nullable', 'date_format:Y-m-d'], 'sort' => ['nullable', Rule::in(['id', 'created_at', 'updated_at', 'status'])], 'direction' => ['nullable', Rule::in(['asc', 'desc'])], 'per_page' => ['nullable', Rule::in([25, 50, 100])], 'page' => ['nullable', 'integer', 'between:1,1000000']]);
        // An empty PHP array serializes as a JS array, whose inherited .sort would mask the editor default.
        $filters = ['sort' => 'created_at', 'direction' => 'desc', 'per_page' => '25', ...$filters];
        $page = $queries->listing($filters);

        return Inertia::render('Admin/CallRequests/Index', [...$this->shell($request), 'requests' => $page, 'filters' => $filters, 'statuses' => CallRequestStatus::values(), 'assignees' => $queries->assignees(array_values(array_filter(array_column($page['data'], 'responsible_id'))))]);
    }

    public function create(Request $request, CallRequestQueries $queries): Response
    {
        Gate::authorize('call-requests.manage');
        $search = $request->validate(['product_search' => ['nullable', 'string', 'max:100'], 'selected_products' => ['nullable', 'array', 'max:100'], 'selected_products.*' => ['integer', 'min:1']]);

        return Inertia::render('Admin/CallRequests/Form', [...$this->shell($request, __('calls.create')), 'callRequest' => null, 'statuses' => CallRequestStatus::values(), 'assignees' => $queries->assignees(), 'productOptions' => $queries->productOptions($search['product_search'] ?? '', array_map(strval(...), $search['selected_products'] ?? []))]);
    }

    public function edit(Request $request, CallRequestQueries $queries, string $callRequest): Response
    {
        Gate::authorize('call-requests.view');
        $editor = $queries->editor($callRequest) ?? abort(404);

        return Inertia::render('Admin/CallRequests/Form', [...$this->shell($request, __('calls.request_title', ['id' => $callRequest])), 'callRequest' => $editor, 'statuses' => CallRequestStatus::values(), 'assignees' => $queries->assignees($editor['responsible_id'] === '' ? [] : [$editor['responsible_id']]), 'productOptions' => []]);
    }

    public function store(Request $request, CallRequests $requests): RedirectResponse
    {
        $id = $requests->save((string) $request->user()->id, null, $request->all());

        return redirect('/admin/call-requests/'.$id.'/edit')->with('status', __('calls.saved'));
    }

    public function update(Request $request, CallRequests $requests, string $callRequest): RedirectResponse
    {
        Gate::authorize('call-requests.manage');
        $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $requests->save((string) $request->user()->id, $callRequest, $request->all());

        return redirect($this->returnTo($request, '/admin/call-requests/'.$callRequest.'/edit'))->with('status', __('calls.saved'));
    }

    public function destroy(Request $request, CallRequests $requests, string $callRequest): RedirectResponse
    {
        Gate::authorize('call-requests.delete');
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $requests->delete((string) $request->user()->id, [['id' => $callRequest, 'version' => (int) $data['version']]]);

        return redirect($this->returnTo($request))->with('status', __('calls.deleted'));
    }

    public function bulkDestroy(Request $request, CallRequests $requests): RedirectResponse
    {
        Gate::authorize('call-requests.delete');
        $data = $request->validate(['rows' => ['required', 'array', 'min:1', 'max:100']]);
        $requests->delete((string) $request->user()->id, $data['rows']);

        return redirect($this->returnTo($request))->with('status', __('calls.deleted'));
    }

    private function returnTo(Request $request, string $fallback = '/admin/call-requests'): string
    {
        $value = $request->input('return_to');

        return is_string($value) && strlen($value) <= 2000 && preg_match('/^\/admin\/call-requests(?:\?[^\r\n]*)?$/D', $value) === 1 ? $value : $fallback;
    }

    /** @return array<string, mixed> */
    private function shell(Request $request, ?string $current = null): array
    {
        $breadcrumbs = [['label' => __('navigation.dashboard'), 'href' => '/admin'], ['label' => __('calls.title'), 'href' => $current === null ? null : '/admin/call-requests']];
        if ($current !== null) {
            $breadcrumbs[] = ['label' => $current, 'href' => null];
        }

        return ['navigation' => app(AdminNavigation::class)->forUser($request->user()), 'breadcrumbs' => $breadcrumbs];
    }
}
