<?php

namespace App\Localization\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

final class LocaleRecord extends Model
{
    protected $table = 'locales';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_default' => 'boolean'];
    }
}
