<?php

namespace App\Catalog\Infrastructure\Import;

use App\Localization\Infrastructure\LocaleCatalog;

final class BrandImportFields
{
    public const TEXT = ['name', 'description', 'image_alt', 'banner_alt'];

    /** @return list<string> */
    public function columns(): array
    {
        $columns = ['code', 'status', 'position', 'banner_link', 'image_url', 'banner_image_url', 'attachment_url', 'attachment_name', 'remove_image', 'remove_banner_image', 'remove_attachments'];
        foreach (app(LocaleCatalog::class)->active() as $locale) {
            foreach (self::TEXT as $field) {
                $columns[] = $field.'.'.$locale->code;
            }
        }

        return $columns;
    }

    /** @return array<string,string> */
    public function example(): array
    {
        $row = array_fill_keys($this->columns(), '');
        foreach (app(LocaleCatalog::class)->active() as $locale) {
            $row['name.'.$locale->code] = $locale->code === 'el' ? 'Μάρκα παραδείγματος' : 'Example brand';
        }

        return array_replace($row, ['code' => 'example-brand', 'status' => 'disabled', 'position' => '0']);
    }
}
