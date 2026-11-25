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
        // One installation-wide switch instead of a per-product flag; the future storefront reads it for every product.
        Schema::table('general_settings', fn (Blueprint $t) => $t->boolean('hide_product_prices')->default(false));
        // Merchant text is locale-linked; empty means no message is shown in place of the price.
        Schema::table('general_settings_translations', fn (Blueprint $t) => $t->string('price_hidden_message', 500)->default(''));
        foreach (AuditTables::priceVisibility() as $table => [$entity, $fields]) {
            DB::statement("DROP TRIGGER IF EXISTS cms_audit_mutation ON $table");
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
    }

    public function down(): void
    {
        Schema::table('general_settings_translations', fn (Blueprint $t) => $t->dropColumn('price_hidden_message'));
        Schema::table('general_settings', fn (Blueprint $t) => $t->dropColumn('hide_product_prices'));
        // Restore the previous captured field lists.
        foreach ([AuditTables::mailDelivery()['general_settings'], AuditTables::generalOptions()['general_settings_translations']] as $i => [$entity, $fields]) {
            $table = $i === 0 ? 'general_settings' : 'general_settings_translations';
            DB::statement("DROP TRIGGER IF EXISTS cms_audit_mutation ON $table");
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
    }
};
