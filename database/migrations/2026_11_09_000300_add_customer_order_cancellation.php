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
        // Statuses in which the customer may cancel the order themselves; on by default only before it ships.
        Schema::table('order_statuses', fn (Blueprint $t) => $t->boolean('customer_can_cancel')->default(false));
        DB::table('order_statuses')->whereIn('code', ['open', 'processing'])->update(['customer_can_cancel' => true]);
        $this->source(['admin', 'system', 'checkout', 'customer']);
        $this->trigger(AuditTables::customerCancellation()['order_statuses'][1]);
    }

    public function down(): void
    {
        DB::table('order_status_history')->where('source', 'customer')->update(['source' => 'system']);
        $this->source(['admin', 'system', 'checkout']);
        $this->trigger(AuditTables::orderEmailLayouts()['order_statuses'][1]);
        Schema::table('order_statuses', fn (Blueprint $t) => $t->dropColumn('customer_can_cancel'));
    }

    /** @param list<string> $sources */
    private function source(array $sources): void
    {
        DB::statement('ALTER TABLE order_status_history DROP CONSTRAINT order_status_history_values');
        DB::statement("ALTER TABLE order_status_history ADD CONSTRAINT order_status_history_values CHECK (source IN ('".implode("','", $sources)."') AND (from_status_id IS NULL OR from_status_id <> to_status_id))");
    }

    private function trigger(string $fields): void
    {
        DB::statement('DROP TRIGGER IF EXISTS cms_audit_mutation ON order_statuses');
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON order_statuses FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('order_status', '$fields')");
    }
};
