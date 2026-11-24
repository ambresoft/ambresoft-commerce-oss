<?php

namespace App\Catalog\Infrastructure\Import;

use App\Audit\Domain\Model\AuditEvent;
use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Catalog\Application\Command\ManageCategoriesHandler;
use App\Catalog\Application\Command\SaveCategoryData;
use App\Catalog\Application\Contract\CategoryCsvImportInterface;
use App\Catalog\Domain\Exception\InvalidCategoryChange;
use App\Catalog\Infrastructure\CategoryHierarchy;
use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Media\Infrastructure\Persistence\MediaFileRecord;
use App\Media\Infrastructure\PrivateImageStorage;
use App\Media\Infrastructure\RemoteImageDownload;
use App\Shared\Application\Command\ImageUpload;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class CategoryCsvImporter implements CategoryCsvImportInterface
{
    public function __construct(private CategoryImportPlanner $planner, private CategoryHierarchy $hierarchy, private ManageCategoriesHandler $categories, private RemoteImageDownload $downloads, private PrivateImageStorage $images) {}

    private function actor(string $actorId): UserRecord
    {
        app(AuditContext::class)->actor($actorId);
        $actor = UserRecord::findOrFail($actorId);
        Gate::forUser($actor)->authorize('access-admin');
        Gate::forUser($actor)->authorize('categories.import');

        return $actor;
    }

    public function preview(string $actorId, string $file, string $delimiter, string $pathSeparator): array
    {
        $this->options($file, $delimiter, $pathSeparator);

        return DB::transaction(function () use ($actorId, $file, $delimiter, $pathSeparator): array {
            $this->hierarchy->lock();

            $report = $this->planner->plan($this->actor($actorId), $file, $delimiter, $pathSeparator, static function (array $operation): void {});
            app(AuditLogger::class)->record(new AuditEvent('category.import_previewed', 'category', metadata: ['rows' => $report['rows']]));

            return $report;
        });
    }

    public function confirm(string $actorId, string $file, string $delimiter, string $pathSeparator, array $preview): array
    {
        $this->options($file, $delimiter, $pathSeparator);
        $started = microtime(true);
        $currentRow = 0;
        $spool = tmpfile();
        if ($spool === false) {
            throw ValidationException::withMessages(['file' => __('category_import.unavailable')]);
        }
        $staged = [];
        $storedKeys = [];
        try {
            // Validate the preview before any outgoing request. Do not hold the catalog
            // lock or a database transaction while waiting for remote image servers.
            DB::transaction(function () use ($actorId, $file, $delimiter, $pathSeparator, $preview, $spool): void {
                $this->hierarchy->lock();
                $report = $this->planner->plan($this->actor($actorId), $file, $delimiter, $pathSeparator, static function (array $operation) use ($spool): void {
                    if (fwrite($spool, json_encode($operation, JSON_THROW_ON_ERROR)."\n") === false) {
                        throw ValidationException::withMessages(['file' => __('category_import.unavailable')]);
                    }
                });
                $this->assertFresh($preview, $report);
            });
            $deadline = microtime(true) + (int) config('category-import.image_download_seconds');
            $bytes = 0;
            rewind($spool);
            while (($line = fgets($spool)) !== false) {
                $op = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                $currentRow = $op['row'];
                $url = $op['image_url'];
                if ($url === '' || isset($staged[$url])) {
                    continue;
                }
                $temporary = tmpfile();
                if ($temporary === false) {
                    throw ValidationException::withMessages(['file' => __('category_import.unavailable')]);
                }
                $staged[$url] = $temporary;
                try {
                    $path = stream_get_meta_data($temporary)['uri'];
                    $metadata = $this->downloads->download($url, $path, $deadline - microtime(true));
                    $bytes += $metadata['byte_size'];
                    if ($bytes > (int) config('category-import.max_image_bytes')) {
                        throw ValidationException::withMessages(['image_url' => __('category_import.image_batch_limit')]);
                    }
                } catch (ValidationException $failure) {
                    throw ValidationException::withMessages(['image_url.'.$currentRow => $failure->getMessage()]);
                }
            }
            $report = DB::transaction(function () use ($actorId, $file, $delimiter, $pathSeparator, $preview, $spool, $staged, &$storedKeys, &$currentRow): array {
                $this->hierarchy->lock();
                // Recheck permissions, file and catalog after downloads, before any writes.
                $report = $this->planner->plan($this->actor($actorId), $file, $delimiter, $pathSeparator, static function (array $operation): void {});
                $this->assertFresh($preview, $report);
                rewind($spool);
                $ids = [];
                while (($line = fgets($spool)) !== false) {
                    $op = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    $currentRow = $op['row'];
                    $parent = $op['parent'] === null ? null : ($ids[$op['parent']] ?? $op['parent']);
                    $categoryId = $op['creating'] ? null : $op['id'];
                    $version = $categoryId === null ? null : CategoryRecord::findOrFail($categoryId)->lock_version;
                    $ids[$op['id']] = $this->categories->saveImported($actorId, $categoryId, new SaveCategoryData($parent, $op['position'], $op['status'], $op['translations'], $version, image: $op['image_url'] === '' ? null : new ImageUpload(stream_get_meta_data($staged[$op['image_url']])['uri']), removeImage: $op['remove_image'], ageVerification: $op['age_verification'], ageLimit: $op['age_limit'], excludeFromDiscovery: $op['exclude_from_discovery'], customerGroups: $op['customer_groups']));
                    if ($op['image_url'] !== '') {
                        $storedKeys[] = CategoryRecord::findOrFail($ids[$op['id']])->image->storage_key;
                    }
                }
                $report['status'] = 'completed';
                app(AuditLogger::class)->record(new AuditEvent('category.import_completed', 'category', metadata: array_intersect_key($report, array_flip(['rows', 'created', 'updated', 'skipped']))));

                return $report;
            });
        } catch (QueryException $failure) {
            app(AuditLogger::class)->record(new AuditEvent('category.import_failed', 'category', status: 'failure', metadata: ['rows' => $currentRow]));
            Log::error('category_csv_import_constraint_failed', ['actor_id' => $actorId, 'row' => $currentRow, 'sqlstate' => $failure->errorInfo[0] ?? null]);
            throw ValidationException::withMessages(['file' => __('category_import.operation_failed', ['row' => $currentRow, 'reason' => __('category_import.database_failed')])]);
        } catch (InvalidCategoryChange $failure) {
            app(AuditLogger::class)->record(new AuditEvent('category.import_failed', 'category', status: 'failure', metadata: ['rows' => $currentRow]));
            Log::warning('category_csv_import_failed', ['actor_id' => $actorId, 'row' => $currentRow, 'reason' => $failure->getMessage()]);
            throw ValidationException::withMessages(['file' => __('category_import.operation_failed', ['row' => $currentRow, 'reason' => __($failure->getMessage())])]);
        } catch (Throwable $failure) {
            app(AuditLogger::class)->record(new AuditEvent('category.import_failed', 'category', status: 'failure', metadata: ['rows' => $currentRow]));
            Log::warning('category_csv_import_failed', ['actor_id' => $actorId, 'row' => $currentRow, 'type' => $failure::class]);
            throw $failure;
        } finally {
            fclose($spool);
            foreach ($staged as $temporary) {
                fclose($temporary);
            }
            // Nested category saves cannot clean earlier rows after an outer rollback.
            // Keep committed media even when an after-commit listener subsequently fails.
            foreach ($storedKeys as $key) {
                if (! MediaFileRecord::where('storage_key', $key)->exists()) {
                    $this->images->discard($key);
                }
            }
        }
        $report['duration'] = round(microtime(true) - $started, 2);
        Log::info('category_csv_import_completed', ['actor_id' => $actorId, 'rows' => $report['rows'], 'created' => $report['created'], 'updated' => $report['updated'], 'skipped' => $report['skipped'], 'duration_seconds' => $report['duration']]);

        return $report;
    }

    /** @param array<string, mixed> $preview
     * @param  array<string, mixed>  $report
     */
    private function assertFresh(array $preview, array $report): void
    {
        if ($report['errors'] > 0 || $preview['errors'] > 0 || $preview['fingerprint'] !== $report['fingerprint'] || $preview['fileHash'] !== $report['fileHash']) {
            throw ValidationException::withMessages(['file' => __('category_import.stale')]);
        }
    }

    private function options(string $file, string $delimiter, string $separator): void
    {
        if (! is_file($file) || filesize($file) > (int) config('category-import.max_kilobytes') * 1024 || ! in_array($delimiter, config('category-import.delimiters'), true) || preg_match('/^[^\pL\pN\s"\x00-\x1f]{1,10}$/uD', $separator) !== 1) {
            throw ValidationException::withMessages(['file' => __('category_import.options_invalid')]);
        }
    }
}
