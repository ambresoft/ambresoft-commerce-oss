<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // A customer's private lists of products. The general list ("All favourites", is_default) has no name of its
        // own; it holds every saved product, and named lists group some of them.
        Schema::create('wishlists', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $t->string('name', 60)->nullable();
            $t->boolean('is_default')->default(false);
            $t->timestampsTz();
            $t->index('customer_id');
        });
        DB::statement("ALTER TABLE wishlists ADD CONSTRAINT wishlist_values CHECK ((is_default AND name IS NULL) OR (NOT is_default AND btrim(name) <> ''))");
        DB::statement('CREATE UNIQUE INDEX wishlists_one_general_list ON wishlists (customer_id) WHERE is_default');
        DB::statement('CREATE UNIQUE INDEX wishlists_name_unique ON wishlists (customer_id, lower(name)) WHERE NOT is_default');
        Schema::create('wishlist_items', function (Blueprint $t): void {
            $t->foreignId('wishlist_id')->constrained('wishlists')->cascadeOnDelete();
            $t->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->primary(['wishlist_id', 'product_id']);
            $t->index('product_id');
            $t->index(['wishlist_id', 'created_at']);
        });

        // The wishlist page layout: the Default shared regions plus a content region holding the protected main content block.
        if (! DB::table('storefront_layouts')->where('page_type', 'wishlist')->exists()) {
            $layout = DB::table('storefront_layouts')->insertGetId(['page_type' => 'wishlist', 'position' => 0, 'created_at' => now(), 'updated_at' => now()]);
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
        Schema::dropIfExists('wishlist_items');
        Schema::dropIfExists('wishlists');
        // Grids, blocks, placements and texts follow through their cascading keys.
        DB::table('storefront_layouts')->where('page_type', 'wishlist')->delete();
    }
};
