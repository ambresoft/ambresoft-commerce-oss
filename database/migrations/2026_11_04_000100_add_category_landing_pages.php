<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // A landing page category shows its subcategories on the storefront instead of products.
        Schema::table('categories', fn (Blueprint $t) => $t->boolean('is_landing_page')->default(false));
        DB::statement('ALTER TABLE categories ADD CONSTRAINT categories_system_landing_check CHECK (system_key IS NULL OR NOT is_landing_page)');
        $this->audit('categories', fn (string $fields): string => $fields.',is_landing_page');

        // The category landing page layout: the Default shared regions plus a content region holding the protected main content block.
        if (DB::table('storefront_layouts')->where('page_type', 'category_landing')->exists()) {
            return;
        }
        $layout = DB::table('storefront_layouts')->insertGetId(['page_type' => 'category_landing', 'position' => 0, 'created_at' => now(), 'updated_at' => now()]);
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
        // Grids, blocks, placements and texts follow through their cascading keys.
        DB::table('storefront_layouts')->where('page_type', 'category_landing')->delete();
        $this->audit('categories', fn (string $fields): string => implode(',', array_diff(explode(',', $fields), ['is_landing_page'])));
        DB::statement('ALTER TABLE categories DROP CONSTRAINT categories_system_landing_check');
        Schema::table('categories', fn (Blueprint $t) => $t->dropColumn('is_landing_page'));
    }

    /** Extends (or shrinks) the field list that earlier additive migrations left on a table's audit trigger. */
    private function audit(string $table, callable $fields): void
    {
        $definition = DB::selectOne("SELECT pg_get_triggerdef(oid) AS def FROM pg_trigger WHERE tgname = 'cms_audit_mutation' AND tgrelid = '$table'::regclass")->def;
        if (preg_match("/cms_capture_audit\('([^']+)', '([^']+)'\)/", $definition, $m)) {
            DB::statement("DROP TRIGGER cms_audit_mutation ON $table");
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('{$m[1]}', '{$fields($m[2])}')");
        }
    }
};
