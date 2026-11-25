<?php

namespace App\Catalog\Application\Contract;

interface CategoryCsvImportInterface
{
    /** @return array<string, mixed> */
    public function preview(string $actorId, string $file, string $delimiter, string $pathSeparator): array;

    /** @param array<string, mixed> $preview
     * @return array<string, mixed>
     */
    public function confirm(string $actorId, string $file, string $delimiter, string $pathSeparator, array $preview): array;
}
