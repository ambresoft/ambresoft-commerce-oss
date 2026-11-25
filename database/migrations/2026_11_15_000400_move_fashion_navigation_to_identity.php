<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('storefront_blocks')->where('type', 'menu')->where('template', 'menu_fashion')->orderBy('id')->get() as $menu) {
            $identity = DB::table('storefront_blocks')->where('layout_id', $menu->layout_id)->where('type', 'identity')->first();
            $placements = DB::table('storefront_placements')->where('block_id', $menu->id)->pluck('id');
            $enabled = DB::table('storefront_placements')->whereIn('id', $placements)->where('enabled', true)->exists();
            if ($identity !== null && $enabled) {
                $settings = json_decode($identity->settings, true);
                $source = json_decode($menu->settings, true);
                $settings = [...$settings, ...array_intersect_key($source, array_flip(['show_logo', 'show_search', 'show_account', 'show_call_request', 'show_favorites', 'show_cart', 'show_mini_cart', 'show_language_switcher', 'icon_order'])),
                    'header_style' => 'fashion', 'background_color' => ($source['background_color'] ?? '') ?: '#ffffff', 'text_color' => ($source['text_color'] ?? '') ?: '#333333', 'icon_color' => ''];
                if (isset($settings['icon_order']) && ! in_array('call', $settings['icon_order'], true)) {
                    $settings['icon_order'][] = 'call';
                }
                DB::table('storefront_blocks')->where('id', $identity->id)->update(['settings' => json_encode((object) $settings)]);
                $selection = DB::table('storefront_block_selections')->where('block_id', $menu->id)->where('field', 'main')->orderBy('position')->first();
                if ($selection !== null) {
                    DB::table('storefront_block_selections')->where('block_id', $identity->id)->where('field', 'drawer_menu')->delete();
                    DB::table('storefront_block_selections')->insert(['block_id' => $identity->id, 'field' => 'drawer_menu', 'position' => 1, 'menu_id' => $selection->menu_id]);
                }
                // Retain the old navigation and selections, but avoid a second navigation bar.
                DB::table('storefront_placements')->whereIn('id', $placements)->update(['enabled' => false]);
                DB::table('storefront_overrides')->whereIn('placement_id', $placements)->update(['enabled' => false]);
            }
            DB::table('storefront_blocks')->where('id', $menu->id)->update(['template' => 'menu']);
            DB::table('storefront_layouts')->where('id', $menu->layout_id)->increment('lock_version');
        }
    }

    public function down(): void
    {
        // Presentation settings are retained when rolling back; never overwrite subsequent merchant edits.
    }
};
