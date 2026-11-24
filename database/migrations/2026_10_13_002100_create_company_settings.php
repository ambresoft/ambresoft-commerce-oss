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
        Schema::create('company_settings', function (Blueprint $t): void {
            $t->integer('id')->primary();
            $t->string('name', 200)->nullable();
            $t->string('address', 500)->nullable();
            $t->string('city', 200)->nullable();
            $t->string('region', 200)->nullable();
            $t->string('postal_code', 32)->nullable();
            $t->string('phone', 64)->nullable();
            $t->string('mobile', 64)->nullable();
            $t->string('website', 2048)->nullable();
            $t->string('email_customers', 254)->nullable();
            $t->string('email_admin', 254)->nullable();
            $t->string('email_orders', 254)->nullable();
            $t->string('email_support', 254)->nullable();
            $t->string('email_newsletter', 254)->nullable();
            $t->string('country_code', 2)->nullable();
            $t->smallInteger('opening_year')->nullable();
            $t->bigInteger('lock_version')->default(1);
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('updated_at')->useCurrent();
        });
        DB::statement("ALTER TABLE company_settings ADD CONSTRAINT company_settings_valid CHECK (id = 1 AND lock_version > 0 AND (opening_year IS NULL OR opening_year BETWEEN 1900 AND 9999) AND (country_code IS NULL OR country_code ~ '^[A-Z]{2}$'))");
        DB::table('company_settings')->insert(['id' => 1]);
        foreach (AuditTables::company() as $table => [$entity, $fields]) {
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('company_settings');
    }
};
