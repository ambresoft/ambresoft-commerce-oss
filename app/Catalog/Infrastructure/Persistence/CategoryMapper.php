<?php

namespace App\Catalog\Infrastructure\Persistence;

use App\Catalog\Domain\Model\Category;
use App\Shared\Domain\Model\LocalizedContent;

final class CategoryMapper
{
    public function toDomain(CategoryRecord $record): Category
    {
        $translations = [];
        if ($record->relationLoaded('translations')) {
            foreach ($record->translations as $translation) {
                $code = $translation->locale->code;
                $translations[$code] = new LocalizedContent($code, $translation->only(['name', 'description', 'seo_title', 'seo_description', 'slug', 'image_alt', 'meta_keywords', 'age_warning_message']));
            }
        }

        return new Category((string) $record->id, $record->parent_id === null ? null : (string) $record->parent_id, $record->position, $record->status, $record->system_key, $record->lock_version, $translations, $record->age_verification ?? false, $record->age_limit);
    }
}
