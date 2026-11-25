<?php

namespace App\Geography\Application\Contract;

interface RegionCsvImportInterface
{
    /** @return array<string, mixed> */
    public function preview(string $actorId, string $file, string $delimiter): array;

    /** @param array<string, mixed> $preview
     * @return array<string, mixed>
     */
    public function confirm(string $actorId, string $file, string $delimiter, array $preview): array;
}
