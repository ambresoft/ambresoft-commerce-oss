<?php

namespace App\Catalog\Presentation\Console;

use App\Audit\Domain\Model\AuditEvent;
use App\Audit\Infrastructure\AuditLogger;
use App\Catalog\Infrastructure\Jobs\CleanupProductFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class DispatchProductCleanup extends Command
{
    protected $signature = 'cms:product-files-cleanup';

    protected $description = 'Dispatch committed private-file cleanup requests to Redis';

    public function handle(): int
    {
        $dispatched = 0;
        DB::table('product_file_cleanups')->where('available_at', '<=', now())->where(fn ($q) => $q->whereNull('dispatched_at')->orWhere('dispatched_at', '<', now()->subMinutes(30)))->orderBy('private_file_id')->chunkById(100, function ($rows) use (&$dispatched): void {
            foreach ($rows as $row) {
                CleanupProductFile::dispatch((string) $row->private_file_id)->onConnection('redis')->onQueue('maintenance');
                $dispatched++;
                DB::table('product_file_cleanups')->where('private_file_id', $row->private_file_id)->update(['dispatched_at' => now()]);
            }
        }, 'private_file_id');

        if ($dispatched > 0) {
            app(AuditLogger::class)->record(new AuditEvent('command.completed', 'command', metadata: ['command' => 'cms:product-files-cleanup', 'rows' => $dispatched]));
        }

        return self::SUCCESS;
    }
}
