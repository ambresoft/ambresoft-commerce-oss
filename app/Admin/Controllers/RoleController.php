<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Admin\Requests\SaveRoleRequest;
use App\Authorization\Application\Command\ManageStaffHandler;
use App\Authorization\Domain\Exception\UnsafeAccessChange;
use App\Authorization\Infrastructure\AccessChecker;
use App\Authorization\Infrastructure\PermissionRegistry;
use App\Authorization\Infrastructure\Persistence\RoleRecord;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class RoleController
{
    public function index(Request $request, AdminNavigation $navigation): Response
    {
        Gate::authorize('roles.view');
        $request->validate(['page' => ['nullable', 'integer', 'min:1', 'max:100000']]);
        $roles = RoleRecord::withCount('permissions')->orderBy('name')->orderBy('id')->paginate(15)
            ->through(fn (RoleRecord $role): array => ['id' => (string) $role->id, 'name' => $role->name, 'slug' => $role->slug, 'description' => $role->description, 'super' => $role->is_super_admin, 'permissionCount' => $role->permissions_count, 'editUrl' => route('admin.roles.edit', $role->id, false)]);

        return Inertia::render('Admin/Roles/Index', [...$this->shell($request, $navigation), 'roles' => $roles]);
    }

    public function create(Request $request, AdminNavigation $navigation): Response
    {
        Gate::authorize('roles.create');
        Gate::authorize('roles.assign-permissions');

        return $this->form($request, $navigation, null);
    }

    public function edit(Request $request, AdminNavigation $navigation, string $role): Response
    {
        Gate::authorize('roles.update');
        Gate::authorize('roles.assign-permissions');

        return $this->form($request, $navigation, RoleRecord::with('permissions')->findOrFail($role));
    }

    private function form(Request $request, AdminNavigation $navigation, ?RoleRecord $role): Response
    {
        $super = app(AccessChecker::class)->isSuper($request->user());
        abort_if(! $super && ($role !== null && $role->permissions->whereIn('name', AccessChecker::SUPER_DELEGATED)->isNotEmpty()), 403);

        return Inertia::render('Admin/Roles/Form', [
            ...$this->shell($request, $navigation),
            'role' => $role ? ['id' => (string) $role->id, 'name' => $role->name, 'slug' => $role->slug, 'description' => $role->description ?? '', 'super' => $role->is_super_admin, 'permissions' => $role->permissions->pluck('name')->reject(fn (string $name): bool => in_array($name, ['audit.view', 'account-security.view'], true))->values()->all()] : null,
            'permissions' => collect(app(PermissionRegistry::class)->all())->filter(fn (string $label, string $ability): bool => ! in_array($ability, ['audit.view', 'account-security.view'], true) && (! in_array($ability, AccessChecker::SUPER_DELEGATED, true) || $super) && Gate::allows($ability))->map(fn (string $label, string $ability): array => ['name' => $ability, 'label' => __($label)])->values(),
        ]);
    }

    public function store(SaveRoleRequest $request, ManageStaffHandler $handler): RedirectResponse
    {
        return $this->save($request, $handler, null);
    }

    public function update(SaveRoleRequest $request, ManageStaffHandler $handler, string $role): RedirectResponse
    {
        return $this->save($request, $handler, $role);
    }

    private function save(SaveRoleRequest $request, ManageStaffHandler $handler, ?string $role): RedirectResponse
    {
        try {
            $handler->saveRole((string) $request->user()->getAuthIdentifier(), $role, $request->toData());
        } catch (UnsafeAccessChange $e) {
            throw ValidationException::withMessages(['access' => __($e->getMessage())]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['slug' => __('validation.unique', ['attribute' => __('roles.slug')])]);
        }

        return to_route(Gate::allows('roles.view') ? 'admin.roles.index' : 'admin.dashboard')->with('status', __('roles.saved'));
    }

    public function destroy(Request $request, ManageStaffHandler $handler, string $role): RedirectResponse
    {
        Gate::authorize('roles.delete');
        try {
            $handler->deleteRole((string) $request->user()->getAuthIdentifier(), $role);
        } catch (UnsafeAccessChange $e) {
            throw ValidationException::withMessages(['access' => __($e->getMessage())]);
        }

        return to_route(Gate::allows('roles.view') ? 'admin.roles.index' : 'admin.dashboard')->with('status', __('roles.deleted'));
    }

    /** @return array<string, mixed> */
    private function shell(Request $request, AdminNavigation $navigation): array
    {
        return ['navigation' => $navigation->forUser($request->user()), 'breadcrumbs' => [
            ['label' => __('navigation.dashboard'), 'href' => route('admin.dashboard', [], false)],
            ['label' => __('navigation.roles'), 'href' => null],
        ]];
    }
}
