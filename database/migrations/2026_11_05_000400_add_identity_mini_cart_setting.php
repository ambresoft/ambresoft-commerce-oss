<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Existing headers get the new default, so the layout editor shows the mini cart as on, as the storefront does.
        DB::statement("UPDATE storefront_blocks SET settings = settings || '{\"show_mini_cart\": true}'::jsonb WHERE type = 'identity' AND settings->'show_mini_cart' IS NULL");
    }

    public function down(): void
    {
        DB::statement("UPDATE storefront_blocks SET settings = settings - 'show_mini_cart' WHERE type = 'identity'");
    }
};
