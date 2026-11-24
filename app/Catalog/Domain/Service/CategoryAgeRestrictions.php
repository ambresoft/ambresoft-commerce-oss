<?php

namespace App\Catalog\Domain\Service;

use App\Catalog\Domain\Model\Category;

final class CategoryAgeRestrictions
{
    /**
     * @param  iterable<Category>  $path  Root through selected category, in that order.
     * @return array{verification: bool, ageLimit: ?int, sourceCategoryId: ?string}
     */
    public function forPath(iterable $path): array
    {
        $limit = null;
        $source = null;
        foreach ($path as $category) {
            if ($category->systemKey === null && $category->ageVerification && $category->ageLimit !== null && ($limit === null || $category->ageLimit >= $limit)) {
                $limit = $category->ageLimit;
                $source = $category->id;
            }
        }

        return ['verification' => $limit !== null, 'ageLimit' => $limit, 'sourceCategoryId' => $source];
    }
}
