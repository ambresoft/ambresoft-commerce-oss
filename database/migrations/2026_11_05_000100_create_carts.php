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
        // A shopping cart: what a visitor intends to buy. The public uuid identifies it to every client (the storefront's
        // cookie today, a mobile app later); a signed-in customer has one cart, guests one each. Prices are never stored:
        // they are read live, so a cart is not a quote.
        Schema::create('carts', function (Blueprint $t): void {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('customer_id')->nullable()->constrained('customers')->cascadeOnDelete();
            $t->timestampsTz();
            $t->index(['updated_at', 'id']);
        });
        DB::statement('CREATE UNIQUE INDEX carts_one_per_customer ON carts (customer_id) WHERE customer_id IS NOT NULL');
        Schema::create('cart_items', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('cart_id')->constrained('carts')->cascadeOnDelete();
            $t->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $t->integer('quantity');
            $t->timestampsTz();
            $t->unique(['cart_id', 'product_id']);
            $t->index('product_id');
        });
        DB::statement('ALTER TABLE cart_items ADD CONSTRAINT cart_item_quantity CHECK (quantity BETWEEN 1 AND 100000)');

        // The cart page layout: the Default shared regions plus a content region holding the protected main content block.
        if (! DB::table('storefront_layouts')->where('page_type', 'cart')->exists()) {
            $layout = DB::table('storefront_layouts')->insertGetId(['page_type' => 'cart', 'position' => 0, 'created_at' => now(), 'updated_at' => now()]);
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
        // Grids, blocks, placements and texts follow through their cascading keys.
        DB::table('storefront_layouts')->where('page_type', 'cart')->delete();
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};
