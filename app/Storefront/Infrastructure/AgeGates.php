<?php

namespace App\Storefront\Infrastructure;

use App\Catalog\Infrastructure\CategoryQueries;

/**
 * Whether a category's age check stands between the visitor and a page: its own limit or the strictest of its
 * ancestors', unless the visitor has already confirmed an age at least that high. Category landing pages and the
 * product pages of products in such a category show only the gate until then.
 */
final class AgeGates
{
    /** @return array{age:int,message:string}|null The limit and the warning of the category that sets it (empty: the theme's own words). */
    public function forCategory(?string $categoryId, string $locale, int $confirmed): ?array
    {
        if ($categoryId === null) {
            return null;
        }
        $restriction = app(CategoryQueries::class)->parentAgeRestriction($categoryId);
        if (! $restriction['verification'] || $confirmed >= $restriction['ageLimit']) {
            return null;
        }
        $content = app(BlockContent::class);
        $texts = $content->translations('category_translations', 'category_id', [$restriction['sourceCategoryId']]);

        return ['age' => (int) $restriction['ageLimit'], 'message' => trim($content->text($texts[$restriction['sourceCategoryId']] ?? [], $locale, 'age_warning_message'))];
    }
}
