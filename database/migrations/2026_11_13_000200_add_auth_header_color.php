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
        // Background of the customer sign-in and registration header; null follows the store's primary colour.
        Schema::table('storefront_theme_settings', fn (Blueprint $t) => $t->string('auth_header_color', 7)->nullable());
        DB::statement("ALTER TABLE storefront_theme_settings ADD CONSTRAINT storefront_theme_auth_header CHECK (auth_header_color IS NULL OR auth_header_color ~ '^#[0-9a-f]{6}$')");
        $this->trigger(AuditTables::authHeader()['storefront_theme_settings'][1]);
    }

    public function down(): void
    {
        $this->trigger(AuditTables::storefrontTheme()['storefront_theme_settings'][1]);
        DB::statement('ALTER TABLE storefront_theme_settings DROP CONSTRAINT storefront_theme_auth_header');
        Schema::table('storefront_theme_settings', fn (Blueprint $t) => $t->dropColumn('auth_header_color'));
    }

    private function trigger(string $fields): void
    {
        DB::statement('DROP TRIGGER IF EXISTS cms_audit_mutation ON storefront_theme_settings');
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON storefront_theme_settings FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('storefront_theme', '$fields')");
    }
};
