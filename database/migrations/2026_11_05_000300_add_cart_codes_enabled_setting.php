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
        // Shoppers can enter coupon and gift card codes in the cart unless the merchant switches it off.
        Schema::table('general_settings', fn (Blueprint $t) => $t->boolean('cart_codes_enabled')->default(true));
        $this->trigger(AuditTables::cartCodesSetting()['general_settings'][1]);
    }

    public function down(): void
    {
        $this->trigger(AuditTables::comparisonLimitSetting()['general_settings'][1]);
        Schema::table('general_settings', fn (Blueprint $t) => $t->dropColumn('cart_codes_enabled'));
    }

    private function trigger(string $fields): void
    {
        DB::statement('DROP TRIGGER IF EXISTS cms_audit_mutation ON general_settings');
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON general_settings FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('general_settings', '$fields')");
    }
};
