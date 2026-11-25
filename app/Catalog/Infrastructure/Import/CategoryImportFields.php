<?php

namespace App\Catalog\Infrastructure\Import;

use App\Localization\Infrastructure\LocaleCatalog;

final class CategoryImportFields
{
    /** Exact persisted merchant fields, shared by mapping, headers and template. */
    public const TRANSLATED = ['name', 'description', 'seo_title', 'seo_description', 'slug', 'image_alt', 'meta_keywords', 'age_warning_message'];

    /** @return list<string> */
    public function columns(): array
    {
        $columns = ['category_id', 'category_path', 'position', 'status', 'age_verification', 'age_limit', 'exclude_from_discovery', 'customer_groups', 'remove_image', 'image_url'];
        foreach (app(LocaleCatalog::class)->active() as $locale) {
            foreach (self::TRANSLATED as $field) {
                $columns[] = $field.'.'.$locale->code;
            }
        }

        return $columns;
    }

    /** @return array<string, string> */
    public function example(string $separator): array
    {
        $default = app(LocaleCatalog::class)->defaultCode();
        $row = array_fill_keys($this->columns(), '');
        $name = $default === 'el' ? 'Κατηγορία παραδείγματος' : 'Example category';
        $row['category_path'] = $name;
        $row['name.'.$default] = $name;
        if (array_key_exists('name.el', $row)) {
            $row['name.el'] = 'Κατηγορία παραδείγματος';
        }
        foreach (app(LocaleCatalog::class)->active() as $locale) {
            foreach (self::TRANSLATED as $field) {
                $row[$field.'.'.$locale->code] = match ($field) {
                    'name' => $locale->code === 'el' ? 'Κατηγορία παραδείγματος' : 'Example category',
                    'slug' => 'example-category',
                    default => $locale->code === 'el' ? 'Κείμενο παραδείγματος' : 'Example text',
                };
            }
        }
        $row['position'] = '1';
        $row['status'] = 'active';
        $row['age_verification'] = 'false';
        $row['exclude_from_discovery'] = 'false';
        $row['remove_image'] = 'false';

        return $row;
    }
}
