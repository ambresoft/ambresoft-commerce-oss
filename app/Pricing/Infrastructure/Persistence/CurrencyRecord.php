<?php

namespace App\Pricing\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $rate Exact numeric(20,10) string; never cast to float.
 */
final class CurrencyRecord extends Model
{
    protected $table = 'currencies';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'decimals' => 'integer', 'lock_version' => 'integer'];
    }
}
