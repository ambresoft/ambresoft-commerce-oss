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
        // The countries the store ships to, chosen from the ISO allowlist in config/geography.php.
        Schema::create('shipping_countries', function (Blueprint $t): void {
            $t->id();
            $t->char('country_code', 2)->unique();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('created_at')->useCurrent();
        });
        // Regions of a country (Greek nomoi): the checkout address dropdown and shipping/tax rules read these.
        // Regions deliberately survive a country being removed from the shipping list, so re-enabling keeps them.
        Schema::create('regions', function (Blueprint $t): void {
            $t->id();
            $t->char('country_code', 2);
            $t->string('code', 10);
            $t->string('status', 10)->default('active');
            $t->bigInteger('lock_version')->default(1);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->unique(['country_code', 'code']);
        });
        Schema::create('region_translations', function (Blueprint $t): void {
            $t->foreignId('region_id')->constrained('regions')->cascadeOnDelete();
            $t->foreignId('locale_id')->constrained('locales')->restrictOnDelete();
            $t->string('name', 120);
            $t->primary(['region_id', 'locale_id']);
        });
        DB::statement("ALTER TABLE shipping_countries ADD CONSTRAINT shipping_country_code CHECK (country_code ~ '^[A-Z]{2}$')");
        DB::statement("ALTER TABLE regions ADD CONSTRAINT region_values CHECK (country_code ~ '^[A-Z]{2}$' AND code ~ '^[A-Z0-9]{1,10}$' AND status IN ('active','disabled') AND lock_version > 0)");
        DB::statement("ALTER TABLE region_translations ADD CONSTRAINT region_name CHECK (btrim(name) <> '')");
        foreach (AuditTables::geography() as $table => [$entity, $fields]) {
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
    }

    public function down(): void
    {
        foreach (['region_translations', 'regions', 'shipping_countries'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
