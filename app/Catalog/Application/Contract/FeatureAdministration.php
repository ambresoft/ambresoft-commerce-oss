<?php

namespace App\Catalog\Application\Contract;

use App\Catalog\Application\Command\SaveFeatureData;

interface FeatureAdministration
{
    public function save(string $actor, ?string $id, SaveFeatureData $data): string;

    public function action(string $actor, string $id, SaveFeatureData $data): void;

    public function option(string $actor, string $feature, ?string $id, SaveFeatureData $data): string;

    public function group(string $actor, ?string $id, SaveFeatureData $data): string;

    public function reorder(string $actor, SaveFeatureData $data): void;
}
