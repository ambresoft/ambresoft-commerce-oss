<?php

namespace App\Catalog\Infrastructure\Persistence;

use App\Localization\Infrastructure\Persistence\LocaleRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CategoryTranslationRecord extends Model
{
    protected $table = 'category_translations';

    protected $fillable = ['locale_id', 'name', 'description', 'seo_title', 'seo_description', 'slug', 'image_alt', 'meta_keywords', 'age_warning_message'];

    /** @return BelongsTo<LocaleRecord, $this> */
    public function locale(): BelongsTo
    {
        return $this->belongsTo(LocaleRecord::class, 'locale_id');
    }
}
