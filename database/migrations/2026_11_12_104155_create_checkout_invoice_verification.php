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
        Schema::create('checkout_settings', function (Blueprint $t): void {
            $t->smallInteger('id')->primary();
            $t->boolean('enabled')->default(false);
            $t->text('secrets')->nullable();
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
        });
        DB::statement('ALTER TABLE checkout_settings ADD CONSTRAINT checkout_settings_singleton CHECK (id = 1 AND lock_version > 0)');
        DB::table('checkout_settings')->insert(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        Schema::create('order_invoice_verifications', function (Blueprint $t): void {
            $t->foreignId('order_id')->primary()->constrained('orders')->cascadeOnDelete();
            $t->char('country_code', 2);
            $t->string('vat_number', 32);
            $t->string('business_name', 200)->nullable();
            $t->string('provider', 16);
            $t->timestampTz('verified_at');
        });
        DB::statement("ALTER TABLE order_invoice_verifications ADD CONSTRAINT order_invoice_verification_values CHECK (country_code ~ '^[A-Z]{2}$' AND vat_number ~ '^[A-Z0-9]{2,32}$' AND provider IN ('aade', 'vatsense'))");
        foreach (AuditTables::checkoutInvoices() as $table => [$entity, $fields]) {
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_invoice_verifications');
        Schema::dropIfExists('checkout_settings');
    }
};
