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
        // How many products a visitor can compare at once (when product comparison is allowed).
        Schema::table('general_settings', fn (Blueprint $t) => $t->integer('comparison_max_products')->default(4));
        DB::statement('ALTER TABLE general_settings ADD CONSTRAINT general_settings_comparison_max_products CHECK (comparison_max_products BETWEEN 2 AND 10)');
        $this->trigger(AuditTables::comparisonLimitSetting()['general_settings'][1]);
    }

    public function down(): void
    {
        $this->trigger(AuditTables::pricesWithoutTaxSetting()['general_settings'][1]);
        Schema::table('general_settings', fn (Blueprint $t) => $t->dropColumn('comparison_max_products'));
    }

    private function trigger(string $fields): void
    {
        DB::statement('DROP TRIGGER IF EXISTS cms_audit_mutation ON general_settings');
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON general_settings FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('general_settings', '$fields')");
    }
};
