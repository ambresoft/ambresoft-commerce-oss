<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Customer\Application\Contract\CustomerGroupAdministration;
use App\Customer\Infrastructure\CustomerGroupInput;
use App\Customer\Infrastructure\CustomerQueries;
use App\Localization\Infrastructure\LocaleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class CustomerGroupController
{
    public function index(Request $request, CustomerQueries $queries): Response
    {
        Gate::authorize('customer-groups.view');

        return Inertia::render('Admin/CustomerGroups/Index', [...$this->shell($request), 'groups' => $queries->groupListing()]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('customer-groups.manage');

        return Inertia::render('Admin/CustomerGroups/Form', [...$this->shell($request, __('customers.group_create')), 'group' => null, 'others' => [], 'defaultLocale' => app(LocaleCatalog::class)->defaultCode()]);
    }

    public function store(Request $request, CustomerGroupInput $input, CustomerGroupAdministration $groups): RedirectResponse
    {
        Gate::authorize('customer-groups.manage');
        $id = $groups->create((string) $request->user()->id, $input->data($request->all()));

        return redirect('/admin/customer-groups/'.$id.'/edit')->with('status', __('customers.group_saved'));
    }

    public function edit(Request $request, CustomerQueries $queries, string $group): Response
    {
        Gate::authorize('customer-groups.view');
        $editor = $queries->groupEditor($group) ?? abort(404);
        // Active groups that can receive this group's members on deletion.
        $others = array_values(array_filter($queries->groupOptions(), fn (array $g): bool => $g['id'] !== $group && $g['active']));

        return Inertia::render('Admin/CustomerGroups/Form', [...$this->shell($request, __('customers.group_edit')), 'group' => $editor, 'others' => $others, 'defaultLocale' => app(LocaleCatalog::class)->defaultCode()]);
    }

    public function update(Request $request, CustomerGroupInput $input, CustomerGroupAdministration $groups, string $group): RedirectResponse
    {
        Gate::authorize('customer-groups.manage');
        $version = $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];
        $groups->update((string) $request->user()->id, $group, (int) $version, $input->data($request->except('version')));

        return redirect('/admin/customer-groups/'.$group.'/edit')->with('status', __('customers.group_saved'));
    }

    public function destroy(Request $request, CustomerGroupAdministration $groups, string $group): RedirectResponse
    {
        Gate::authorize('customer-groups.manage');
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'reassign_to' => ['nullable', 'integer', 'min:1']]);
        $groups->delete((string) $request->user()->id, $group, (int) $data['version'], isset($data['reassign_to']) ? (string) $data['reassign_to'] : null);

        return redirect('/admin/customer-groups')->with('status', __('customers.group_deleted'));
    }

    /** @return array<string, mixed> */
    private function shell(Request $request, ?string $current = null): array
    {
        $breadcrumbs = [['label' => __('navigation.dashboard'), 'href' => '/admin'], ['label' => __('customers.groups_title'), 'href' => $current === null ? null : '/admin/customer-groups']];
        if ($current !== null) {
            $breadcrumbs[] = ['label' => $current, 'href' => null];
        }

        return ['navigation' => app(AdminNavigation::class)->forUser($request->user()), 'breadcrumbs' => $breadcrumbs];
    }
}
