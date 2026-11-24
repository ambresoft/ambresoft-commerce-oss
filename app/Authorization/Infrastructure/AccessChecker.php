<?php

namespace App\Authorization\Infrastructure;

use App\Identity\Infrastructure\Persistence\UserRecord;
use Illuminate\Support\Facades\DB;

final class AccessChecker
{
    /** Only super administrators may delegate staff-management authority. */
    public const SUPER_DELEGATED = ['users.create', 'users.update', 'users.deactivate', 'users.set-password', 'users.assign-roles', 'roles.create', 'roles.update', 'roles.delete', 'roles.assign-permissions'];

    public function isSuper(UserRecord $user): bool
    {
        return $user->exists && DB::table('role_user')->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->where('user_id', $user->getKey())->where('roles.is_super_admin', true)->exists();
    }

    public function allows(UserRecord $user, string $ability): bool
    {
        // One fresh persisted-state query per gate; adding capabilities must not multiply role lookups.
        return $user->exists && DB::table('users')->where('users.id', $user->getKey())
            ->where('users.is_active', true)->whereNotNull('users.email_verified_at')
            ->whereExists(function ($roles) use ($ability): void {
                $roles->selectRaw('1')->from('role_user')->join('roles', 'roles.id', '=', 'role_user.role_id')
                    ->whereColumn('role_user.user_id', 'users.id')
                    ->where(function ($granted) use ($ability): void {
                        $granted->where('roles.is_super_admin', true);
                        // Audit history is reserved for active, verified super administrators.
                        // Legacy permission grants cannot confer access.
                        if (in_array($ability, ['audit.view', 'account-security.view'], true)) {
                            return;
                        }
                        $granted->orWhereExists(function ($permissions) use ($ability): void {
                            $permissions->selectRaw('1')->from('permission_role')->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
                                ->whereColumn('permission_role.role_id', 'roles.id')->where('permissions.name', $ability);
                        });
                    });
            })->exists();
    }
}
