<?php

namespace App\Admin\DataTransfer;

use App\Shared\Infrastructure\Export\ExportSource;
use LogicException;

/** Registry of exportable entities, keyed by the URL segment of the generic export screen. */
final class DataTransferExports
{
    /** @var array<string, class-string<ExportSource>> */
    private array $sources = [];

    /** @param class-string<ExportSource> $source */
    public function add(string $entity, string $source): void
    {
        if (isset($this->sources[$entity]) || preg_match('/^[a-z][a-z-]*$/D', $entity) !== 1) {
            throw new LogicException('Exports require a unique lowercase entity key.');
        }
        $this->sources[$entity] = $source;
    }

    public function get(string $entity): ExportSource
    {
        abort_unless(isset($this->sources[$entity]), 404);

        return app($this->sources[$entity]);
    }
}
