<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_cache_state', function (Blueprint $t): void {
            $t->smallInteger('id')->primary();
            $t->boolean('enabled')->default(false);
            $t->unsignedInteger('lock_version')->default(1);
            foreach (['blocks', 'products', 'categories', 'brands', 'media'] as $group) {
                $t->uuid($group)->default(DB::raw('gen_random_uuid()'));
            }
        });
        DB::statement('ALTER TABLE storefront_cache_state ADD CONSTRAINT storefront_cache_singleton CHECK (id = 1)');
        DB::table('storefront_cache_state')->insert(['id' => 1]);
        // Transactional statement triggers also cover imports, bulk SQL and cascading deletes.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION cms_invalidate_storefront_cache() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_ARGV[0] = 'all' THEN
        UPDATE storefront_cache_state SET blocks = gen_random_uuid(), products = gen_random_uuid(), categories = gen_random_uuid(), brands = gen_random_uuid(), media = gen_random_uuid() WHERE id = 1;
    ELSE
        EXECUTE format('UPDATE storefront_cache_state SET %I = gen_random_uuid() WHERE id = 1', TG_ARGV[0]);
    END IF;
    RETURN NULL;
END;
$$;
SQL);
        foreach (DB::select('SELECT tablename FROM pg_tables WHERE schemaname = current_schema()') as $row) {
            $table = $row->tablename;
            $group = match (true) {
                in_array($table, ['locales', 'ui_translation_overrides', 'general_settings', 'company_settings', 'addons'], true) => 'all',
                str_starts_with($table, 'website_menu') => 'blocks',
                str_starts_with($table, 'banner') => 'blocks',
                str_starts_with($table, 'storefront_') && $table !== 'storefront_cache_state' => 'blocks',
                in_array($table, ['product_translations', 'product_images', 'product_feature_assignments', 'product_feature_choices', 'product_feature_texts', 'features', 'feature_translations', 'feature_groups', 'feature_group_translations', 'feature_options', 'feature_option_translations'], true) => 'products',
                $table === 'categories' || str_starts_with($table, 'category_') => 'categories',
                $table === 'page_translations' || $table === 'brands' || str_starts_with($table, 'brand_') => 'brands',
                str_starts_with($table, 'media_') => 'media',
                default => null,
            };
            if ($group !== null) {
                DB::unprepared('CREATE TRIGGER cms_storefront_cache AFTER INSERT OR UPDATE OR DELETE OR TRUNCATE ON "'.$table.'" FOR EACH STATEMENT EXECUTE FUNCTION cms_invalidate_storefront_cache(\''.$group.'\')');
            }
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS cms_invalidate_storefront_cache() CASCADE');
        Schema::dropIfExists('storefront_cache_state');
    }
};
