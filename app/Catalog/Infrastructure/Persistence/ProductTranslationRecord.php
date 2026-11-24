<?php

namespace App\Catalog\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

final class ProductTranslationRecord extends Model
{
    protected $table = 'product_translations';

    protected $guarded = ['id'];
}
