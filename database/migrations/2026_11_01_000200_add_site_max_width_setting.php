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
        // Keep the existing storefront width until a merchant changes it.
        Schema::table('general_settings', fn (Blueprint $t) => $t->integer('site_max_width')->default(1320));
        DB::statement('ALTER TABLE general_settings ADD CONSTRAINT general_settings_site_max_width CHECK (site_max_width BETWEEN 320 AND 3840)');
        $this->trigger(AuditTables::siteWidth()['general_settings'][1]);
    }

    public function down(): void
    {
        $this->trigger(AuditTables::productCardStyle()['general_settings'][1]);
        DB::statement('ALTER TABLE general_settings DROP CONSTRAINT general_settings_site_max_width');
        Schema::table('general_settings', fn (Blueprint $t) => $t->dropColumn('site_max_width'));
    }

    private function trigger(string $fields): void
    {
        DB::statement('DROP TRIGGER IF EXISTS cms_audit_mutation ON general_settings');
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON general_settings FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('general_settings', '$fields')");
    }
};
