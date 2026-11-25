<?php

namespace App\Shared\Infrastructure\Export;

/**
 * One exportable entity. The generic export screen and download only know this contract;
 * each import-enabled module supplies its columns (the same headers its import accepts)
 * and the rows for a filtered or hand-picked record set.
 */
interface ExportSource
{
    /** Capability required to export this entity. */
    public function ability(): string;

    /** Translation key of the entity's title. */
    public function title(): string;

    /** Named route of the entity's list screen (back link). */
    public function indexRoute(): string;

    /** @return list<string> Exportable column keys, in file order. */
    public function columns(): array;

    /** @return array<string, list<mixed>> Validation rules for the list filters this export honours. */
    public function filterRules(): array;

    /**
     * @param  list<string>  $columns  Selected columns, already restricted to columns().
     * @param  array<string, mixed>  $filters  Validated list filters; empty means every record.
     * @param  list<string>  $ids  Explicitly selected records; empty means no restriction.
     * @return iterable<list<string>> One string cell per selected column.
     */
    public function rows(array $columns, array $filters, array $ids): iterable;
}
