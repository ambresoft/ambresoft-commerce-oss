<?php

use App\Audit\Infrastructure\AuditTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // download: delivered to buyers of a digital product. attachment: product document shown with any product
        // (size chart, book sample). Existing rows were digital downloads.
        Schema::table('product_files', function (Blueprint $t): void {
            $t->string('purpose', 10)->default('download');
        });
        DB::statement("ALTER TABLE product_files ADD CONSTRAINT product_file_purpose CHECK (purpose IN ('download', 'attachment'))");
        [$entity, $fields] = AuditTables::productAttachments()['product_files'];
        DB::statement('DROP TRIGGER IF EXISTS cms_audit_mutation ON product_files');
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON product_files FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");

        // One row per effective price change, amounts in the current primary currency (re-denominated with prices).
        Schema::create('product_price_history', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $t->decimal('price', 20, 6);
            $t->decimal('list_price', 20, 6)->nullable();
            $t->timestampTz('recorded_at')->useCurrent();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->index(['product_id', 'recorded_at', 'id']);
        });
        DB::statement("ALTER TABLE product_price_history ADD CONSTRAINT product_price_history_values CHECK (price >= 0 AND price <> 'NaN'::numeric AND (list_price IS NULL OR (list_price >= 0 AND list_price <> 'NaN'::numeric)))");
        $this->backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('product_price_history');
        [$entity, $fields] = AuditTables::all()['product_files'];
        DB::statement('DROP TRIGGER IF EXISTS cms_audit_mutation ON product_files');
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON product_files FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        DB::statement('ALTER TABLE product_files DROP CONSTRAINT product_file_purpose');
        // Rollback turns attachments back into download files; use it only before merchants rely on attachments.
        Schema::table('product_files', fn (Blueprint $t) => $t->dropColumn('purpose'));
    }

    /**
     * Reconstructs past prices from the central audit ledger where it still holds them, then makes sure every priced
     * product's history ends at its current price. Ledger entries from before the latest primary-currency change are
     * in another currency and are skipped. Best effort: pruned ledger entries cannot be recovered.
     */
    private function backfill(): void
    {
        $since = DB::table('currency_audits')->where('action', 'primary_changed')->max('occurred_at');
        $state = [];
        $rows = [];
        $entries = DB::table('audit_logs')->where('entity_type', 'product')->where('status', 'success')
            ->whereRaw("(jsonb_exists(changes, 'price') OR jsonb_exists(changes, 'list_price'))")
            ->when($since !== null, fn ($q) => $q->where('occurred_at', '>', $since))
            ->orderBy('occurred_at')->orderBy('id')->cursor();
        foreach ($entries as $entry) {
            $id = (string) $entry->entity_id;
            if (preg_match('/^[1-9][0-9]{0,18}$/D', $id) !== 1) {
                continue;
            }
            $changes = json_decode((string) $entry->changes, true);
            $current = $state[$id] ?? ['price' => null, 'list_price' => null];
            foreach (['price', 'list_price'] as $field) {
                if (is_array($changes[$field] ?? null) && array_key_exists('after', $changes[$field])) {
                    $current[$field] = $changes[$field]['after'] === null ? null : (string) $changes[$field]['after'];
                }
            }
            $previous = $state[$id] ?? null;
            $state[$id] = $current;
            if ($current['price'] !== null && ($previous === null || $previous['price'] !== $current['price'] || $previous['list_price'] !== $current['list_price'])) {
                $actor = filter_var($entry->actor_id, FILTER_VALIDATE_INT);
                $rows[$id][] = ['price' => $current['price'], 'list_price' => $current['list_price'], 'recorded_at' => $entry->occurred_at, 'actor_id' => $actor === false ? null : $actor];
            }
        }
        $users = DB::table('users')->pluck('id')->flip();
        foreach (DB::table('products')->whereNotNull('price')->orderBy('id')->get(['id', 'price', 'list_price']) as $product) {
            $history = $rows[(string) $product->id] ?? [];
            $last = end($history) ?: null;
            if ($last === null || bccomp($last['price'], $product->price, 6) !== 0 || ($last['list_price'] === null) !== ($product->list_price === null) || ($last['list_price'] !== null && bccomp($last['list_price'], $product->list_price, 6) !== 0)) {
                $history[] = ['price' => $product->price, 'list_price' => $product->list_price, 'recorded_at' => now(), 'actor_id' => null];
            }
            foreach (array_chunk($history, 500) as $chunk) {
                DB::table('product_price_history')->insert(array_map(fn (array $row): array => [...$row, 'product_id' => $product->id, 'actor_id' => isset($users[$row['actor_id']]) ? $row['actor_id'] : null], $chunk));
            }
        }
    }
};
