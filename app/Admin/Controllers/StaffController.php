<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Admin\Requests\SaveUserRequest;
use App\Authorization\Application\Command\ManageStaffHandler;
use App\Authorization\Domain\Exception\UnsafeAccessChange;
use App\Authorization\Infrastructure\AccessChecker;
use App\Authorization\Infrastructure\Persistence\RoleRecord;
use App\Identity\Infrastructure\Auth\StaffInvitations;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Identity\Infrastructure\Security\AccountSecurity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class StaffController
{
    public function index(Request $request, AdminNavigation $navigation): Response
    {
        Gate::authorize('users.view');
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:120'], 'status' => ['nullable', Rule::in(['active', 'inactive'])], 'role' => ['nullable', 'integer', 'exists:roles,id'], 'page' => ['nullable', 'integer', 'min:1', 'max:100000']]);
        $actorSuper = app(AccessChecker::class)->isSuper($request->user());
        $canEdit = Gate::allows('users.update');
        $canDeactivate = Gate::allows('users.deactivate');
        $users = UserRecord::query()->with('roles')
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(fn ($q) => $q->where('name', 'ilike', '%'.$search.'%')->orWhere('email', 'ilike', '%'.$search.'%')))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('is_active', $status === 'active'))
            ->when($filters['role'] ?? null, fn ($q, $role) => $q->whereHas('roles', fn ($q) => $q->where('roles.id', $role)))
            ->orderBy('name')->orderBy('id')->paginate(15)->withQueryString()
            ->through(fn (UserRecord $user): array => ['id' => (string) $user->id, 'name' => $user->name, 'email' => $user->email, 'active' => $user->is_active, 'verified' => $user->hasVerifiedEmail(), 'roles' => $user->roles->map(fn (RoleRecord $role): array => ['id' => (string) $role->id, 'name' => $role->name])->all(), 'editUrl' => $canEdit && ($actorSuper || ! $user->roles->contains('is_super_admin', true)) ? route('admin.users.edit', $user->id, false) : null, 'canDeactivate' => $canDeactivate && $user->is_active && (string) $user->id !== (string) $request->user()->id && ($actorSuper || ! $user->roles->contains('is_super_admin', true))]);

        return Inertia::render('Admin/Users/Index', [
            ...$this->shell($request, $navigation), 'users' => $users, 'filters' => $filters,
            'roles' => RoleRecord::orderBy('name')->get()->map(fn (RoleRecord $role): array => ['id' => (string) $role->id, 'name' => $role->name]),
        ]);
    }

    public function create(Request $request, AdminNavigation $navigation): Response
    {
        Gate::authorize('users.create');

        return $this->form($request, $navigation, null);
    }

    public function edit(Request $request, AdminNavigation $navigation, string $user): Response
    {
        Gate::authorize('users.update');

        $target = UserRecord::with('roles')->findOrFail($user);
        abort_if(app(AccessChecker::class)->isSuper($target) && ! app(AccessChecker::class)->isSuper($request->user()), 403);

        return $this->form($request, $navigation, $target);
    }

    private function form(Request $request, AdminNavigation $navigation, ?UserRecord $user): Response
    {
        return Inertia::render('Admin/Users/Form', [
            ...$this->shell($request, $navigation),
            'security' => $user && Gate::allows('account-security.view') ? app(AccountSecurity::class)->summary((string) $user->id) : null,
            'staff' => $user ? ['id' => (string) $user->id, 'name' => $user->name, 'email' => $user->email, 'active' => $user->is_active, 'verified' => $user->hasVerifiedEmail(), 'roleIds' => $user->roles->map(fn (RoleRecord $role): string => (string) $role->id)->all()] : null,
            'roles' => RoleRecord::query()->when(! app(AccessChecker::class)->isSuper($request->user()), fn ($q) => $q->where('is_super_admin', false))->orderBy('name')->get()->map(fn (RoleRecord $role): array => ['id' => (string) $role->id, 'name' => $role->name]),
        ]);
    }

    public function store(SaveUserRequest $request, ManageStaffHandler $handler): RedirectResponse
    {
        $id = $this->save($request, $handler, null);
        $user = UserRecord::findOrFail($id);
        if ($request->boolean('invite')) {
            app(StaffInvitations::class)->send($user);
        } else {
            $user->sendEmailVerificationNotification();
        }

        return to_route(Gate::allows('users.view') ? 'admin.users.index' : 'admin.dashboard')->with('status', __('users.saved'));
    }

    public function update(SaveUserRequest $request, ManageStaffHandler $handler, string $user): RedirectResponse
    {
        $oldEmail = UserRecord::findOrFail($user)->email;
        $this->save($request, $handler, $user);
        $updated = UserRecord::findOrFail($user);
        if ($oldEmail !== $updated->email) {
            $updated->sendEmailVerificationNotification();
        }
        // Rotate the acting browser after access changes; other sessions recheck DB grants.
        $request->session()->regenerate();

        return to_route(Gate::allows('users.view') ? 'admin.users.index' : 'admin.dashboard')->with('status', __('users.saved'));
    }

    private function save(SaveUserRequest $request, ManageStaffHandler $handler, ?string $user): string
    {
        try {
            return $handler->saveUser((string) $request->user()->getAuthIdentifier(), $user, $request->toData());
        } catch (UnsafeAccessChange $e) {
            throw ValidationException::withMessages(['access' => __($e->getMessage())]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => __('validation.unique', ['attribute' => __('common.email')])]);
        }
    }

    public function destroy(Request $request, ManageStaffHandler $handler, string $user): RedirectResponse
    {
        Gate::authorize('users.deactivate');
        try {
            $handler->deactivateUser((string) $request->user()->getAuthIdentifier(), $user);
        } catch (UnsafeAccessChange $e) {
            throw ValidationException::withMessages(['access' => __($e->getMessage())]);
        }

        return to_route(Gate::allows('users.view') ? 'admin.users.index' : 'admin.dashboard')->with('status', __('users.deactivated'));
    }

    public function invite(Request $request, string $user, StaffInvitations $invitations): RedirectResponse
    {
        Gate::authorize('users.update');
        $target = UserRecord::with('roles.permissions')->findOrFail($user);
        abort_if(app(AccessChecker::class)->isSuper($target) && ! app(AccessChecker::class)->isSuper($request->user()), 403);
        foreach ($target->roles as $role) {
            foreach ($role->permissions as $permission) {
                Gate::authorize($permission->name);
            }
        }
        abort_unless($target->is_active && ! $target->hasVerifiedEmail() && $target->roles->isNotEmpty(), 403);
        $invitations->send($target);

        return back()->with('status', __('auth.invitation_sent'));
    }

    /** @return array<string, mixed> */
    private function shell(Request $request, AdminNavigation $navigation): array
    {
        return ['navigation' => $navigation->forUser($request->user()), 'breadcrumbs' => [
            ['label' => __('navigation.dashboard'), 'href' => route('admin.dashboard', [], false)],
            ['label' => __('navigation.users'), 'href' => null],
        ]];
    }
}
