<?php

namespace App\Authorization\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

final class PermissionRecord extends Model
{
    protected $table = 'permissions';

    protected $fillable = ['name'];
}
