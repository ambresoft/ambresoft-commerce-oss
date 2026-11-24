<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // The Category layout of product listing categories: the Default shared regions plus a content row with the
        // product filters on the left (4 of 16 columns) and the protected main content block (the listing) beside them.
        if (DB::table('storefront_layouts')->where('page_type', 'category')->exists()) {
            return;
        }
        $layout = DB::table('storefront_layouts')->insertGetId(['page_type' => 'category', 'position' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $locales = DB::table('locales')->pluck('id');
        foreach ([1 => [4, 'product_filters', 'Product filters'], 2 => [12, 'main_content', 'Main content']] as $position => [$width, $type, $name]) {
            $grid = (string) Str::uuid();
            $block = (string) Str::uuid();
            DB::table('storefront_grids')->insert(['id' => $grid, 'layout_id' => $layout, 'region' => 'content', 'width' => $width, 'row' => 1, 'position' => $position]);
            DB::table('storefront_blocks')->insert(['id' => $block, 'layout_id' => $layout, 'type' => $type, 'name' => $name, 'template' => $type, 'settings' => '{}']);
            DB::table('storefront_placements')->insert(['id' => (string) Str::uuid(), 'layout_id' => $layout, 'grid_id' => $grid, 'block_id' => $block, 'position' => 1]);
            foreach ($locales as $locale) {
                DB::table('storefront_block_translations')->insert(['block_id' => $block, 'locale_id' => $locale, 'title' => '']);
            }
        }
    }

    public function down(): void
    {
        // Grids, blocks, placements and texts follow through their cascading keys.
        DB::table('storefront_layouts')->where('page_type', 'category')->delete();
    }
};
