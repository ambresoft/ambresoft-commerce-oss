<?php

namespace App\Catalog\Infrastructure;

use App\Media\Infrastructure\Persistence\MediaFileRecord;
use App\Media\Infrastructure\PrivateImageStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class ProductImages
{
    /** @param list<string> $ids
     * @param  list<string>  $staged  Trusted IDs created by the current upload request.
     */
    public function sync(string $productId, array $ids, ?string $primary, array $staged = []): void
    {
        $current = DB::table('product_images')->where('product_id', $productId)->pluck('media_file_id')->map(strval(...))->all();
        $allowed = array_unique([...$current, ...$staged]);
        $records = MediaFileRecord::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        if (array_diff($ids, $allowed) !== [] || $records->count() !== count($ids) || ($primary !== null && ! in_array($primary, $ids, true))) {
            throw ValidationException::withMessages(['images' => __('products.invalid_image')]);
        }
        DB::table('product_images')->where('product_id', $productId)->delete();
        foreach ($ids as $index => $id) {
            DB::table('product_images')->insert(['product_id' => $productId, 'media_file_id' => $id, 'position' => $index + 1, 'is_primary' => $id === $primary]);
        }
        $this->deleteAssets(array_values(array_diff($current, $ids)));
    }

    public function duplicate(string $sourceProduct, string $targetProduct): void
    {
        $createdKeys = [];
        try {
            $images = DB::table('product_images as pi')->join('media_files as m', 'm.id', '=', 'pi.media_file_id')
                ->where('pi.product_id', $sourceProduct)->orderBy('pi.position')
                ->get(['m.disk', 'm.storage_key', 'm.mime_type', 'm.byte_size', 'm.width', 'm.height', 'pi.position', 'pi.is_primary']);
            foreach ($images as $image) {
                if ($image->disk !== 'local' || ! Storage::disk('local')->exists($image->storage_key)) {
                    throw new RuntimeException('Owned product image is unavailable.');
                }
                $extension = pathinfo($image->storage_key, PATHINFO_EXTENSION);
                $key = 'images/'.Str::uuid().($extension === '' ? '' : '.'.$extension);
                if (! Storage::disk('local')->copy($image->storage_key, $key)) {
                    throw new RuntimeException('Unable to duplicate owned product image.');
                }
                $createdKeys[] = $key;
                $record = MediaFileRecord::create(['disk' => 'local', 'storage_key' => $key, 'mime_type' => $image->mime_type, 'byte_size' => $image->byte_size, 'width' => $image->width, 'height' => $image->height]);
                DB::table('product_images')->insert(['product_id' => $targetProduct, 'media_file_id' => $record->id, 'position' => $image->position, 'is_primary' => $image->is_primary]);
            }
        } catch (Throwable $exception) {
            foreach ($createdKeys as $key) {
                app(PrivateImageStorage::class)->discard($key);
            }
            throw $exception;
        }
    }

    /** @param list<string> $ids */
    public function deleteAssets(array $ids): void
    {
        foreach (MediaFileRecord::whereIn('id', $ids)->lockForUpdate()->get() as $record) {
            $disk = $record->disk;
            $key = $record->storage_key;
            $record->delete();
            DB::afterCommit(fn () => Storage::disk($disk)->delete($key));
        }
    }
}
