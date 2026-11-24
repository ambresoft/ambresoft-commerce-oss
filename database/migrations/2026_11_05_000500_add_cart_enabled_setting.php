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
        // The storefront cart (and the checkout after it) is on unless the merchant switches it off for the whole store.
        Schema::table('general_settings', fn (Blueprint $t) => $t->boolean('cart_enabled')->default(true));
        $this->trigger(AuditTables::cartSetting()['general_settings'][1]);
    }

    public function down(): void
    {
        $this->trigger(AuditTables::cartCodesSetting()['general_settings'][1]);
        Schema::table('general_settings', fn (Blueprint $t) => $t->dropColumn('cart_enabled'));
    }

    private function trigger(string $fields): void
    {
        DB::statement('DROP TRIGGER IF EXISTS cms_audit_mutation ON general_settings');
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON general_settings FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('general_settings', '$fields')");
    }
};
