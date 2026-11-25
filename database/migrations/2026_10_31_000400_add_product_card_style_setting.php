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
        // How storefront product cards look in carousels and blocks; the classic card stays the default.
        Schema::table('general_settings', fn (Blueprint $t) => $t->string('product_card_style', 16)->default('classic'));
        DB::statement("ALTER TABLE general_settings ADD CONSTRAINT general_settings_product_card_style CHECK (product_card_style IN ('classic', 'showcase'))");
        $this->trigger(AuditTables::productCardStyle()['general_settings'][1]);
    }

    public function down(): void
    {
        $this->trigger(AuditTables::productImageBlend()['general_settings'][1]);
        DB::statement('ALTER TABLE general_settings DROP CONSTRAINT general_settings_product_card_style');
        Schema::table('general_settings', fn (Blueprint $t) => $t->dropColumn('product_card_style'));
    }

    private function trigger(string $fields): void
    {
        DB::statement('DROP TRIGGER IF EXISTS cms_audit_mutation ON general_settings');
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON general_settings FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('general_settings', '$fields')");
    }
};
