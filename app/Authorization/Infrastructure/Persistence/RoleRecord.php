<?php

namespace App\Authorization\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class RoleRecord extends Model
{
    protected $table = 'roles';

    protected $fillable = ['name', 'slug', 'description'];

    protected function casts(): array
    {
        return ['is_super_admin' => 'boolean'];
    }

    /** @return BelongsToMany<PermissionRecord, $this> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(PermissionRecord::class, 'permission_role', 'role_id', 'permission_id');
    }
}
