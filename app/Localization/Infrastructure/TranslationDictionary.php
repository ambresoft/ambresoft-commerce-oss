<?php

namespace App\Localization\Infrastructure;

use Illuminate\Support\Arr;

final readonly class TranslationDictionary
{
    public function __construct(private UiTranslations $ui) {}

    /** @return array<string, string> */
    public function originals(string $locale): array
    {
        $dictionary = [];
        $loader = app('translator')->getLoader();
        foreach ($this->ui->groups() as $group) {
            [$namespace, $file] = str_contains($group, '::') ? explode('::', $group, 2) : [null, $group];
            $values = $loader instanceof OverrideTranslationLoader ? $loader->original($locale, $file, $namespace) : $loader->load($locale, $file, $namespace);
            foreach (Arr::dot($values) as $key => $value) {
                if (is_string($value)) {
                    $dictionary[$group.'.'.$key] = $value;
                }
            }
        }

        return $dictionary;
    }
}
