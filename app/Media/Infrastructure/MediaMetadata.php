<?php

namespace App\Media\Infrastructure;

use Illuminate\Support\Facades\DB;

/** Display metadata for stored images: size and dimensions only (no storage keys or filenames). */
final class MediaMetadata
{
    /** @param list<string> $ids
     * @return array<int|string, array{size: int, width: int, height: int}> keyed by media ID
     */
    public function for(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('media_files')->whereIn('id', $ids)->get(['id', 'byte_size', 'width', 'height'])
            ->mapWithKeys(fn ($m): array => [(string) $m->id => ['size' => (int) $m->byte_size, 'width' => (int) $m->width, 'height' => (int) $m->height]])->all();
    }

    /** @return array{size: int, width: int, height: int}|null */
    public function one(?string $id): ?array
    {
        return $id === null ? null : ($this->for([$id])[$id] ?? null);
    }
}
