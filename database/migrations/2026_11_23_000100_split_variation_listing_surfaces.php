<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('variation_groups', function (Blueprint $t): void {
            $t->boolean('single_carousel')->default(false);
            $t->boolean('single_search')->default(false);
        });
        DB::table('variation_groups')->update(['single_carousel' => DB::raw('single_listing'), 'single_search' => DB::raw('single_listing')]);
        Schema::table('storefront_block_selections', function (Blueprint $t): void {
            $t->foreignId('variation_group_id')->nullable()->constrained('variation_groups')->cascadeOnDelete();
            $t->index('variation_group_id');
        });
        $this->rule(', variation_group_id');
        DB::table('storefront_layouts')->increment('lock_version');
    }

    public function down(): void
    {
        DB::table('storefront_block_selections')->whereNotNull('variation_group_id')->delete();
        $this->rule('');
        Schema::table('storefront_block_selections', fn (Blueprint $t) => $t->dropConstrainedForeignId('variation_group_id'));
        Schema::table('variation_groups', fn (Blueprint $t) => $t->dropColumn(['single_carousel', 'single_search']));
    }

    private function rule(string $extra): void
    {
        DB::statement('ALTER TABLE storefront_block_selections DROP CONSTRAINT storefront_selection');
        DB::statement("ALTER TABLE storefront_block_selections ADD CONSTRAINT storefront_selection CHECK (num_nonnulls(product_id, category_id, banner_id, banner_group_id, menu_id, newsletter_list_id, brand_id, search_term_id, page_id, blog_id, blog_post_id, coupon_id, promotion_id, reel_id$extra) = 1 AND position > 0)");
    }
};
