<?php

namespace App\Storefront\Infrastructure;

use App\Shared\Infrastructure\Cache\StorefrontCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class PublicMedia
{
    /** The public route rechecks eligibility for the owning record, never serves an arbitrary media ID.
     * @param list<int|string|null> $ids
     * @return array<int|string,array<string,mixed>> */
    public function images(array $ids): array
    {
        return app(StorefrontCache::class)->remember('media', ['images', $ids], fn () => $this->loadImages($ids));
    }

    /** The public route rechecks eligibility for the owning record, never serves an arbitrary media ID.
     * @param list<int|string|null> $ids
     * @return array<int|string,array<string,mixed>> */
    private function loadImages(array $ids): array
    {
        $result = [];
        foreach (DB::table('media_files')->whereIn('id', array_filter($ids))->get() as $m) {
            if ($m->disk !== 'local' || ! in_array($m->mime_type, ['image/jpeg', 'image/png', 'image/webp'], true) || ! Storage::disk('local')->exists($m->storage_key)) {
                continue;
            }
            $result[$m->id] = ['id' => (string) $m->id, 'width' => (int) $m->width, 'height' => (int) $m->height];
        }

        return $result;
    }

    /** @param list<int|string|null> $ids
     * @return array<int|string,true> */
    public function videos(array $ids): array
    {
        $result = [];
        foreach (DB::table('media_videos')->whereIn('id', array_filter($ids))->get() as $video) {
            if ($video->disk === 'local' && $video->mime_type === 'video/mp4' && Storage::disk('local')->exists($video->storage_key)) {
                $result[$video->id] = true;
            }
        }

        return $result;
    }

    /** @param array<string,mixed>|null $image
     * @return array<string,mixed>|null */
    public function url(?array $image, string $kind, string $owner): ?array
    {
        return $image === null ? null : ['url' => '/storefront/media/'.$kind.'/'.$owner.'/'.$image['id'], 'width' => $image['width'], 'height' => $image['height']];
    }
}
