<?php

namespace App\Catalog\Infrastructure\Jobs;

use App\Audit\Infrastructure\AuditContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class CleanupProductFile implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(public string $fileId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 300, 600];
    }

    public function handle(): void
    {
        $context = app(AuditContext::class);
        $previous = $context->data();
        $context->start(['source' => 'background_job', ...(($previous['source'] ?? '') === 'background_job' ? ['request_id' => $previous['request_id']] : [])]);
        try {
            DB::transaction(function (): void {
                // Association creation takes this same row lock. Keep it through storage deletion.
                $file = DB::table('private_product_files')->where('id', $this->fileId)->lockForUpdate()->first();
                if ($file === null) {
                    return;
                }
                $pending = DB::table('product_file_cleanups')->where('private_file_id', $file->id)->where('available_at', '<=', now())->first();
                if ($pending === null) {
                    return;
                }
                if (DB::table('product_files')->where('private_file_id', $file->id)->exists() || DB::table('brand_files')->where('private_file_id', $file->id)->exists()) {
                    DB::table('product_file_cleanups')->where('private_file_id', $file->id)->delete();

                    return;
                }
                if ($file->disk !== 'local' || ! str_starts_with($file->storage_key, 'product-files/')) {
                    throw new RuntimeException('Invalid private storage metadata.');
                }
                $disk = Storage::disk($file->disk);
                if ($disk->exists($file->storage_key) && ! $disk->delete($file->storage_key)) {
                    throw new RuntimeException('Private file cleanup failed.');
                }
                DB::table('private_product_files')->where('id', $file->id)->delete();
            });
        } finally {
            if ($previous === []) {
                $context->clear();
            } else {
                $context->start($previous);
            }
        }
    }
}
