<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Brand pages open with the brand's picture, name and description across the whole width: a new first row of
        // the content region holds the protected page heading block; the rows already there move down by one.
        $layout = DB::table('storefront_layouts')->where('page_type', 'catalog_landing')->value('id');
        if ($layout === null || DB::table('storefront_blocks')->where('layout_id', $layout)->where('type', 'page_header')->exists()) {
            return;
        }
        DB::table('storefront_grids')->where('layout_id', $layout)->where('region', 'content')->whereNull('parent_id')->increment('row');
        $grid = (string) Str::uuid();
        $block = (string) Str::uuid();
        DB::table('storefront_grids')->insert(['id' => $grid, 'layout_id' => $layout, 'region' => 'content', 'width' => 16, 'row' => 1, 'position' => 1]);
        DB::table('storefront_blocks')->insert(['id' => $block, 'layout_id' => $layout, 'type' => 'page_header', 'name' => 'Page heading', 'template' => 'page_header', 'settings' => '{}']);
        DB::table('storefront_placements')->insert(['id' => (string) Str::uuid(), 'layout_id' => $layout, 'grid_id' => $grid, 'block_id' => $block, 'position' => 1]);
        foreach (DB::table('locales')->pluck('id') as $locale) {
            DB::table('storefront_block_translations')->insert(['block_id' => $block, 'locale_id' => $locale, 'title' => '']);
        }
        DB::table('storefront_layouts')->where('id', $layout)->increment('lock_version');
    }

    public function down(): void
    {
        $layout = DB::table('storefront_layouts')->where('page_type', 'catalog_landing')->value('id');
        $header = DB::table('storefront_placements as p')->join('storefront_blocks as b', 'b.id', '=', 'p.block_id')->where('p.layout_id', $layout)->where('b.type', 'page_header')->first(['p.grid_id', 'b.id']);
        if ($header === null) {
            return;
        }
        DB::table('storefront_blocks')->where('id', $header->id)->delete();
        DB::table('storefront_grids')->where('id', $header->grid_id)->delete();
        DB::table('storefront_grids')->where('layout_id', $layout)->where('region', 'content')->whereNull('parent_id')->decrement('row');
        DB::table('storefront_layouts')->where('id', $layout)->increment('lock_version');
    }
};
