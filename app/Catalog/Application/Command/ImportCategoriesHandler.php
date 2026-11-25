<?php

namespace App\Catalog\Application\Command;

use App\Catalog\Application\Contract\CategoryCsvImportInterface;

final readonly class ImportCategoriesHandler
{
    public function __construct(private CategoryCsvImportInterface $importer) {}

    /** @return array<string, mixed> */
    public function preview(string $actorId, string $file, string $delimiter, string $pathSeparator): array
    {
        return $this->importer->preview($actorId, $file, $delimiter, $pathSeparator);
    }

    /** @param array<string, mixed> $preview
     * @return array<string, mixed>
     */
    public function confirm(string $actorId, string $file, string $delimiter, string $pathSeparator, array $preview): array
    {
        return $this->importer->confirm($actorId, $file, $delimiter, $pathSeparator, $preview);
    }
}
