<?php

namespace App\Catalog\Infrastructure;

use Illuminate\Support\Facades\DB;

final class ProductEligibility
{
    // Invoked inside the category/language transaction under the shared advisory lock.
    public function refresh(string $actor, ?string $category = null): void
    {
        $queries = app(ProductQueries::class);
        DB::table('products')->where('status', 'active')->when($category !== null, fn ($q) => $q->where('category_id', $category))
            ->whereRaw('NOT '.$queries->completenessSql(), [$queries->defaultId()])->orderBy('id')->chunkById(100, function ($rows) use ($actor): void {
                foreach ($rows as $p) {
                    DB::table('products')->where('id', $p->id)->update(['status' => 'disabled', 'lock_version' => DB::raw('lock_version + 1'), 'updated_by' => $actor, 'updated_at' => now()]);
                    app(ProductAudit::class)->record((string) $p->id, $actor, 'eligibility_disabled');
                }
            });
    }
}
