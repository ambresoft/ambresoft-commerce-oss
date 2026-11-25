<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the discontinued Feed Engine add-on and the core Timeline presentation it introduced (A146). Layouts that
 * used the Timeline header or Carousel Bubbles fall back to the default header and menu; Timeline banners, feed blocks
 * and the add-on's own tables are deleted. Safe on databases that never installed it.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Placements, selections, translations and overrides cascade with their blocks.
        DB::table('storefront_blocks')->whereIn('type', ['timeline_feed', 'timeline_header', 'timeline_menu'])->delete();
        DB::table('storefront_layouts')->where('page_type', 'timeline')->delete();
        DB::statement("UPDATE storefront_blocks SET settings = jsonb_set(settings, '{header_style}', '\"classic\"') WHERE settings->>'header_style' = 'timeline'");
        DB::table('storefront_blocks')->where('template', 'menu_bubbles_carousel')->update(['template' => 'menu']);

        foreach (['ambresoft_feed_item_translations', 'ambresoft_feed_item_products', 'ambresoft_feed_locales', 'ambresoft_feed_items', 'ambresoft_feed_configurations'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::table('addons')->where('name', 'ambresoft/feed-engine')->delete();

        if (Schema::hasColumn('banners', 'presentation_type')) {
            DB::table('banners')->where('presentation_type', 'timeline')->delete();
            Schema::dropIfExists('banner_timeline_contents');
            DB::statement('ALTER TABLE banners DROP CONSTRAINT IF EXISTS banners_presentation_check');
            DB::statement('ALTER TABLE banners DROP COLUMN presentation_type');
        }

        DB::table('migrations')->where('migration', 'like', '%\_ambresoft\_feed\_engine\_%')->orWhere('migration', '2026_11_30_000200_add_timeline_banners')->delete();
    }

    public function down(): void
    {
        // Irreversible: the feature and its data were removed on purpose.
    }
};
