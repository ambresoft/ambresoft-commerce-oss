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
        Schema::create('storefront_layouts', function (Blueprint $t): void {
            $t->id();
            $t->string('page_type', 80)->unique();
            $t->bigInteger('lock_version')->default(1);
            $t->timestampsTz();
        });
        Schema::create('storefront_grids', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignId('layout_id')->constrained('storefront_layouts')->cascadeOnDelete();
            $t->uuid('parent_id')->nullable();
            $t->string('region', 80);
            $t->smallInteger('width');
            $t->integer('row');
            $t->integer('position');
            $t->unique(['id', 'layout_id']);
            $t->index(['layout_id', 'parent_id', 'region', 'row', 'position']);
        });
        DB::statement('ALTER TABLE storefront_grids ADD CONSTRAINT storefront_grid_parent FOREIGN KEY (parent_id, layout_id) REFERENCES storefront_grids(id, layout_id) ON DELETE CASCADE DEFERRABLE INITIALLY DEFERRED');
        DB::statement('ALTER TABLE storefront_grids ADD CONSTRAINT storefront_grid_values CHECK (width BETWEEN 1 AND 16 AND row > 0 AND position > 0 AND id IS DISTINCT FROM parent_id)');
        Schema::create('storefront_blocks', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignId('layout_id')->constrained('storefront_layouts')->cascadeOnDelete();
            $t->string('type', 80);
            $t->string('name', 128);
            $t->string('template', 80);
            $t->string('css_class', 128)->default('');
            $t->jsonb('settings')->default('{}');
            $t->unique(['id', 'layout_id']);
        });
        DB::statement("ALTER TABLE storefront_blocks ADD CONSTRAINT storefront_block_values CHECK (btrim(name) <> '' AND css_class ~ '^[a-zA-Z0-9_ -]*$' AND jsonb_typeof(settings) = 'object')");
        Schema::create('storefront_block_translations', function (Blueprint $t): void {
            $t->foreignUuid('block_id')->constrained('storefront_blocks')->cascadeOnDelete();
            $t->foreignId('locale_id')->constrained('locales')->restrictOnDelete();
            $t->string('title', 200)->default('');
            $t->text('html')->default('');
            $t->primary(['block_id', 'locale_id']);
            $t->index('locale_id');
        });
        Schema::create('storefront_block_selections', function (Blueprint $t): void {
            $t->id();
            $t->foreignUuid('block_id')->constrained('storefront_blocks')->cascadeOnDelete();
            $t->string('field', 80);
            $t->integer('position');
            foreach (['product' => 'products', 'category' => 'categories', 'banner' => 'banners', 'banner_group' => 'banner_groups'] as $key => $table) {
                $t->foreignId($key.'_id')->nullable()->constrained($table)->cascadeOnDelete();
                $t->index($key.'_id');
            }
            $t->unique(['block_id', 'field', 'position']);
        });
        DB::statement('ALTER TABLE storefront_block_selections ADD CONSTRAINT storefront_selection CHECK (num_nonnulls(product_id, category_id, banner_id, banner_group_id) = 1 AND position > 0)');
        Schema::create('storefront_placements', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignId('layout_id')->constrained('storefront_layouts')->cascadeOnDelete();
            $t->uuid('grid_id');
            $t->uuid('block_id')->unique();
            $t->integer('position');
            $t->boolean('enabled')->default(true);
            foreach (['mobile', 'tablet', 'desktop'] as $device) {
                $t->boolean($device)->default(true);
            }
            $t->foreign(['grid_id', 'layout_id'])->references(['id', 'layout_id'])->on('storefront_grids')->cascadeOnDelete();
            $t->foreign(['block_id', 'layout_id'])->references(['id', 'layout_id'])->on('storefront_blocks')->cascadeOnDelete();
            $t->index(['grid_id', 'position']);
        });
        DB::statement('ALTER TABLE storefront_placements ADD CONSTRAINT storefront_placement_position CHECK (position > 0)');
        Schema::create('storefront_overrides', function (Blueprint $t): void {
            $t->foreignId('layout_id')->constrained('storefront_layouts')->cascadeOnDelete();
            $t->foreignUuid('placement_id')->constrained('storefront_placements')->cascadeOnDelete();
            $t->boolean('enabled');
            $t->uuid('block_id')->nullable()->unique();
            $t->foreign(['block_id', 'layout_id'])->references(['id', 'layout_id'])->on('storefront_blocks')->cascadeOnDelete();
            $t->primary(['layout_id', 'placement_id']);
            $t->index('placement_id');
        });
        foreach (['default', 'homepage'] as $page) {
            $layout = DB::table('storefront_layouts')->insertGetId(['page_type' => $page, 'created_at' => now(), 'updated_at' => now()]);
            $blocks = $page === 'default' ? [['identity', 'header', 'identity', [], '']] : [
                ['banners', 'content', 'banner_carousel', ['source' => 'latest', 'limit' => 3], ''],
                ['products', 'content', 'product_carousel', ['source' => 'newest', 'limit' => 8], 'New arrivals'],
                ['categories', 'content', 'category_carousel', ['limit' => 8], 'Categories'],
            ];
            foreach ($blocks as $n => [$type, $region, $template, $settings, $title]) {
                $grid = (string) Str::uuid();
                $block = (string) Str::uuid();
                DB::table('storefront_grids')->insert(['id' => $grid, 'layout_id' => $layout, 'region' => $region, 'width' => 16, 'row' => $n + 1, 'position' => 1]);
                DB::table('storefront_blocks')->insert(['id' => $block, 'layout_id' => $layout, 'type' => $type, 'name' => ucfirst($type), 'template' => $template, 'settings' => json_encode((object) $settings)]);
                DB::table('storefront_placements')->insert(['id' => (string) Str::uuid(), 'layout_id' => $layout, 'grid_id' => $grid, 'block_id' => $block, 'position' => 1]);
                foreach (DB::table('locales')->get() as $locale) {
                    DB::table('storefront_block_translations')->insert(['block_id' => $block, 'locale_id' => $locale->id, 'title' => $locale->code === 'el' ? (['New arrivals' => 'Νέες αφίξεις', 'Categories' => 'Κατηγορίες'][$title] ?? '') : $title]);
                }
            }
        }
        foreach (AuditTables::storefront() as $table => [$entity, $fields]) {
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
    }

    public function down(): void
    {
        foreach (['storefront_overrides', 'storefront_placements', 'storefront_block_selections', 'storefront_block_translations', 'storefront_blocks', 'storefront_grids', 'storefront_layouts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
