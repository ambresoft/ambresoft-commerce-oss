<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** The store header blocks that make way for the checkout's own header. */
    private const HEADER = ['identity', 'topbar', 'menu'];

    public function up(): void
    {
        $layout = DB::table('storefront_layouts')->where('page_type', 'checkout')->value('id');
        if ($layout === null || DB::table('storefront_blocks')->where('layout_id', $layout)->where('type', 'checkout_header')->exists()) {
            return;
        }
        // The checkout opens with its own header (logo, "secure checkout", the store's phone) above the form.
        DB::table('storefront_grids')->where('layout_id', $layout)->where('region', 'content')->whereNull('parent_id')->increment('row');
        $grid = (string) Str::uuid();
        $block = (string) Str::uuid();
        DB::table('storefront_grids')->insert(['id' => $grid, 'layout_id' => $layout, 'region' => 'content', 'width' => 16, 'row' => 1, 'position' => 1]);
        DB::table('storefront_blocks')->insert(['id' => $block, 'layout_id' => $layout, 'type' => 'checkout_header', 'name' => 'Checkout header', 'template' => 'checkout_header', 'settings' => '{}']);
        DB::table('storefront_placements')->insert(['id' => (string) Str::uuid(), 'layout_id' => $layout, 'grid_id' => $grid, 'block_id' => $block, 'position' => 1]);
        foreach (DB::table('locales')->pluck('id') as $locale) {
            DB::table('storefront_block_translations')->insert(['block_id' => $block, 'locale_id' => $locale, 'title' => '']);
        }
        DB::table('storefront_layouts')->where('id', $layout)->increment('lock_version');
        // The store header hides itself there through its existing "Hide on these pages" setting, which staff can change.
        $this->headers(fn (string $paths): string => in_array('/checkout', preg_split('/\R/', $paths) ?: [], true) ? $paths : ltrim($paths."\n/checkout", "\n"));
    }

    public function down(): void
    {
        $this->headers(fn (string $paths): string => implode("\n", array_filter(preg_split('/\R/', $paths) ?: [], fn (string $line): bool => trim($line) !== '/checkout')));
        $layout = DB::table('storefront_layouts')->where('page_type', 'checkout')->value('id');
        $header = DB::table('storefront_placements as p')->join('storefront_blocks as b', 'b.id', '=', 'p.block_id')->where('p.layout_id', $layout)->where('b.type', 'checkout_header')->first(['p.grid_id', 'b.id']);
        if ($header === null) {
            return;
        }
        DB::table('storefront_blocks')->where('id', $header->id)->delete();
        DB::table('storefront_grids')->where('id', $header->grid_id)->delete();
        DB::table('storefront_grids')->where('layout_id', $layout)->where('region', 'content')->whereNull('parent_id')->decrement('row');
        DB::table('storefront_layouts')->where('id', $layout)->increment('lock_version');
    }

    /** @param callable(string): string $change */
    private function headers(callable $change): void
    {
        $default = DB::table('storefront_layouts')->where('page_type', 'default')->value('id');
        foreach (DB::table('storefront_blocks')->where('layout_id', $default)->whereIn('type', self::HEADER)->get(['id', 'settings']) as $block) {
            $settings = json_decode((string) $block->settings, true) ?: [];
            $settings['hidden_paths'] = $change((string) ($settings['hidden_paths'] ?? ''));
            DB::table('storefront_blocks')->where('id', $block->id)->update(['settings' => json_encode($settings, JSON_THROW_ON_ERROR)]);
        }
        DB::table('storefront_layouts')->where('id', $default)->increment('lock_version');
    }
};
