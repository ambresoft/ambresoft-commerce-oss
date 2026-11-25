<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing definitions become Standard Attributes; purpose is never inferred from names or codes.
        Schema::table('features', function (Blueprint $t): void {
            $t->string('purpose', 32)->default('standard');
        });
        DB::statement("ALTER TABLE features ADD CONSTRAINT feature_purpose CHECK (purpose IN ('standard','landing_pages') AND (purpose = 'standard' OR type = 'single'))");

        // Feature-level SEO cannot be mapped unambiguously onto individual variants. Retain any
        // non-empty values in the audit history (restored by down()) before removing the columns.
        DB::statement(<<<'SQL'
INSERT INTO feature_audits (entity, entity_id, actor_id, action, context, occurred_at)
SELECT 'feature', ft.feature_id, NULL, 'seo_retired',
       jsonb_build_object('locale', l.code, 'slug', ft.slug, 'seo_title', ft.seo_title, 'seo_description', ft.seo_description),
       now()
FROM feature_translations ft JOIN locales l ON l.id = ft.locale_id
WHERE ft.slug IS NOT NULL OR ft.seo_title <> '' OR ft.seo_description <> ''
ORDER BY ft.feature_id, ft.locale_id
SQL);
        DB::statement('ALTER TABLE feature_translations DROP CONSTRAINT feature_slug');
        Schema::table('feature_translations', function (Blueprint $t): void {
            $t->dropUnique(['locale_id', 'slug']);
            $t->dropColumn(['slug', 'seo_title', 'seo_description']);
        });

        // Variant landing-page metadata belongs to the stable option and its locale-linked row.
        Schema::table('feature_option_translations', function (Blueprint $t): void {
            $t->text('description')->default('');
            $t->string('seo_title', 200)->default('');
            $t->string('seo_description', 500)->default('');
            $t->string('meta_keywords', 1000)->default('');
            $t->string('slug', 200)->nullable();
            $t->unique(['locale_id', 'slug']);
        });
        DB::statement("ALTER TABLE feature_option_translations ADD CONSTRAINT feature_option_slug CHECK (slug IS NULL OR slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$')");
        Schema::table('feature_options', function (Blueprint $t): void {
            $t->foreignId('landing_image_media_id')->nullable()->unique()->constrained('media_files')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('feature_options', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('landing_image_media_id');
        });
        DB::statement('ALTER TABLE feature_option_translations DROP CONSTRAINT feature_option_slug');
        Schema::table('feature_option_translations', function (Blueprint $t): void {
            $t->dropUnique(['locale_id', 'slug']);
            $t->dropColumn(['description', 'seo_title', 'seo_description', 'meta_keywords', 'slug']);
        });
        Schema::table('feature_translations', function (Blueprint $t): void {
            $t->string('slug', 200)->nullable();
            $t->string('seo_title', 200)->default('');
            $t->string('seo_description', 500)->default('');
            $t->unique(['locale_id', 'slug']);
        });
        DB::statement("ALTER TABLE feature_translations ADD CONSTRAINT feature_slug CHECK (slug IS NULL OR slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$')");
        DB::statement(<<<'SQL'
UPDATE feature_translations ft SET slug = NULLIF(a.context->>'slug', ''), seo_title = COALESCE(a.context->>'seo_title', ''), seo_description = COALESCE(a.context->>'seo_description', '')
FROM feature_audits a JOIN locales l ON l.code = a.context->>'locale'
WHERE a.entity = 'feature' AND a.action = 'seo_retired' AND a.entity_id = ft.feature_id AND l.id = ft.locale_id
SQL);
        DB::table('feature_audits')->where('entity', 'feature')->where('action', 'seo_retired')->whereNull('actor_id')->delete();
        DB::statement('ALTER TABLE features DROP CONSTRAINT feature_purpose');
        Schema::table('features', fn (Blueprint $t) => $t->dropColumn('purpose'));
    }
};
