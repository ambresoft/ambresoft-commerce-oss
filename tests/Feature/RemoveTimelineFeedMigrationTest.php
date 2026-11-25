<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RemoveTimelineFeedMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function block(int $layout, string $type, string $template, array $settings = []): string
    {
        DB::table('storefront_blocks')->insert(['id' => $id = (string) Str::uuid(), 'layout_id' => $layout, 'type' => $type, 'name' => $type,
            'template' => $template, 'settings' => json_encode((object) $settings)]);

        return $id;
    }

    public function test_timeline_presentation_falls_back_to_defaults_and_feed_data_is_removed(): void
    {
        $layout = (int) DB::table('storefront_layouts')->insertGetId(['page_type' => 'timeline', 'created_at' => now(), 'updated_at' => now()]);
        $home = (int) DB::table('storefront_layouts')->where('page_type', '!=', 'timeline')->value('id');
        $feed = $this->block($home, 'timeline_feed', 'timeline_feed');
        $header = $this->block($home, 'identity', 'identity', ['header_style' => 'timeline', 'background_color' => '#000000']);
        $menu = $this->block($home, 'menu', 'menu_bubbles_carousel');
        $compact = $this->block($home, 'identity', 'identity', ['header_style' => 'compact']);
        Schema::create('ambresoft_feed_configurations', fn ($t) => $t->uuid('id')->primary());
        DB::table('migrations')->insert([['migration' => '2026_11_30_000100_ambresoft_feed_engine_create_feeds', 'batch' => 1], ['migration' => '2026_11_30_000200_add_timeline_banners', 'batch' => 1]]);

        (require database_path('migrations/2026_12_03_000100_remove_timeline_feed.php'))->up();

        $this->assertDatabaseMissing('storefront_blocks', ['id' => $feed]);
        $this->assertDatabaseMissing('storefront_layouts', ['id' => $layout]);
        self::assertSame(['header_style' => 'classic', 'background_color' => '#000000'], json_decode((string) DB::table('storefront_blocks')->where('id', $header)->value('settings'), true));
        self::assertSame('compact', json_decode((string) DB::table('storefront_blocks')->where('id', $compact)->value('settings'), true)['header_style']);
        self::assertSame('menu', DB::table('storefront_blocks')->where('id', $menu)->value('template'));
        self::assertFalse(Schema::hasTable('ambresoft_feed_configurations'));
        self::assertFalse(DB::table('migrations')->where('migration', 'like', '%feed_engine%')->orWhere('migration', 'like', '%timeline_banners')->exists());
    }
}
