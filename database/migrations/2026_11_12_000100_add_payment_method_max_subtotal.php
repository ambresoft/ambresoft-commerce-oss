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
        // Checkout stops offering the method above this order subtotal (e.g. cash on delivery over 500 in Greece), in
        // the primary currency; null means no limit.
        Schema::table('payment_methods', fn (Blueprint $t) => $t->decimal('max_subtotal', 20, 6)->nullable());
        DB::statement('ALTER TABLE payment_methods ADD CONSTRAINT payment_method_max_subtotal CHECK (max_subtotal IS NULL OR max_subtotal > 0)');
        $this->trigger(AuditTables::paymentLimits()['payment_methods'][1]);
    }

    public function down(): void
    {
        $this->trigger(AuditTables::paymentFees()['payment_methods'][1]);
        DB::statement('ALTER TABLE payment_methods DROP CONSTRAINT payment_method_max_subtotal');
        Schema::table('payment_methods', fn (Blueprint $t) => $t->dropColumn('max_subtotal'));
    }

    private function trigger(string $fields): void
    {
        DB::statement('DROP TRIGGER IF EXISTS cms_audit_mutation ON payment_methods');
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON payment_methods FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('payment_method', '$fields')");
    }
};
