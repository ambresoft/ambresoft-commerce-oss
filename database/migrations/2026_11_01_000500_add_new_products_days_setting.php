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
        // Storefront "newest" product blocks show products created within this many days.
        Schema::table('general_settings', fn (Blueprint $t) => $t->integer('new_products_days')->default(90));
        DB::statement('ALTER TABLE general_settings ADD CONSTRAINT general_settings_new_products_days CHECK (new_products_days BETWEEN 1 AND 3650)');
        $this->trigger(AuditTables::newProductsWindow()['general_settings'][1]);
    }

    public function down(): void
    {
        $this->trigger(AuditTables::wishlistSetting()['general_settings'][1]);
        Schema::table('general_settings', fn (Blueprint $t) => $t->dropColumn('new_products_days'));
    }

    private function trigger(string $fields): void
    {
        DB::statement('DROP TRIGGER IF EXISTS cms_audit_mutation ON general_settings');
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON general_settings FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('general_settings', '$fields')");
    }
};
