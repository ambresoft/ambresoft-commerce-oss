<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // A category listing's default page (newest first) reads its first products in index order instead of sorting
        // the whole category: measured 50 ms -> 21 ms for a 20,000-product category (docs/architecture/category-listings.md).
        DB::statement('CREATE INDEX IF NOT EXISTS products_category_newest ON products (category_id, created_at DESC, id DESC)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS products_category_newest');
    }
};
