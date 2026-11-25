<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX product_images_exclusive_asset ON product_images (media_file_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS product_images_exclusive_asset');
    }
};
