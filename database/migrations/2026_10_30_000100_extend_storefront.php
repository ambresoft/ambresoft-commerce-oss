<?php

use App\Audit\Infrastructure\AuditTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Storefront additions on top of the starter layouts: store colors, menu/top bar blocks, block texts and listing settings. */
return new class extends Migration
{
    private const COLORS = ['primary' => '#05ab7d', 'primary_hover' => '#079c72', 'on_primary' => '#ffffff', 'text' => '#333333', 'background' => '#f8f8f8', 'sale' => '#ff3d47'];

    public function up(): void
    {
        // A second picture of a category, shown on category tiles in storefront blocks.
        Schema::table('categories', function (Blueprint $t): void {
            $t->foreignId('block_image_media_id')->nullable()->unique()->constrained('media_files')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE categories ADD CONSTRAINT categories_system_block_image_check CHECK (system_key IS NULL OR block_image_media_id IS NULL)');
        $this->audit('categories', fn (string $fields): string => $fields.',block_image_media_id');

        // Listings skip products without stock; the product's own page stays public.
        Schema::table('general_settings', fn (Blueprint $t) => $t->boolean('hide_out_of_stock_listings')->default(false));
        $this->audit('general_settings', fn (string $fields): string => $fields.',hide_out_of_stock_listings');

        // The store's brand colors: one row, strict #rrggbb values.
        Schema::create('storefront_theme_settings', function (Blueprint $t): void {
            $t->smallInteger('id')->primary();
            foreach (self::COLORS as $name => $default) {
                $t->string($name.'_color', 7)->default($default);
            }
            $t->bigInteger('lock_version')->default(1);
            $t->timestampsTz();
        });
        $checks = implode(' AND ', array_map(fn (string $c): string => "{$c}_color ~ '^#[0-9a-f]{6}$'", array_keys(self::COLORS)));
        DB::statement("ALTER TABLE storefront_theme_settings ADD CONSTRAINT storefront_theme_single CHECK (id = 1 AND $checks)");
        DB::table('storefront_theme_settings')->insert(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        foreach (AuditTables::storefrontTheme() as $table => [$entity, $fields]) {
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }

        // Block texts: a second rich text (top bar, right side) and the optional "see all" link text.
        Schema::table('storefront_block_translations', function (Blueprint $t): void {
            $t->text('html_right')->default('');
            $t->string('link_text', 100)->default('');
        });

        // Menu blocks pick website menus, so a block selection can also point at a menu.
        Schema::table('storefront_block_selections', function (Blueprint $t): void {
            $t->foreignId('menu_id')->nullable()->constrained('website_menus')->cascadeOnDelete();
            $t->index('menu_id');
        });
        DB::statement('ALTER TABLE storefront_block_selections DROP CONSTRAINT storefront_selection');
        DB::statement('ALTER TABLE storefront_block_selections ADD CONSTRAINT storefront_selection CHECK (num_nonnulls(product_id, category_id, banner_id, banner_group_id, menu_id) = 1 AND position > 0)');
        $this->audit('storefront_block_selections', fn (string $fields): string => $fields.',menu_id');

        // Starter blocks of the shared layout; they render nothing until they have content.
        $layout = DB::table('storefront_layouts')->where('page_type', 'default')->value('id');
        if ($layout !== null) {
            $this->starter($layout, 'top_panel', 'topbar', 'Top bar');
            $this->starter($layout, 'navigation', 'menu', 'Menu');
            $this->starter($layout, 'footer', 'footer', 'Footer');
        }
    }

    public function down(): void
    {
        DB::table('storefront_blocks')->whereIn('type', ['topbar', 'menu', 'footer'])->delete();
        DB::statement('ALTER TABLE storefront_block_selections DROP CONSTRAINT storefront_selection');
        Schema::table('storefront_block_selections', fn (Blueprint $t) => $t->dropConstrainedForeignId('menu_id'));
        DB::statement('ALTER TABLE storefront_block_selections ADD CONSTRAINT storefront_selection CHECK (num_nonnulls(product_id, category_id, banner_id, banner_group_id) = 1 AND position > 0)');
        Schema::table('storefront_block_translations', fn (Blueprint $t) => $t->dropColumn(['html_right', 'link_text']));
        Schema::dropIfExists('storefront_theme_settings');
        $this->audit('general_settings', fn (string $fields): string => $this->without($fields, 'hide_out_of_stock_listings'));
        Schema::table('general_settings', fn (Blueprint $t) => $t->dropColumn('hide_out_of_stock_listings'));
        $this->audit('categories', fn (string $fields): string => $this->without($fields, 'block_image_media_id'));
        DB::statement('ALTER TABLE categories DROP CONSTRAINT categories_system_block_image_check');
        Schema::table('categories', fn (Blueprint $t) => $t->dropConstrainedForeignId('block_image_media_id'));
    }

    private function without(string $fields, string $field): string
    {
        return implode(',', array_diff(explode(',', $fields), [$field]));
    }

    private function starter(int|string $layout, string $region, string $type, string $name): void
    {
        $grid = (string) Str::uuid();
        $block = (string) Str::uuid();
        DB::table('storefront_grids')->insert(['id' => $grid, 'layout_id' => $layout, 'region' => $region, 'width' => 16, 'row' => 1, 'position' => 1]);
        DB::table('storefront_blocks')->insert(['id' => $block, 'layout_id' => $layout, 'type' => $type, 'name' => $name, 'template' => $type, 'settings' => json_encode((object) [])]);
        DB::table('storefront_placements')->insert(['id' => (string) Str::uuid(), 'layout_id' => $layout, 'grid_id' => $grid, 'block_id' => $block, 'position' => 1]);
        foreach (DB::table('locales')->get() as $locale) {
            DB::table('storefront_block_translations')->insert(['block_id' => $block, 'locale_id' => $locale->id]);
        }
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
