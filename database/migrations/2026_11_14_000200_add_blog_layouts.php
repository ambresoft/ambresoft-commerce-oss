<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** The Blog and Blog post layouts: the shared Default regions plus a content region holding the protected main content block. */
    public function up(): void
    {
        foreach (['blog', 'blog_post'] as $type) {
            if (DB::table('storefront_layouts')->where('page_type', $type)->exists()) {
                continue;
            }
            $layout = DB::table('storefront_layouts')->insertGetId(['page_type' => $type, 'position' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $grid = (string) Str::uuid();
            $block = (string) Str::uuid();
            DB::table('storefront_grids')->insert(['id' => $grid, 'layout_id' => $layout, 'region' => 'content', 'width' => 16, 'row' => 1, 'position' => 1]);
            DB::table('storefront_blocks')->insert(['id' => $block, 'layout_id' => $layout, 'type' => 'main_content', 'name' => 'Main content', 'template' => 'main_content', 'settings' => '{}']);
            DB::table('storefront_placements')->insert(['id' => (string) Str::uuid(), 'layout_id' => $layout, 'grid_id' => $grid, 'block_id' => $block, 'position' => 1]);
            foreach (DB::table('locales')->pluck('id') as $locale) {
                DB::table('storefront_block_translations')->insert(['block_id' => $block, 'locale_id' => $locale, 'title' => '']);
            }
        }
    }

    public function down(): void
    {
        DB::table('storefront_layouts')->whereIn('page_type', ['blog', 'blog_post'])->delete();
    }
};
