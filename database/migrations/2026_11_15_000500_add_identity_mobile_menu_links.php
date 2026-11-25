<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storefront_block_translations', function (Blueprint $table): void {
            $table->jsonb('mobile_links')->nullable();
        });
        DB::statement("ALTER TABLE storefront_block_translations ADD CONSTRAINT storefront_mobile_links_object CHECK (mobile_links IS NULL OR jsonb_typeof(mobile_links) = 'object')");
        // Invalidate cached projections and editors loaded before the new content field existed.
        DB::table('storefront_layouts')->increment('lock_version');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE storefront_block_translations DROP CONSTRAINT storefront_mobile_links_object');
        Schema::table('storefront_block_translations', fn (Blueprint $table) => $table->dropColumn('mobile_links'));
        DB::table('storefront_layouts')->increment('lock_version');
    }
};
