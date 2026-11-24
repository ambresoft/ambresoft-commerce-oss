<?php

namespace App\Catalog\Infrastructure;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class ProductFiles
{
    /** @return array{name:string,mime:string,extension:string,byte_size:int} */
    public function inspect(UploadedFile $upload): array
    {
        $extension = strtolower($upload->getClientOriginalExtension());
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($upload->getRealPath());
        $name = $upload->getClientOriginalName();
        if (! $upload->isValid() || ! in_array($extension, config('products.private_files.extensions'), true)
            || ! in_array($mime, config('products.private_files.mime_types.'.$extension, []), true)
            || $upload->getSize() < 1 || $upload->getSize() > config('products.private_files.max_kilobytes') * 1024
            || preg_match('/[\\\\\/\x00-\x1f]/', $name) || mb_strlen($name) > 255) {
            throw ValidationException::withMessages(['file_uploads' => __('products.invalid_file')]);
        }

        return ['name' => $name, 'mime' => $mime, 'extension' => $extension, 'byte_size' => (int) $upload->getSize()];
    }

    public function stage(UploadedFile $upload): string
    {
        ['name' => $name, 'mime' => $mime, 'extension' => $extension] = $this->inspect($upload);
        // Commit tracking BEFORE writing bytes; interrupted uploads remain discoverable by cleanup.
        $key = 'product-files/'.Str::uuid().'.'.$extension;
        $id = DB::transaction(function () use ($key, $name, $mime, $upload): int {
            $id = DB::table('private_product_files')->insertGetId(['disk' => 'local', 'storage_key' => $key, 'original_name' => $name, 'mime_type' => $mime, 'byte_size' => $upload->getSize(), 'ready' => false, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('product_file_cleanups')->insert(['private_file_id' => $id, 'available_at' => now()->addHour()]);

            return $id;
        });
        $stream = fopen($upload->getRealPath(), 'rb');
        if ($stream === false) {
            throw new RuntimeException('Unable to read uploaded file.');
        }
        try {
            DB::transaction(function () use ($id, $key, $stream): void {
                // Cleanup cannot reclaim an interrupted/stalled upload while bytes are still being written.
                $file = DB::table('private_product_files')->where('id', $id)->lockForUpdate()->first();
                if ($file === null || ! Storage::disk('local')->put($key, $stream, ['visibility' => 'private'])) {
                    throw new RuntimeException('Private upload failed.');
                }
                DB::table('private_product_files')->where('id', $id)->update(['ready' => true]);
            });
        } finally {
            fclose($stream);
        }

        return (string) $id;
    }

    /**
     * Called under the owning product transaction.
     *
     * @param  list<string>  $ids
     */
    public function schedule(array $ids): void
    {
        foreach (array_unique($ids) as $id) {
            DB::table('product_file_cleanups')->upsert([['private_file_id' => $id, 'available_at' => now(), 'dispatched_at' => null]], ['private_file_id'], ['available_at', 'dispatched_at']);
        }
    }

    /** @param list<array{id: string, display_name: string, replacement?: int|null}> $requested
     * @param  list<string>  $staged  Trusted IDs returned by stage(), never browser-provided IDs.
     * @param  array<int, string>  $purposes  Purpose of each new upload by staged index: download (delivered to buyers of a
     *                                        digital product) or attachment (product document for any product). Existing
     *                                        files and their replacements keep their purpose.
     */
    public function sync(string $productId, array $requested, array $staged, array $purposes = [], string $type = 'digital'): void
    {
        $this->syncFiles('product_files', 'product_id', $productId, $requested, $staged, $purposes, $type);
    }

    /** @param list<array{id:string,display_name:string,replacement?:int|null}> $requested
     * @param list<string> $staged */
    public function syncBrand(string $brandId, array $requested, array $staged): void
    {
        $this->syncFiles('brand_files', 'brand_id', $brandId, $requested, $staged, [], 'physical');
    }

    /** @param list<array{id:string,display_name:string,replacement?:int|null}> $requested
     * @param list<string> $staged
     * @param array<int,string> $purposes */
    private function syncFiles(string $table, string $owner, string $ownerId, array $requested, array $staged, array $purposes, string $type): void
    {
        $old = DB::table($table)->where($owner, $ownerId)->get()->keyBy('id');
        $allIds = array_unique([...$old->pluck('private_file_id')->map(fn ($id): string => (string) $id)->all(), ...$staged]);
        $files = DB::table('private_product_files')->whereIn('id', $allIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $rows = [];
        $used = [];
        foreach ($requested as $row) {
            $association = $old->get($row['id']);
            if ($association === null) {
                throw ValidationException::withMessages(['files' => __('products.invalid_file')]);
            }
            $fileId = (string) $association->private_file_id;
            if (isset($row['replacement'])) {
                if (! isset($staged[$row['replacement']]) || isset($used[$row['replacement']])) {
                    throw ValidationException::withMessages(['files' => __('products.invalid_file')]);
                }
                $fileId = $staged[$row['replacement']];
                $used[$row['replacement']] = true;
            }
            $rows[] = [$owner => $ownerId, 'private_file_id' => $fileId, 'display_name' => $row['display_name'], 'position' => count($rows) + 1, ...($table === 'product_files' ? ['purpose' => $association->purpose] : [])];
        }
        foreach ($staged as $index => $id) {
            if (! isset($files[$id]) || ! $files[$id]->ready || ! Storage::disk($files[$id]->disk)->exists($files[$id]->storage_key)) {
                throw ValidationException::withMessages(['files' => __('products.invalid_file')]);
            }
            if (! isset($used[$index])) {
                // Without an explicit purpose (older callers) the product type decides.
                $purpose = $purposes[$index] ?? ($type === 'digital' ? 'download' : 'attachment');
                if ($purpose === 'download' && $type !== 'digital') {
                    throw ValidationException::withMessages(['file_uploads' => __('products.download_digital_only')]);
                }
                $rows[] = [$owner => $ownerId, 'private_file_id' => $id, 'display_name' => $files[$id]->original_name, 'position' => count($rows) + 1, ...($table === 'product_files' ? ['purpose' => $purpose] : [])];
            }
        }
        if (count($rows) > 50) {
            throw ValidationException::withMessages(['files' => __('validation.max.array', ['attribute' => __('products.media'), 'max' => 50])]);
        }
        DB::table($table)->where($owner, $ownerId)->delete();
        if ($rows !== []) {
            DB::table($table)->insert($rows);
        }
        $this->schedule($allIds);
    }
}
