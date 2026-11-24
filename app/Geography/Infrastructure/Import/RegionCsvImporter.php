<?php

namespace App\Geography\Infrastructure\Import;

use App\Audit\Domain\Model\AuditEvent;
use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Audit\Infrastructure\AuditWriteFailure;
use App\Geography\Application\Contract\GeographyAdministration;
use App\Geography\Application\Contract\RegionCsvImportInterface;
use App\Identity\Infrastructure\Persistence\UserRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class RegionCsvImporter implements RegionCsvImportInterface
{
    public function __construct(private RegionImportPlanner $planner, private GeographyAdministration $geography) {}

    public function preview(string $actorId, string $file, string $delimiter): array
    {
        return app(AuditLogger::class)->operation('region', $actorId, fn (): array => DB::transaction(function () use ($actorId, $file, $delimiter): array {
            DB::select('SELECT pg_advisory_xact_lock(2142513)');
            $this->authorize($actorId);
            $this->options($file, $delimiter);
            $report = $this->planner->plan($file, $delimiter)['report'];
            app(AuditLogger::class)->record(new AuditEvent('region.import_previewed', 'region', metadata: ['rows' => $report['rows']]));

            return $report;
        }));
    }

    public function confirm(string $actorId, string $file, string $delimiter, array $preview): array
    {
        return app(AuditLogger::class)->operation('region', $actorId, function () use ($actorId, $file, $delimiter, $preview): array {
            $this->authorize($actorId);
            $this->options($file, $delimiter);
            $started = microtime(true);
            $row = 0;
            try {
                $report = DB::transaction(function () use ($actorId, $file, $delimiter, $preview, &$row): array {
                    // Share the configuration lock with geography CRUD and language mutations.
                    DB::select('SELECT pg_advisory_xact_lock(2142513)');
                    $planned = $this->planner->plan($file, $delimiter);
                    $report = $planned['report'];
                    if (($preview['status'] ?? null) !== 'preview' || ($preview['errors'] ?? 1) > 0 || $report['errors'] > 0 || ($preview['fingerprint'] ?? null) !== $report['fingerprint'] || ($preview['fileHash'] ?? null) !== $report['fileHash']) {
                        throw ValidationException::withMessages(['file' => __('region_import.stale')]);
                    }
                    foreach ($planned['operations'] as $operation) {
                        $row = $operation['row'];
                        if ($operation['creating']) {
                            $this->geography->createRegion($actorId, $operation['country_code'], $operation['code'], $operation['names'], $operation['status']);
                        } else {
                            $this->geography->updateRegion($actorId, $operation['id'], $operation['version'], $operation['code'], $operation['names'], $operation['status']);
                        }
                    }
                    $report['status'] = 'completed';
                    app(AuditLogger::class)->record(new AuditEvent('region.import_completed', 'region', metadata: array_intersect_key($report, array_flip(['rows', 'created', 'updated', 'skipped']))));

                    return $report;
                });
            } catch (Throwable $failure) {
                if (! $failure instanceof AuditWriteFailure) {
                    app(AuditLogger::class)->record(new AuditEvent('region.import_failed', 'region', status: 'failure', metadata: ['rows' => $preview['rows'] ?? 0, 'row' => $row]));
                    app(AuditContext::class)->markFailure();
                }
                if ($failure instanceof ValidationException && $row > 0) {
                    throw ValidationException::withMessages(['file' => __('region_import.operation_failed', ['row' => $row, 'reason' => $failure->getMessage()])]);
                }
                throw $failure;
            }
            $report['duration'] = round(microtime(true) - $started, 2);

            return $report;
        });
    }

    private function authorize(string $actorId): void
    {
        $actor = UserRecord::findOrFail($actorId);
        Gate::forUser($actor)->authorize('access-admin');
        Gate::forUser($actor)->authorize('regions.manage');
    }

    private function options(string $file, string $delimiter): void
    {
        if (! is_file($file) || ! is_readable($file) || filesize($file) > (int) config('region-import.max_kilobytes') * 1024 || ! in_array($delimiter, config('region-import.delimiters'), true)) {
            throw ValidationException::withMessages(['file' => __('region_import.options_invalid')]);
        }
    }
}
