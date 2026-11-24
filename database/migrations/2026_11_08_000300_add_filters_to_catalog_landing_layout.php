<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Brand pages now list their products like categories: the product filters (4 of 16 columns) beside the main
        // content. Only the starter shape is changed (the main content alone in a full-width root grid); a layout
        // staff already rearranged keeps its structure and is reported.
        $layout = DB::table('storefront_layouts')->where('page_type', 'catalog_landing')->value('id');
        if ($layout === null || DB::table('storefront_blocks')->where('layout_id', $layout)->where('type', 'product_filters')->exists()) {
            return;
        }
        $main = DB::table('storefront_placements as p')->join('storefront_blocks as b', 'b.id', '=', 'p.block_id')->join('storefront_grids as g', 'g.id', '=', 'p.grid_id')
            ->where('p.layout_id', $layout)->where('b.type', 'main_content')->first(['g.id', 'g.width', 'g.row', 'g.parent_id', 'g.region']);
        $alone = $main !== null && $main->parent_id === null && (int) $main->width === 16
            && DB::table('storefront_grids')->where('layout_id', $layout)->where('region', $main->region)->whereNull('parent_id')->where('row', $main->row)->count() === 1;
        if (! $alone) {
            echo "  Catalog landing layout was rearranged: add the product filters grid by hand.\n";

            return;
        }
        DB::table('storefront_grids')->where('id', $main->id)->update(['width' => 12, 'position' => 2]);
        $grid = (string) Str::uuid();
        $block = (string) Str::uuid();
        DB::table('storefront_grids')->insert(['id' => $grid, 'layout_id' => $layout, 'region' => $main->region, 'width' => 4, 'row' => $main->row, 'position' => 1]);
        DB::table('storefront_blocks')->insert(['id' => $block, 'layout_id' => $layout, 'type' => 'product_filters', 'name' => 'Product filters', 'template' => 'product_filters', 'settings' => '{}']);
        DB::table('storefront_placements')->insert(['id' => (string) Str::uuid(), 'layout_id' => $layout, 'grid_id' => $grid, 'block_id' => $block, 'position' => 1]);
        foreach (DB::table('locales')->pluck('id') as $locale) {
            DB::table('storefront_block_translations')->insert(['block_id' => $block, 'locale_id' => $locale, 'title' => '']);
        }
        DB::table('storefront_layouts')->where('id', $layout)->increment('lock_version');
    }

    public function down(): void
    {
        $layout = DB::table('storefront_layouts')->where('page_type', 'catalog_landing')->value('id');
        $filters = DB::table('storefront_placements as p')->join('storefront_blocks as b', 'b.id', '=', 'p.block_id')->where('p.layout_id', $layout)->where('b.type', 'product_filters')->first(['p.grid_id', 'b.id']);
        if ($filters === null) {
            return;
        }
        DB::table('storefront_blocks')->where('id', $filters->id)->delete();
        DB::table('storefront_grids')->where('id', $filters->grid_id)->delete();
        $main = DB::table('storefront_placements as p')->join('storefront_blocks as b', 'b.id', '=', 'p.block_id')->where('p.layout_id', $layout)->where('b.type', 'main_content')->value('p.grid_id');
        DB::table('storefront_grids')->where('id', $main)->where('width', 12)->update(['width' => 16, 'position' => 1]);
        DB::table('storefront_layouts')->where('id', $layout)->increment('lock_version');
    }
};
