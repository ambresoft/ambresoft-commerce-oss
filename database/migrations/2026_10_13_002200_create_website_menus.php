<?php

use App\Audit\Infrastructure\AuditTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_menus', function (Blueprint $t): void {
            $t->id();
            $t->string('status', 10)->default('active');
            $t->string('placement', 10)->default('header');
            $t->string('source', 10)->default('manual');
            $t->bigInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->index(['status', 'id']);
        });
        Schema::create('website_menu_translations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('menu_id')->constrained('website_menus')->cascadeOnDelete();
            $t->foreignId('locale_id')->constrained('locales')->restrictOnDelete();
            $t->string('name', 200);
            $t->unique(['menu_id', 'locale_id']);
            $t->index('locale_id');
        });
        Schema::create('website_menu_items', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('menu_id')->constrained('website_menus')->cascadeOnDelete();
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->string('status', 10)->default('active');
            $t->integer('position')->default(10);
            $t->string('kind', 10)->default('url');
            $t->string('url', 2048)->nullable();
            $t->foreignId('category_id')->nullable()->constrained('categories')->restrictOnDelete();
            $t->boolean('generate_children')->default(false);
            $t->boolean('new_window')->default(false);
            $t->foreignId('image_media_id')->nullable()->constrained('media_files')->restrictOnDelete();
            $t->bigInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->unique(['menu_id', 'id']);
            $t->foreign(['menu_id', 'parent_id'])->references(['menu_id', 'id'])->on('website_menu_items')->cascadeOnDelete();
            $t->index(['menu_id', 'parent_id', 'position', 'id']);
            $t->index('category_id');
            $t->index('image_media_id');
        });
        Schema::create('website_menu_item_translations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('item_id')->constrained('website_menu_items')->cascadeOnDelete();
            $t->foreignId('locale_id')->constrained('locales')->restrictOnDelete();
            $t->string('name', 200);
            $t->string('description', 500)->default('');
            $t->string('image_alt', 200)->default('');
            $t->unique(['item_id', 'locale_id']);
            $t->index('locale_id');
        });
        DB::statement("ALTER TABLE website_menus ADD CONSTRAINT website_menus_valid CHECK (status IN ('active','disabled') AND placement IN ('header','footer','other') AND source IN ('manual','categories') AND lock_version > 0)");
        DB::statement("ALTER TABLE website_menu_items ADD CONSTRAINT website_menu_items_valid CHECK (status IN ('active','disabled') AND position >= 0 AND lock_version > 0 AND (parent_id IS NULL OR parent_id <> id) AND kind IN ('heading','url','category') AND (NOT generate_children OR kind = 'category') AND ((kind = 'heading' AND url IS NULL AND category_id IS NULL) OR (kind = 'url' AND url IS NOT NULL AND category_id IS NULL) OR (kind = 'category' AND url IS NULL AND category_id IS NOT NULL)))");
        foreach (['website_menu_translations', 'website_menu_item_translations'] as $table) {
            DB::statement("ALTER TABLE $table ADD CONSTRAINT {$table}_name CHECK (btrim(name) <> '')");
        }
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION cms_validate_menu_item() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    PERFORM pg_advisory_xact_lock(2142513);
    PERFORM id FROM website_menus WHERE id = NEW.menu_id FOR UPDATE;
    IF TG_OP = 'UPDATE' AND NEW.menu_id <> OLD.menu_id THEN
        RAISE EXCEPTION 'Menu identity is immutable' USING ERRCODE = '23514';
    END IF;
    IF EXISTS (WITH RECURSIVE ancestors AS (
        SELECT id,parent_id FROM website_menu_items WHERE id = NEW.parent_id
        UNION SELECT i.id,i.parent_id FROM website_menu_items i JOIN ancestors a ON i.id = a.parent_id
    ) SELECT 1 FROM ancestors WHERE id = NEW.id) THEN
        RAISE EXCEPTION 'Menu cycle' USING ERRCODE = '23514';
    END IF;
    IF EXISTS (SELECT 1 FROM website_menu_items WHERE id = NEW.parent_id AND generate_children) OR
       (NEW.generate_children AND EXISTS (SELECT 1 FROM website_menu_items WHERE parent_id = NEW.id)) THEN
        RAISE EXCEPTION 'Generated submenus cannot contain manual children' USING ERRCODE = '23514';
    END IF;
    IF EXISTS (SELECT 1 FROM categories WHERE id = NEW.category_id AND system_key IS NOT NULL) THEN
        RAISE EXCEPTION 'System category cannot be a menu target' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER cms_menu_integrity BEFORE INSERT OR UPDATE ON website_menu_items FOR EACH ROW EXECUTE FUNCTION cms_validate_menu_item();
SQL);
        // A merchant-selected URL may contain a private query parameter. Audit only its change/length.
        $definition = DB::selectOne("SELECT pg_get_functiondef('cms_audit_safe_value(text,jsonb)'::regprocedure) AS definition")->definition;
        DB::unprepared(str_replace("('description','seo_description','age_warning_message','value')", "('description','seo_description','age_warning_message','value','url')", $definition));
        foreach (AuditTables::menus() as $table => [$entity, $fields]) {
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('website_menu_item_translations');
        Schema::dropIfExists('website_menu_items');
        Schema::dropIfExists('website_menu_translations');
        Schema::dropIfExists('website_menus');
        DB::statement('DROP FUNCTION IF EXISTS cms_validate_menu_item()');
        $definition = DB::selectOne("SELECT pg_get_functiondef('cms_audit_safe_value(text,jsonb)'::regprocedure) AS definition")->definition;
        DB::unprepared(str_replace("('description','seo_description','age_warning_message','value','url')", "('description','seo_description','age_warning_message','value')", $definition));
    }
};
