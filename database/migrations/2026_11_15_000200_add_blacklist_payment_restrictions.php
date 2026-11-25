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
        // An entry either blocks the email/phone completely (`full`, the original behaviour) or only takes away some
        // payment methods at checkout (`payment_methods`, e.g. cash on delivery for a buyer who refuses deliveries).
        Schema::table('customer_blacklist_entries', fn (Blueprint $t) => $t->string('scope', 16)->default('full'));
        DB::statement("ALTER TABLE customer_blacklist_entries ADD CONSTRAINT blacklist_entry_scope CHECK (scope IN ('full', 'payment_methods'))");
        Schema::create('customer_blacklist_payment_methods', function (Blueprint $t): void {
            $t->foreignId('entry_id')->constrained('customer_blacklist_entries')->cascadeOnDelete();
            $t->foreignId('payment_method_id')->constrained('payment_methods')->cascadeOnDelete();
            $t->primary(['entry_id', 'payment_method_id']);
            $t->index('payment_method_id');
        });
        $this->audit('customer_blacklist_entries', fn (string $fields): string => $fields.',scope');
        foreach (AuditTables::blacklistPaymentRestrictions() as $table => [$entity, $fields]) {
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_blacklist_payment_methods');
        $this->audit('customer_blacklist_entries', fn (string $fields): string => implode(',', array_diff(explode(',', $fields), ['scope'])));
        DB::table('customer_blacklist_entries')->where('scope', 'payment_methods')->delete();
        DB::statement('ALTER TABLE customer_blacklist_entries DROP CONSTRAINT blacklist_entry_scope');
        Schema::table('customer_blacklist_entries', fn (Blueprint $t) => $t->dropColumn('scope'));
    }

    /** Extends (or shrinks) the field list that earlier additive migrations left on a table's audit trigger. */
    private function audit(string $table, callable $fields): void
    {
        $definition = DB::selectOne("SELECT pg_get_triggerdef(oid) AS def FROM pg_trigger WHERE tgname = 'cms_audit_mutation' AND tgrelid = '$table'::regclass")->def;
        if (preg_match("/cms_capture_audit\('([^']+)', '([^']+)'\)/", $definition, $m)) {
            DB::statement("DROP TRIGGER cms_audit_mutation ON $table");
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('{$m[1]}', '{$fields($m[2])}')");
        }
    }
};
