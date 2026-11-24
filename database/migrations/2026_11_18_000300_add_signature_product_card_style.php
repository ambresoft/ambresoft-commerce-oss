<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE general_settings DROP CONSTRAINT general_settings_product_card_style');
        DB::statement("ALTER TABLE general_settings ADD CONSTRAINT general_settings_product_card_style CHECK (product_card_style IN ('classic', 'showcase', 'signature'))");
    }

    public function down(): void
    {
        // The older application can still render the shared Showcase presentation.
        DB::table('general_settings')->where('product_card_style', 'signature')->update(['product_card_style' => 'showcase']);
        DB::statement('ALTER TABLE general_settings DROP CONSTRAINT general_settings_product_card_style');
        DB::statement("ALTER TABLE general_settings ADD CONSTRAINT general_settings_product_card_style CHECK (product_card_style IN ('classic', 'showcase'))");
    }
};
