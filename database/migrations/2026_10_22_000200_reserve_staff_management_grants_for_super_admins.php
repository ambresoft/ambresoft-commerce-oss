<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::select('SELECT pg_advisory_xact_lock(2142512)');
        // Reset existing authority once; later grants are deliberate super-admin decisions.
        DB::table('permission_role')->whereIn('role_id', DB::table('roles')->where('is_super_admin', false)->select('id'))
            ->whereIn('permission_id', DB::table('permissions')->whereIn('name', [
                'users.create', 'users.update', 'users.deactivate', 'users.set-password', 'users.assign-roles',
                'roles.create', 'roles.update', 'roles.delete', 'roles.assign-permissions',
            ])->select('id'))->delete();
    }

    public function down(): void
    {
        // Do not restore broad access automatically; super administrators can grant it deliberately.
    }
};
