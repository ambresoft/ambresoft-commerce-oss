<?php

namespace App\Catalog\Infrastructure\Import;

use App\Audit\Domain\Model\AuditEvent;
use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Catalog\Application\Command\SaveBrandData;
use App\Catalog\Infrastructure\Brands;
use App\Catalog\Infrastructure\CategoryHierarchy;
use App\Catalog\Infrastructure\ProductFiles;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Media\Infrastructure\Persistence\MediaFileRecord;
use App\Media\Infrastructure\PrivateImageStorage;
use App\Media\Infrastructure\RemoteDownload;
use App\Media\Infrastructure\RemoteImageDownload;
use App\Shared\Application\Command\ImageUpload;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

final class BrandCsvImporter
{
    private function actor(string $actor): UserRecord
    {
        app(AuditContext::class)->actor($actor);
        $user = UserRecord::findOrFail($actor);
        Gate::forUser($user)->authorize('access-admin');
        Gate::forUser($user)->authorize('brands.import');

        return $user;
    }

    /** @return array<string,mixed> */
    public function preview(string $actor, string $file, string $delimiter): array
    {
        $this->options($file, $delimiter);

        return DB::transaction(function () use ($actor, $file, $delimiter): array {
            app(CategoryHierarchy::class)->lock();
            $report = app(BrandImportPlanner::class)->plan($this->actor($actor), $file, $delimiter)['report'];
            app(AuditLogger::class)->record(new AuditEvent('brand.import_previewed', 'brand', metadata: ['rows' => $report['rows']]));

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
                $plan = app(BrandImportPlanner::class)->plan($this->actor($actor), $file, $delimiter);
                $this->fresh($preview, $plan['report']);

                return $plan;
            });
            $bytes = 0;
            $deadline = microtime(true) + (int) config('brand-import.download_seconds');
            foreach ($plan['operations'] as $operation) {
                foreach ($operation['downloads'] as $kind => $url) {
                    $key = $kind.':'.$url;
                    if (isset($staged[$key])) {
                        continue;
                    }
                    $temporary = tmpfile();
                    if ($temporary === false) {
                        throw ValidationException::withMessages(['file' => __('brand_import.unavailable')]);
                    }
                    $staged[$key] = $temporary;
                    $path = stream_get_meta_data($temporary)['uri'];
                    try {
                        if ($kind === 'attachment') {
                            $bytes += app(RemoteDownload::class)->fetch($url, $path, $deadline - microtime(true), (int) config('products.private_files.max_kilobytes') * 1024);
                            app(ProductFiles::class)->inspect(new UploadedFile($path, $operation['attachment_name'], null, null, true));
                        } else {
                            $metadata = app(RemoteImageDownload::class)->download($url, $path, $deadline - microtime(true));
                            $bytes += $metadata['byte_size'];
                        }
                        if ($bytes > config('brand-import.max_download_bytes')) {
                            throw new \RuntimeException('Batch limit exceeded.');
                        }
                    } catch (Throwable) {
                        throw ValidationException::withMessages([$kind.'_url.'.$operation['row'] => __('brand_import.download_failed')]);
                    }
                }
            }
            $report = DB::transaction(function () use ($actor, $file, $delimiter, $preview, $staged, &$stored): array {
                app(CategoryHierarchy::class)->lock();
                $plan = app(BrandImportPlanner::class)->plan($this->actor($actor), $file, $delimiter);
                $this->fresh($preview, $plan['report']);
                foreach ($plan['operations'] as $op) {
                    if ($op['action'] === 'skipped') {
                        continue;
                    }
                    try {
                        $fields = $op['fields'];
                        $stagedFiles = [];
                        if (isset($op['downloads']['attachment']) || $fields['remove_attachments']) {
                            $fields['files'] = $fields['remove_attachments'] || $op['id'] === null ? [] : DB::table('brand_files')->where('brand_id', $op['id'])->orderBy('position')->get()->map(fn ($f): array => ['id' => (string) $f->id, 'display_name' => $f->display_name])->all();
                            if (isset($op['downloads']['attachment'])) {
                                $path = stream_get_meta_data($staged['attachment:'.$op['downloads']['attachment']])['uri'];
                                $file = app(ProductFiles::class)->stage(new UploadedFile($path, $op['attachment_name'], null, null, true));
                                $stagedFiles[] = $file;
                                $stored[] = DB::table('private_product_files')->where('id', $file)->value('storage_key');
                            }
                        }
                        $image = fn (string $kind): ?ImageUpload => isset($op['downloads'][$kind]) ? new ImageUpload(stream_get_meta_data($staged[$kind.':'.$op['downloads'][$kind]])['uri']) : null;
                        $id = app(Brands::class)->save($actor, $op['id'], new SaveBrandData($fields, $image('image'), $image('banner_image'), $stagedFiles));
                        foreach (['image_media_id', 'banner_image_media_id'] as $column) {
                            $mediaId = DB::table('brands')->where('id', $id)->value($column);
                            if ($mediaId !== null) {
                                $stored[] = DB::table('media_files')->where('id', $mediaId)->value('storage_key');
                            }
                        }
                    } catch (ValidationException $e) {
                        throw ValidationException::withMessages(['file' => __('brand_import.operation_failed', ['row' => $op['row'], 'reason' => $e->getMessage()])]);
                    }
                }
                $report = $plan['report'];
                $report['status'] = 'completed';
                app(AuditLogger::class)->record(new AuditEvent('brand.import_completed', 'brand', metadata: array_intersect_key($report, array_flip(['rows', 'created', 'updated', 'skipped']))));

                return $report;
            });
            $report['duration'] = round(microtime(true) - $started, 2);

            return $report;
        } catch (Throwable $e) {
            app(AuditLogger::class)->record(new AuditEvent('brand.import_failed', 'brand', status: 'failure'));
            if ($e instanceof QueryException) {
                throw ValidationException::withMessages(['file' => __('brand_import.database_failed')]);
            }
            throw $e;
        } finally {
            foreach ($staged as $temporary) {
                fclose($temporary);
            }
            foreach ($stored as $key) {
                if (! MediaFileRecord::where('storage_key', $key)->exists() && ! DB::table('private_product_files')->where('storage_key', $key)->exists()) {
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
            throw ValidationException::withMessages(['file' => __('brand_import.stale')]);
        }
    }

    private function options(string $file, string $delimiter): void
    {
        if (! is_file($file) || filesize($file) > config('brand-import.max_kilobytes') * 1024 || ! in_array($delimiter, config('brand-import.delimiters'), true)) {
            throw ValidationException::withMessages(['file' => __('brand_import.options_invalid')]);
        }
    }
}
