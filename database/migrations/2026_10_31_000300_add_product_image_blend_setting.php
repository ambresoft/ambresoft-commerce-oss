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
        // Storefront only: product pictures with a white background take the grey of the area behind them.
        Schema::table('general_settings', fn (Blueprint $t) => $t->boolean('blend_product_images')->default(true));
        $this->trigger(AuditTables::productImageBlend()['general_settings'][1]);
    }

    public function down(): void
    {
        $this->trigger(AuditTables::priceVisibility()['general_settings'][1]);
        Schema::table('general_settings', fn (Blueprint $t) => $t->dropColumn('blend_product_images'));
    }

    private function trigger(string $fields): void
    {
        DB::statement('DROP TRIGGER IF EXISTS cms_audit_mutation ON general_settings');
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON general_settings FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('general_settings', '$fields')");
    }
};
