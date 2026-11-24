<?php

namespace App\Catalog\Application\Contract;

use App\Catalog\Application\Command\VariationGroupData;

/** Variation Groups connect complete standalone products; they never own product data. */
interface VariationGroupAdministration
{
    /** Create a group with its first member and defining features; returns the group ID. */
    public function create(string $actor, VariationGroupData $data): string;

    public function listing(string $actor, string $group, VariationGroupData $data): void;

    public function rename(string $actor, string $group, VariationGroupData $data): void;

    /** Change the defining feature set (impact preview, then confirmation). */
    public function configure(string $actor, string $group, VariationGroupData $data): void;

    /** Change only the selector order of the current defining features. */
    public function reorder(string $actor, string $group, VariationGroupData $data): void;

    /** Add existing standalone products, all-or-nothing. */
    public function add(string $actor, string $group, VariationGroupData $data): void;

    /** Detach one member; disbands the group when it was the last one. */
    public function remove(string $actor, string $group, string $product, VariationGroupData $data): void;

    /** Move a member to another group without changing product data. */
    public function transfer(string $actor, string $group, string $product, VariationGroupData $data): void;

    /** Remove the group; every member becomes standalone and unchanged. */
    public function disband(string $actor, string $group, VariationGroupData $data): void;

    /** Duplicate a member with an explicit unused combination; returns the new standalone product ID. */
    public function createVariation(string $actor, string $group, VariationGroupData $data): string;

    /**
     * Called by product saves: join the intended group when the product is now complete and valid.
     *
     * @return array{status: 'none'|'joined'|'pending', group?: string, name?: string, problems?: list<string>}
     */
    public function joinIntended(string $actor, string $product): array;

    /** Called by product trash: detach the member in the same transaction. */
    public function detachForTrash(string $actor, string $product): void;
}
