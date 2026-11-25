<?php

namespace App\Catalog\Infrastructure\Import;

use App\Shared\Infrastructure\Import\CsvReader;
use Generator;

final class CategoryCsvReader
{
    /** @return Generator<int, list<string>> Logical CSV record numbers, header = 1. */
    public function rows(string $file, string $delimiter): Generator
    {
        yield from (new CsvReader)->rows($file, $delimiter, 'category-import', 'category_import');
    }
}
