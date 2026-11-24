<?php

namespace App\Storefront\Domain\Service;

use DomainException;

/** Widths are absolute columns; siblings share a row whose capacity is their parent's width. */
final class LayoutRules
{
    /** @param list<array<string,mixed>> $grids
     * @param list<string> $regions */
    public function validate(array $grids, array $regions): void
    {
        $byId = array_column($grids, null, 'id');
        if (count($byId) !== count($grids) || count($grids) > 100) {
            throw new DomainException('Duplicate grids or too many grids.');
        }
        $rows = [];
        $positions = [];
        foreach ($grids as $grid) {
            $parent = $grid['parent_id'] === null ? null : ($byId[$grid['parent_id']] ?? throw new DomainException('Missing parent grid.'));
            if (! in_array($grid['region'], $regions, true) || ($parent !== null && $parent['region'] !== $grid['region'])) {
                throw new DomainException('Invalid grid region.');
            }
            $capacity = $parent['width'] ?? 16;
            if ($grid['width'] < 1 || $grid['width'] > $capacity || $grid['row'] < 1 || $grid['position'] < 1) {
                throw new DomainException('Invalid grid width or position.');
            }
            $seen = [$grid['id']];
            $node = $parent;
            while ($node !== null) {
                if (in_array($node['id'], $seen, true) || count($seen) >= 8) {
                    throw new DomainException('Circular or excessively nested grids.');
                }
                $seen[] = $node['id'];
                $node = $node['parent_id'] === null ? null : ($byId[$node['parent_id']] ?? throw new DomainException('Missing parent grid.'));
            }
            $key = $grid['region'].'/'.($grid['parent_id'] ?? '').'/'.$grid['row'];
            $slot = $key.'/'.$grid['position'];
            if (isset($positions[$slot])) {
                throw new DomainException('Duplicate grid position.');
            }
            $positions[$slot] = true;
            $rows[$key] = ($rows[$key] ?? 0) + $grid['width'];
            if ($rows[$key] > $capacity) {
                throw new DomainException('Grid row exceeds its parent width.');
            }
        }
    }
}
