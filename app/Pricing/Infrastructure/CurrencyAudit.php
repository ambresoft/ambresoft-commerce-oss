<?php

namespace App\Pricing\Infrastructure;

use Illuminate\Support\Facades\DB;

final class CurrencyAudit
{
    /** @param array<string, mixed> $context */
    public function record(?string $id, string $code, string $actor, string $action, array $context = []): void
    {
        DB::table('currency_audits')->insert(['currency_id' => $id, 'currency_code' => $code, 'actor_id' => $actor, 'action' => $action, 'context' => json_encode($context, JSON_THROW_ON_ERROR), 'occurred_at' => now()]);
    }
}
