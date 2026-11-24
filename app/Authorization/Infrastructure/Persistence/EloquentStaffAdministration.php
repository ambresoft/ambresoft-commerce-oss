<?php

namespace App\Authorization\Infrastructure\Persistence;

use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Authorization\Application\Command\SaveRoleData;
use App\Authorization\Application\Command\SaveStaffData;
use App\Authorization\Application\Contract\StaffAdministrationInterface;
use App\Authorization\Domain\Exception\UnsafeAccessChange;
use App\Authorization\Domain\Service\AccessSafety;
use App\Authorization\Infrastructure\AccessChecker;
use App\Authorization\Infrastructure\PermissionRegistry;
use App\Identity\Infrastructure\Persistence\UserRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final readonly class EloquentStaffAdministration implements StaffAdministrationInterface
{
    public function __construct(private AccessChecker $access, private PermissionRegistry $permissions) {}

    private function lock(): void
    {
        // Serialize staff access mutations, including operator bootstrap, across workers.
        DB::select('SELECT pg_advisory_xact_lock(2142512)');
    }

    private function actor(string $id, string $ability): UserRecord
    {
        app(AuditContext::class)->actor($id);
        $actor = UserRecord::findOrFail($id);
        Gate::forUser($actor)->authorize($ability);

        return $actor;
    }

    /** @param list<string> $names */
    private function assertGrantable(UserRecord $actor, array $names): void
    {
        foreach ($names as $name) {
            if (! isset($this->permissions->all()[$name]) || ! Gate::forUser($actor)->allows($name)) {
                throw new UnsafeAccessChange('authorization.cannot_grant');
            }
        }
    }

    public function saveUser(string $actorId, ?string $targetId, SaveStaffData $data): string
    {
        return app(AuditLogger::class)->operation('user', $actorId, function () use ($actorId, $targetId, $data): string {
            return DB::transaction(function () use ($actorId, $targetId, $data): string {
                $this->lock();
                $actor = $this->actor($actorId, $targetId === null ? 'users.create' : 'users.update');
                $user = $targetId === null ? new UserRecord : UserRecord::findOrFail($targetId);
                $wasSuper = $user->exists && $this->access->isSuper($user);
                if ($wasSuper && ! $this->access->isSuper($actor)) {
                    throw new UnsafeAccessChange('authorization.protected_role');
                }
                if ($user->exists) {
                    $this->assertGrantable($actor, DB::table('role_user')
                        ->join('permission_role', 'permission_role.role_id', '=', 'role_user.role_id')
                        ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
                        ->where('user_id', $user->id)->distinct()->pluck('permissions.name')->all());
                }
                $roleIds = $data->roleIds;
                $willBeSuper = $wasSuper;
                if ($roleIds !== null) {
                    Gate::forUser($actor)->authorize('users.assign-roles');
                    $roles = RoleRecord::whereIn('id', $roleIds)->with('permissions')->get();
                    if ($roles->count() !== count(array_unique($roleIds))) {
                        throw new UnsafeAccessChange('authorization.cannot_grant');
                    }
                    if (! $this->access->isSuper($actor)) {
                        $current = $user->exists ? DB::table('permission_role')->join('role_user', 'role_user.role_id', '=', 'permission_role.role_id')
                            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')->where('role_user.user_id', $user->id)->pluck('permissions.name')->all() : [];
                        $proposed = $roles->flatMap(fn (RoleRecord $role) => $role->permissions->pluck('name'))->all();
                        if (array_intersect(AccessChecker::SUPER_DELEGATED, [...array_diff($current, $proposed), ...array_diff($proposed, $current)]) !== []) {
                            throw new UnsafeAccessChange('authorization.cannot_grant');
                        }
                    }
                    $willBeSuper = $roles->contains('is_super_admin', true);
                    foreach ($roles as $role) {
                        if ($role->is_super_admin && ! $this->access->isSuper($actor)) {
                            throw new UnsafeAccessChange('authorization.protected_role');
                        }
                        $this->assertGrantable($actor, $role->permissions->pluck('name')->all());
                    }
                }
                if ($user->exists) {
                    AccessSafety::assertUserChange($actorId, (string) $user->id, $data->active, $wasSuper && $user->is_active && $user->hasVerifiedEmail(), $willBeSuper, $this->superCount());
                }
                $emailChanged = $user->exists && $user->email !== $data->email;
                if ($user->exists && $user->is_active && ! $data->active) {
                    Gate::forUser($actor)->authorize('users.deactivate');
                }
                if ($emailChanged) {
                    Gate::forUser($actor)->authorize('users.set-password');
                }
                // A privileged identity cannot lose verification via email editing.
                if ($emailChanged && $wasSuper) {
                    throw new UnsafeAccessChange('authorization.super_email');
                }
                $user->name = $data->name;
                $user->email = $data->email;
                $user->is_active = $data->active;
                if ($data->password !== null) {
                    Gate::forUser($actor)->authorize('users.set-password');
                    $user->password = $data->password;
                    $user->remember_token = Str::random(60);
                } elseif (! $user->exists) {
                    $user->password = Str::random(64);
                }
                if ($emailChanged) {
                    $user->email_verified_at = null;
                    $user->remember_token = Str::random(60);
                }
                if (! $data->active) {
                    $user->remember_token = Str::random(60);
                }
                $user->save();
                if ($roleIds !== null) {
                    $user->roles()->sync($roleIds);
                }

                return (string) $user->id;
            });

        });
    }

    public function deactivateUser(string $actorId, string $targetId): void
    {
        app(AuditLogger::class)->operation('user', $actorId, function () use ($actorId, $targetId): void {
            DB::transaction(function () use ($actorId, $targetId): void {
                $this->lock();
                $actor = $this->actor($actorId, 'users.deactivate');
                $target = UserRecord::findOrFail($targetId);
                if ($this->access->isSuper($target) && ! $this->access->isSuper($actor)) {
                    throw new UnsafeAccessChange('authorization.protected_role');
                }
                AccessSafety::assertUserChange($actorId, $targetId, false, $target->is_active && $target->hasVerifiedEmail() && $this->access->isSuper($target), false, $this->superCount());
                $target->is_active = false;
                $target->remember_token = Str::random(60);
                $target->save();
            });

        });
    }

    public function saveRole(string $actorId, ?string $roleId, SaveRoleData $data): string
    {
        return app(AuditLogger::class)->operation('user', $actorId, function () use ($actorId, $roleId, $data): string {
            return DB::transaction(function () use ($actorId, $roleId, $data): string {
                $this->lock();
                $actor = $this->actor($actorId, $roleId === null ? 'roles.create' : 'roles.update');
                Gate::forUser($actor)->authorize('roles.assign-permissions');
                $role = $roleId === null ? new RoleRecord : RoleRecord::findOrFail($roleId);
                if ($role->is_super_admin) {
                    throw new UnsafeAccessChange('authorization.protected_role');
                }
                if (! $this->access->isSuper($actor) && (array_intersect(AccessChecker::SUPER_DELEGATED, $data->permissions) !== []
                    || ($role->exists && $role->permissions()->whereIn('name', AccessChecker::SUPER_DELEGATED)->exists()))) {
                    throw new UnsafeAccessChange('authorization.cannot_grant');
                }
                // Editing a stronger role would indirectly grant/revoke powers outside the actor's authority.
                if ($role->exists) {
                    $this->assertGrantable($actor, $role->permissions()->pluck('name')->all());
                }
                if (array_intersect(['audit.view', 'account-security.view'], $data->permissions) !== []) {
                    throw new UnsafeAccessChange('authorization.cannot_grant');
                }
                $this->assertGrantable($actor, $data->permissions);
                $role->fill(['name' => $data->name, 'slug' => $data->slug, 'description' => $data->description])->save();
                $ids = PermissionRecord::whereIn('name', $data->permissions)->pluck('id');
                if ($ids->count() !== count($data->permissions)) {
                    throw new UnsafeAccessChange('authorization.sync_required');
                }
                $role->permissions()->sync($ids);

                return (string) $role->id;
            });

        });
    }

    public function deleteRole(string $actorId, string $roleId): void
    {
        app(AuditLogger::class)->operation('user', $actorId, function () use ($actorId, $roleId): void {
            DB::transaction(function () use ($actorId, $roleId): void {
                $this->lock();
                $actor = $this->actor($actorId, 'roles.delete');
                $role = RoleRecord::findOrFail($roleId);
                if ($role->is_super_admin || DB::table('role_user')->where('role_id', $roleId)->exists()) {
                    throw new UnsafeAccessChange('authorization.role_in_use');
                }
                if (! $this->access->isSuper($actor) && $role->permissions()->whereIn('name', AccessChecker::SUPER_DELEGATED)->exists()) {
                    throw new UnsafeAccessChange('authorization.cannot_grant');
                }
                $this->assertGrantable($actor, $role->permissions()->pluck('name')->all());
                $role->delete();
            });

        });
    }

    private function superCount(): int
    {
        return DB::table('users')->where('is_active', true)->whereNotNull('email_verified_at')
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('role_user')->join('roles', 'roles.id', '=', 'role_user.role_id')->whereColumn('role_user.user_id', 'users.id')->where('roles.is_super_admin', true))->count();
    }
}
