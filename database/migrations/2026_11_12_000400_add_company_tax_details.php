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
        // Legal and tax details printed on order summaries; all optional. The GEMI number exists only for Greek companies.
        Schema::table('company_settings', function (Blueprint $t): void {
            $t->string('legal_name', 200)->nullable();
            $t->string('business_activity', 200)->nullable();
            $t->string('tax_id', 32)->nullable();
            $t->string('tax_office', 100)->nullable();
            $t->string('gemi_number', 12)->nullable();
        });
        DB::statement("ALTER TABLE company_settings ADD CONSTRAINT company_gemi_number CHECK (gemi_number IS NULL OR (gemi_number ~ '^[0-9]{1,12}$' AND country_code = 'GR'))");
        $this->trigger(AuditTables::companyTaxDetails()['company_settings'][1]);
    }

    public function down(): void
    {
        $this->trigger(AuditTables::company()['company_settings'][1]);
        Schema::table('company_settings', fn (Blueprint $t) => $t->dropColumn(['legal_name', 'business_activity', 'tax_id', 'tax_office', 'gemi_number']));
    }

    private function trigger(string $fields): void
    {
        DB::statement('DROP TRIGGER IF EXISTS cms_audit_mutation ON company_settings');
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON company_settings FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('company_settings', '$fields')");
    }
};
