<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** The store-wide listings (/new, /bestsellers, /offers): the Category layout's shape, each its own layout. */
    private const PAGES = ['new_products', 'bestsellers', 'offers'];

    public function up(): void
    {
        $locales = DB::table('locales')->pluck('id');
        foreach (self::PAGES as $pageType) {
            if (DB::table('storefront_layouts')->where('page_type', $pageType)->exists()) {
                continue;
            }
            // The product filters (4 of 16 columns) beside the protected main content block (the listing).
            $layout = DB::table('storefront_layouts')->insertGetId(['page_type' => $pageType, 'position' => 0, 'created_at' => now(), 'updated_at' => now()]);
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
    }

    public function down(): void
    {
        // Grids, blocks, placements and texts follow through their cascading keys.
        DB::table('storefront_layouts')->whereIn('page_type', self::PAGES)->delete();
    }
};
