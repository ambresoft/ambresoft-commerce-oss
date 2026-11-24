<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Banner preloading is no longer on/off: none, first or all. On meant the first banner.
        DB::statement("UPDATE storefront_blocks SET settings = jsonb_set(settings, '{preload}', CASE WHEN settings->'preload' = 'true'::jsonb THEN '\"first\"'::jsonb ELSE '\"none\"'::jsonb END) WHERE type = 'banners' AND jsonb_typeof(settings->'preload') = 'boolean'");
        DB::table('storefront_layouts')->increment('lock_version');
    }

    public function down(): void
    {
        DB::statement("UPDATE storefront_blocks SET settings = jsonb_set(settings, '{preload}', CASE WHEN settings->>'preload' = 'none' THEN 'false'::jsonb ELSE 'true'::jsonb END) WHERE type = 'banners' AND jsonb_typeof(settings->'preload') = 'string'");
        DB::table('storefront_layouts')->increment('lock_version');
    }
};
