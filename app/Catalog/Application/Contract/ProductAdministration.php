<?php

namespace App\Catalog\Application\Contract;

use App\Catalog\Application\Command\SaveProductData;

interface ProductAdministration
{
    public function draft(string $actor): string;

    public function save(string $actor, string $id, SaveProductData $data): void;

    public function duplicate(string $actor, string $id, int $version): string;

    public function lifecycle(string $actor, string $id, int $version, string $action, string $confirmation = ''): void;

    /** @param list<string> $ids
     * @return list<array{id: string, success: bool, reason: string}>
     */
    public function bulk(string $actor, array $ids, string $status): array;
}
