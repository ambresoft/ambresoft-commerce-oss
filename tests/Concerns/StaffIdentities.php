<?php

namespace Tests\Concerns;

use App\Authorization\Infrastructure\Persistence\PermissionRecord;
use App\Authorization\Infrastructure\Persistence\RoleRecord;
use App\Identity\Infrastructure\Persistence\UserRecord;

trait StaffIdentities
{
    private static int $sequence = 0;

    /** @param list<string> $abilities */
    private function staff(array $abilities = ['admin.access'], bool $super = false): UserRecord
    {
        $number = ++self::$sequence;
        $user = UserRecord::create(['name' => 'Staff '.$number, 'email' => 'staff'.$number.'@example.test', 'password' => 'a long test passphrase']);
        $user->markEmailAsVerified();
        if ($super) {
            $role = RoleRecord::where('is_super_admin', true)->sole();
        } else {
            $role = RoleRecord::create(['name' => 'Test role '.$number, 'slug' => 'test-role-'.$number]);
            $role->permissions()->sync(PermissionRecord::whereIn('name', $abilities)->pluck('id'));
        }
        $user->roles()->attach($role->id);

        return $user;
    }
}
