<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $titles = [
            'sitemap' => ['en' => 'Sitemap', 'el' => 'Χάρτης ιστοτόπου', 'es' => 'Mapa del sitio', 'fr' => 'Plan du site', 'it' => 'Mappa del sito', 'nl' => 'Sitemap'],
            'brands' => ['en' => 'All brands', 'el' => 'Όλες οι μάρκες', 'es' => 'Todas las marcas', 'fr' => 'Toutes les marques', 'it' => 'Tutti i marchi', 'nl' => 'Alle merken'],
        ];
        foreach ($titles as $page => $names) {
            if (DB::table('storefront_layouts')->where('page_type', $page)->exists()) {
                continue;
            }
            $layout = DB::table('storefront_layouts')->insertGetId(['page_type' => $page, 'position' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $grid = (string) Str::uuid();
            $block = (string) Str::uuid();
            DB::table('storefront_grids')->insert(['id' => $grid, 'layout_id' => $layout, 'region' => 'content', 'width' => 16, 'row' => 1, 'position' => 1]);
            DB::table('storefront_blocks')->insert(['id' => $block, 'layout_id' => $layout, 'type' => 'main_content', 'name' => 'Main content', 'template' => 'main_content', 'settings' => '{}']);
            DB::table('storefront_placements')->insert(['id' => (string) Str::uuid(), 'layout_id' => $layout, 'grid_id' => $grid, 'block_id' => $block, 'position' => 1]);
            foreach (DB::table('locales')->get(['id', 'code']) as $locale) {
                DB::table('storefront_block_translations')->insert(['block_id' => $block, 'locale_id' => $locale->id, 'title' => '']);
                DB::table('storefront_layout_translations')->insert(['layout_id' => $layout, 'locale_id' => $locale->id, 'page_title' => $names[$locale->code] ?? $names['en']]);
            }
        }
    }

    public function down(): void
    {
        DB::table('storefront_layouts')->whereIn('page_type', ['sitemap', 'brands'])->delete();
    }
};
