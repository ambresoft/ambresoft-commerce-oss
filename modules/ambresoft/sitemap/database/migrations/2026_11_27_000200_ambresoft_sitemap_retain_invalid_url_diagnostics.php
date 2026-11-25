<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Keep an overlong discovered address as a warning instead of aborting an otherwise valid draft refresh.
        foreach (['ambresoft_sitemap_draft_urls', 'ambresoft_sitemap_version_urls'] as $table) {
            DB::statement("ALTER TABLE $table ALTER COLUMN canonical_url TYPE text");
        }
        DB::statement('ALTER TABLE ambresoft_sitemap_versions DROP CONSTRAINT IF EXISTS ambresoft_sitemap_versions_sections_check');
        DB::statement("ALTER TABLE ambresoft_sitemap_versions ADD CONSTRAINT ambresoft_sitemap_section_snapshot_shape CHECK (jsonb_typeof(sections) = 'object')");
    }

    public function down(): void
    {
        foreach (['ambresoft_sitemap_draft_urls', 'ambresoft_sitemap_version_urls'] as $table) {
            if (DB::table($table)->whereRaw('length(canonical_url) > 2048')->exists()) {
                throw new RuntimeException('Retained long URL diagnostics must be resolved before narrowing the URL column.');
            }
            DB::statement("ALTER TABLE $table ALTER COLUMN canonical_url TYPE varchar(2048)");
        }
        DB::statement('ALTER TABLE ambresoft_sitemap_versions DROP CONSTRAINT ambresoft_sitemap_section_snapshot_shape');
        DB::statement("ALTER TABLE ambresoft_sitemap_versions ADD CHECK (jsonb_typeof(sections) = 'object')");
    }
};
