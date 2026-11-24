<?php

namespace App\Catalog\Infrastructure;

use Illuminate\Support\Facades\DB;

final class ProductAudit
{
    /** @param array<string, mixed> $context */
    public function record(?string $id, string $actor, string $action, array $context = []): void
    {
        DB::table('product_audits')->insert(['product_id' => $id, 'actor_id' => $actor, 'action' => $action, 'context' => json_encode($context, JSON_THROW_ON_ERROR), 'occurred_at' => now()]);
    }
}
