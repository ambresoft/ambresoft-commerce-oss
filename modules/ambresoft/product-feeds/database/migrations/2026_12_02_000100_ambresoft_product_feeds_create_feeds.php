<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ambresoft_product_feeds', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('name', 120);
            $t->string('channel', 10);
            $t->text('cron_password');
            $t->string('status', 10)->default('active');
            $t->string('scope', 12)->default('all');
            $t->foreignId('locale_id')->nullable()->constrained('locales')->nullOnDelete();
            $t->string('id_source', 4)->default('id');
            $t->string('description_source', 5)->default('full');
            $t->unsignedInteger('description_limit')->nullable();
            $t->boolean('title_for_empty_description')->default(true);
            $t->text('empty_description')->default('');
            $t->jsonb('attributes')->default('[]');
            $t->boolean('apply_promotions')->default(true);
            $t->boolean('use_list_price')->default(false);
            $t->boolean('discount_label')->default(false);
            $t->boolean('show_categories')->default(true);
            $t->boolean('additional_images')->default(false);
            $t->boolean('in_stock_only')->default(false);
            $t->decimal('min_price', 18, 6)->nullable();
            $t->boolean('show_weight')->default(false);
            $t->unsignedInteger('product_count')->nullable();
            $t->timestampTz('generated_at')->nullable();
            $t->string('last_error', 500)->nullable();
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE ambresoft_product_feeds ADD CONSTRAINT ambresoft_product_feeds_values_check CHECK (channel IN ('google', 'meta') AND status IN ('active', 'disabled') AND scope IN ('all', 'categories') AND id_source IN ('id', 'sku') AND description_source IN ('full', 'short') AND (description_limit IS NULL OR description_limit > 0) AND (min_price IS NULL OR min_price >= 0) AND jsonb_typeof(attributes) = 'array' AND btrim(name) <> '')");

        Schema::create('ambresoft_product_feed_categories', function (Blueprint $t): void {
            $t->foreignUuid('feed_id')->constrained('ambresoft_product_feeds')->cascadeOnDelete();
            $t->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $t->primary(['feed_id', 'category_id']);
            $t->index('category_id');
        });

        // The last good file per feed; a failed generation leaves it in place.
        Schema::create('ambresoft_product_feed_files', function (Blueprint $t): void {
            $t->foreignUuid('feed_id')->primary()->constrained('ambresoft_product_feeds')->cascadeOnDelete();
            $t->text('xml');
            $t->string('etag', 64);
            $t->timestampTz('generated_at');
        });
    }

    public function down(): void
    {
        foreach (['ambresoft_product_feed_files', 'ambresoft_product_feed_categories', 'ambresoft_product_feeds'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
