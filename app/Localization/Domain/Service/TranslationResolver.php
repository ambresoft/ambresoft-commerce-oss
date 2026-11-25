<?php

namespace App\Localization\Domain\Service;

use App\Shared\Domain\Contract\Translatable;
use App\Shared\Domain\Model\LocalizedContent;

final class TranslationResolver
{
    public function resolveTranslation(Translatable $entity, string $requested, string $default = 'en'): ?LocalizedContent
    {
        foreach (array_unique([$requested, $default, 'en']) as $locale) {
            $translation = $entity->translation($locale);
            if ($translation !== null) {
                return $translation;
            }
        }

        return null;
    }
}
