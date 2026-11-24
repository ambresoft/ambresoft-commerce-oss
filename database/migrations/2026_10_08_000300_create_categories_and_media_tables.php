<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_files', function (Blueprint $table): void {
            $table->id();
            $table->string('disk', 40);
            $table->string('storage_key')->unique();
            $table->string('mime_type', 40);
            $table->unsignedInteger('byte_size');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE media_files ADD CONSTRAINT media_image_check CHECK (mime_type IN ('image/jpeg', 'image/png', 'image/webp') AND byte_size > 0 AND byte_size <= 5242880 AND width BETWEEN 1 AND 6000 AND height BETWEEN 1 AND 6000)");
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->integer('position');
            $table->string('status', 16)->default('active');
            $table->string('system_key', 40)->nullable()->unique();
            $table->foreignId('image_media_id')->nullable()->unique()->constrained('media_files')->restrictOnDelete();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampsTz();
            $table->index(['parent_id', 'position', 'id']);
            $table->index(['status', 'id']);
        });
        DB::statement("ALTER TABLE categories ADD CONSTRAINT categories_status_check CHECK (status IN ('active', 'disabled')), ADD CONSTRAINT categories_parent_check CHECK (parent_id IS NULL OR parent_id <> id), ADD CONSTRAINT categories_version_check CHECK (lock_version > 0), ADD CONSTRAINT categories_system_check CHECK ((system_key IS NULL AND position >= 1) OR (system_key IS NOT NULL AND system_key = 'uncategorized' AND parent_id IS NULL AND position = 0 AND status = 'disabled' AND image_media_id IS NULL)), ADD CONSTRAINT categories_sibling_position_unique UNIQUE NULLS NOT DISTINCT (parent_id, position) DEFERRABLE INITIALLY DEFERRED");
        Schema::create('category_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->foreignId('locale_id')->constrained('locales')->restrictOnDelete();
            $table->string('name', 200);
            $table->text('description')->default('');
            $table->string('seo_title', 200)->default('');
            $table->string('seo_description', 500)->default('');
            $table->string('slug', 200);
            $table->string('image_alt', 200)->default('');
            $table->timestampsTz();
            $table->unique(['category_id', 'locale_id']);
            $table->unique(['locale_id', 'slug']);
        });
        DB::statement("ALTER TABLE category_translations ADD CONSTRAINT category_translation_name_check CHECK (length(trim(name)) > 0), ADD CONSTRAINT category_translation_slug_check CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$')");
        // Installation reference data, not a merchant/demo catalog. Names are UI translations.
        DB::table('categories')->insert(['system_key' => 'uncategorized', 'parent_id' => null, 'position' => 0, 'status' => 'disabled', 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION cms_category_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    PERFORM pg_advisory_xact_lock(2142513);
    IF TG_OP = 'DELETE' THEN
        IF OLD.system_key IS NOT NULL THEN RAISE EXCEPTION 'System categories cannot be deleted' USING ERRCODE = '23514'; END IF;
        RETURN OLD;
    END IF;
    IF TG_OP = 'UPDATE' AND (OLD.system_key IS NOT NULL OR NEW.system_key IS DISTINCT FROM OLD.system_key) THEN
        RAISE EXCEPTION 'System category identity is immutable' USING ERRCODE = '23514';
    END IF;
    IF TG_OP = 'UPDATE' AND NEW.parent_id IS NOT DISTINCT FROM OLD.parent_id THEN RETURN NEW; END IF;
    IF NEW.parent_id IS NOT NULL THEN
        IF EXISTS (SELECT 1 FROM categories WHERE id = NEW.parent_id AND system_key IS NOT NULL) THEN
            RAISE EXCEPTION 'System categories cannot be parents' USING ERRCODE = '23514';
        END IF;
        IF EXISTS (
            WITH RECURSIVE ancestors AS (
                SELECT id, parent_id FROM categories WHERE id = NEW.parent_id
                UNION
                SELECT c.id, c.parent_id FROM categories c JOIN ancestors a ON c.id = a.parent_id
            ) SELECT 1 FROM ancestors WHERE id = NEW.id
        ) THEN RAISE EXCEPTION 'Category hierarchy cycle' USING ERRCODE = '23514'; END IF;
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER categories_integrity BEFORE INSERT OR UPDATE OR DELETE ON categories FOR EACH ROW EXECUTE FUNCTION cms_category_guard();
CREATE OR REPLACE FUNCTION cms_category_translation_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF EXISTS (SELECT 1 FROM categories WHERE id = NEW.category_id AND system_key IS NOT NULL) THEN
        RAISE EXCEPTION 'System category names use application translations' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER category_translations_integrity BEFORE INSERT OR UPDATE ON category_translations FOR EACH ROW EXECUTE FUNCTION cms_category_translation_guard();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('category_translations');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('media_files');
        DB::statement('DROP FUNCTION IF EXISTS cms_category_translation_guard()');
        DB::statement('DROP FUNCTION IF EXISTS cms_category_guard()');
    }
};
