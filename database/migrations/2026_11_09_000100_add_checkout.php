<?php

use App\Audit\Infrastructure\AuditTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Guests may check out unless the merchant requires an account for every order.
        Schema::table('general_settings', fn (Blueprint $t) => $t->boolean('checkout_requires_account')->default(false));
        $this->trigger(AuditTables::checkoutAccountSetting()['general_settings'][1]);
        // The checkout page layout: the Default shared regions plus a content region holding the protected main content block.
        if (DB::table('storefront_layouts')->where('page_type', 'checkout')->exists()) {
            return;
        }
        $layout = DB::table('storefront_layouts')->insertGetId(['page_type' => 'checkout', 'position' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $grid = (string) Str::uuid();
        $block = (string) Str::uuid();
        DB::table('storefront_grids')->insert(['id' => $grid, 'layout_id' => $layout, 'region' => 'content', 'width' => 16, 'row' => 1, 'position' => 1]);
        DB::table('storefront_blocks')->insert(['id' => $block, 'layout_id' => $layout, 'type' => 'main_content', 'name' => 'Main content', 'template' => 'main_content', 'settings' => '{}']);
        DB::table('storefront_placements')->insert(['id' => (string) Str::uuid(), 'layout_id' => $layout, 'grid_id' => $grid, 'block_id' => $block, 'position' => 1]);
        foreach (DB::table('locales')->pluck('id') as $locale) {
            DB::table('storefront_block_translations')->insert(['block_id' => $block, 'locale_id' => $locale, 'title' => '']);
        }
    }

    public function down(): void
    {
        DB::table('storefront_layouts')->where('page_type', 'checkout')->delete();
        $this->trigger(AuditTables::landingSourceSetting()['general_settings'][1]);
        Schema::table('general_settings', fn (Blueprint $t) => $t->dropColumn('checkout_requires_account'));
    }

    private function trigger(string $fields): void
    {
        DB::statement('DROP TRIGGER IF EXISTS cms_audit_mutation ON general_settings');
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON general_settings FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('general_settings', '$fields')");
    }
};
