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
        // Settings an add-on declares (the `settings` contribution). Kept when the add-on is uninstalled, like its tables,
        // so a reinstall keeps its configuration.
        Schema::create('addon_settings', function (Blueprint $t): void {
            $t->string('addon', 100)->primary();
            $t->jsonb('settings')->default('{}');
            // Encrypted secret fields; never sent to the browser.
            $t->text('secrets')->nullable();
            $t->bigInteger('lock_version')->default(1);
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE addon_settings ADD CONSTRAINT addon_settings_values CHECK (addon ~ '^[a-z0-9][a-z0-9_.-]*/[a-z0-9][a-z0-9_.-]*$' AND lock_version > 0)");
        foreach (AuditTables::addonSettings() as $table => [$entity, $fields]) {
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('addon_settings');
    }
};
