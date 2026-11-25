<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $t): void {
            $t->id();
            $t->string('type', 16)->default('physical');
            $t->string('sku', 200)->nullable();
            $t->string('status', 16)->default('disabled');
            $t->decimal('price', 20, 6)->nullable();
            $t->foreignId('category_id')->nullable()->constrained('categories')->restrictOnDelete();
            foreach (['weight', 'length', 'width', 'height'] as $field) {
                $t->decimal($field, 15, 6)->nullable();
            }
            $t->unsignedBigInteger('lock_version')->default(1);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->softDeletesTz();
            $t->index(['deleted_at', 'updated_at', 'id']);
            $t->index(['category_id', 'id']);
            $t->index(['status', 'type']);
        });
        DB::statement('CREATE UNIQUE INDEX products_sku_unique ON products (lower(sku)) WHERE sku IS NOT NULL');
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_values_check CHECK (type IN ('physical','digital') AND status IN ('active','disabled','hidden') AND lock_version > 0 AND (sku IS NULL OR (sku = btrim(sku) AND length(sku) > 0)) AND (price IS NULL OR price >= 0) AND (weight IS NULL OR weight >= 0) AND (length IS NULL OR length >= 0) AND (width IS NULL OR width >= 0) AND (height IS NULL OR height >= 0))");
        Schema::create('product_translations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $t->foreignId('locale_id')->constrained('locales')->restrictOnDelete();
            $t->string('name', 200)->default('');
            $t->text('description')->default('');
            $t->string('seo_title', 200)->default('');
            $t->string('seo_description', 500)->default('');
            $t->string('meta_keywords', 500)->default('');
            $t->string('slug', 200)->nullable();
            $t->timestampsTz();
            $t->unique(['product_id', 'locale_id']);
            $t->unique(['locale_id', 'slug']);
        });
        DB::statement("ALTER TABLE product_translations ADD CONSTRAINT product_slug_check CHECK (slug IS NULL OR slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$')");
        Schema::create('product_images', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $t->foreignId('media_file_id')->constrained('media_files')->restrictOnDelete();
            $t->integer('position');
            $t->boolean('is_primary')->default(false);
            $t->unique(['product_id', 'media_file_id']);
            $t->unique(['product_id', 'position']);
            $t->index('media_file_id');
        });
        DB::statement('CREATE UNIQUE INDEX product_one_primary_image ON product_images (product_id) WHERE is_primary');
        DB::statement('ALTER TABLE product_images ADD CONSTRAINT product_image_position CHECK (position > 0)');
        Schema::create('private_product_files', function (Blueprint $t): void {
            $t->id();
            $t->string('disk', 40)->default('local');
            $t->string('storage_key')->unique();
            $t->string('original_name');
            $t->string('mime_type', 100);
            $t->unsignedBigInteger('byte_size');
            $t->boolean('ready')->default(false);
            $t->timestampsTz();
        });
        Schema::create('product_files', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $t->foreignId('private_file_id')->constrained('private_product_files')->restrictOnDelete();
            $t->string('display_name');
            $t->integer('position');
            $t->unique(['product_id', 'position']);
            $t->index('private_file_id');
        });
        DB::statement('ALTER TABLE product_files ADD CONSTRAINT product_file_values CHECK (position > 0 AND length(btrim(display_name)) > 0)');
        Schema::create('product_file_cleanups', function (Blueprint $t): void {
            $t->foreignId('private_file_id')->primary()->constrained('private_product_files')->cascadeOnDelete();
            $t->timestampTz('available_at')->index();
            $t->timestampTz('dispatched_at')->nullable();
        });
        Schema::create('product_audits', function (Blueprint $t): void {
            $t->id();
            // Deliberately no product FK: audit evidence survives permanent deletion.
            $t->unsignedBigInteger('product_id')->nullable()->index();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('action', 60);
            $t->jsonb('context');
            $t->timestampTz('occurred_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['product_audits', 'product_file_cleanups', 'product_files', 'private_product_files', 'product_images', 'product_translations', 'products'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
