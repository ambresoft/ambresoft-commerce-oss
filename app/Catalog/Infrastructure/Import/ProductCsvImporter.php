<?php

namespace App\Catalog\Infrastructure\Import;

use App\Audit\Domain\Model\AuditEvent;
use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Catalog\Application\Command\SaveProductData;
use App\Catalog\Application\Contract\ProductAdministration;
use App\Catalog\Infrastructure\CategoryHierarchy;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Media\Infrastructure\Persistence\MediaFileRecord;
use App\Media\Infrastructure\PrivateImageStorage;
use App\Media\Infrastructure\RemoteImageDownload;
use App\Shared\Application\Command\ImageUpload;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ProductCsvImporter
{
    private function actor(string $actor): UserRecord
    {
        app(AuditContext::class)->actor($actor);
        $user = UserRecord::findOrFail($actor);
        Gate::forUser($user)->authorize('access-admin');
        Gate::forUser($user)->authorize('products.import');

        return $user;
    }

    /** @return array<string,mixed> */
    public function preview(string $actor, string $file, string $delimiter): array
    {
        $this->options($file, $delimiter);

        return DB::transaction(function () use ($actor, $file, $delimiter): array {
            app(CategoryHierarchy::class)->lock();
            $report = app(ProductImportPlanner::class)->plan($this->actor($actor), $file, $delimiter)['report'];
            app(AuditLogger::class)->record(new AuditEvent('product.import_previewed', 'product', metadata: ['rows' => $report['rows']]));

            return $report;
        });
    }

    /** @param array<string,mixed> $preview
     * @return array<string,mixed> */
    public function confirm(string $actor, string $file, string $delimiter, array $preview): array
    {
        $this->options($file, $delimiter);
        $started = microtime(true);
        $staged = $stored = [];
        try {
            $plan = DB::transaction(function () use ($actor, $file, $delimiter, $preview): array {
                app(CategoryHierarchy::class)->lock();
                $plan = app(ProductImportPlanner::class)->plan($this->actor($actor), $file, $delimiter);
                $this->fresh($preview, $plan['report']);

                return $plan;
            });
            $bytes = 0;
            $deadline = microtime(true) + (int) config('product-import.image_download_seconds');
            foreach ($plan['operations'] as $operation) {
                $url = $operation['image_url'];
                if ($url === '' || isset($staged[$url])) {
                    continue;
                }
                $temporary = tmpfile();
                if ($temporary === false) {
                    throw ValidationException::withMessages(['file' => __('product_import.unavailable')]);
                }
                $staged[$url] = $temporary;
                try {
                    $metadata = app(RemoteImageDownload::class)->download($url, stream_get_meta_data($temporary)['uri'], $deadline - microtime(true));
                    $bytes += $metadata['byte_size'];
                    if ($bytes > config('product-import.max_image_bytes')) {
                        throw ValidationException::withMessages(['image_url' => __('product_import.image_batch_limit')]);
                    }
                } catch (ValidationException $e) {
                    throw ValidationException::withMessages(['image_url.'.$operation['row'] => $e->getMessage()]);
                }
            }
            $report = DB::transaction(function () use ($actor, $file, $delimiter, $preview, $staged, &$stored): array {
                app(CategoryHierarchy::class)->lock();
                $plan = app(ProductImportPlanner::class)->plan($this->actor($actor), $file, $delimiter);
                $this->fresh($preview, $plan['report']);
                $products = app(ProductAdministration::class);
                foreach ($plan['operations'] as $op) {
                    if ($op['action'] === 'skipped') {
                        continue;
                    }
                    try {
                        $id = $op['id'] ?? $products->draft($actor);
                        $fields = $op['fields'];
                        if ($op['image_url'] !== '' || $op['remove_image']) {
                            $images = DB::table('product_images')->where('product_id', $id)->orderBy('position')->get();
                            $keep = $images->where('is_primary', false)->pluck('media_file_id')->map(strval(...))->all();
                            $fields['images'] = $keep;
                            $fields['primary_image'] = null;
                            if ($op['image_url'] !== '') {
                                $metadata = app(PrivateImageStorage::class)->store(new ImageUpload(stream_get_meta_data($staged[$op['image_url']])['uri']));
                                $stored[] = $metadata['storage_key'];
                                $image = MediaFileRecord::create($metadata);
                                $fields['images'][] = (string) $image->id;
                                $fields['primary_image'] = (string) $image->id;
                                $fields['staged_images'] = [(string) $image->id];
                            }
                        }
                        $products->save($actor, $id, new SaveProductData((int) $op['version'], $fields));
                    } catch (ValidationException $e) {
                        throw ValidationException::withMessages(['file' => __('product_import.operation_failed', ['row' => $op['row'], 'reason' => $e->getMessage()])]);
                    }
                }
                $report = $plan['report'];
                $report['status'] = 'completed';
                app(AuditLogger::class)->record(new AuditEvent('product.import_completed', 'product', metadata: array_intersect_key($report, array_flip(['rows', 'created', 'updated', 'skipped']))));

                return $report;
            });
            $report['duration'] = round(microtime(true) - $started, 2);

            return $report;
        } catch (Throwable $e) {
            app(AuditLogger::class)->record(new AuditEvent('product.import_failed', 'product', status: 'failure'));
            if ($e instanceof QueryException) {
                throw ValidationException::withMessages(['file' => __('product_import.database_failed')]);
            }
            throw $e;
        } finally {
            foreach ($staged as $temporary) {
                fclose($temporary);
            }
            foreach ($stored as $key) {
                if (! MediaFileRecord::where('storage_key', $key)->exists()) {
                    app(PrivateImageStorage::class)->discard($key);
                }
            }
        }
    }

    /** @param array<string,mixed> $before
     * @param array<string,mixed> $after */
    private function fresh(array $before, array $after): void
    {
        if ($before['errors'] || $after['errors'] || ! hash_equals($before['fingerprint'], $after['fingerprint']) || $before['fileHash'] !== $after['fileHash']) {
            throw ValidationException::withMessages(['file' => __('product_import.stale')]);
        }
    }

    private function options(string $file, string $delimiter): void
    {
        if (! is_file($file) || filesize($file) > config('product-import.max_kilobytes') * 1024 || ! in_array($delimiter, config('product-import.delimiters'), true)) {
            throw ValidationException::withMessages(['file' => __('product_import.options_invalid')]);
        }
    }
}
