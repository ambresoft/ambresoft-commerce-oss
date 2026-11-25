<?php

namespace App\Audit\Presentation\Console;

use App\Audit\Domain\Model\AuditEvent;
use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PruneAuditLogs extends Command
{
    protected $signature = 'cms:audit-prune';

    protected $description = 'Prune audit logs only with explicitly configured retention; never scheduled by default';

    public function handle(AuditContext $context, AuditLogger $logger): int
    {
        $days = filter_var(config('audit.retention_days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 36500]]);
        if ($days === false) {
            $this->error('Set a positive AUDIT_RETENTION_DAYS after approving a retention policy.');

            return self::FAILURE;
        }
        $context->start();
        try {
            $count = DB::transaction(function () use ($days, $logger): int {
                DB::select("SELECT set_config('cms.audit_retention_days', ?, true)", [(string) $days]);
                $count = DB::table('audit_logs')->where('occurred_at', '<', now()->utc()->subDays($days))->delete();
                $logger->record(new AuditEvent('audit.retention_purged', 'audit', metadata: ['deleted_count' => $count, 'retention_days' => $days]));

                return $count;
            });
            $this->info('Pruned '.$count.' expired audit records.');

            return self::SUCCESS;
        } finally {
            $context->clear();
        }
    }
}
