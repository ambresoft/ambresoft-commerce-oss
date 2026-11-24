<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_catalog_state', function (Blueprint $t): void {
            $t->integer('id')->primary();
            $t->bigInteger('version')->default(1);
        });
        DB::statement('ALTER TABLE feature_catalog_state ADD CONSTRAINT feature_catalog_singleton CHECK (id = 1 AND version > 0)');
        DB::table('feature_catalog_state')->insert(['id' => 1]);
        Schema::create('feature_groups', function (Blueprint $t): void {
            $t->id();
            $t->string('system_key')->nullable()->unique();
            $t->integer('position');
            $t->bigInteger('lock_version')->default(1);
        });
        DB::statement("ALTER TABLE feature_groups ADD CONSTRAINT feature_group_configuration CHECK (position > 0 AND lock_version > 0 AND (system_key IS NULL OR system_key = 'general'))");
        DB::table('feature_groups')->insert(['system_key' => 'general', 'position' => 1]);
        Schema::create('feature_group_translations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('group_id')->constrained('feature_groups')->cascadeOnDelete();
            $t->foreignId('locale_id')->constrained('locales')->restrictOnDelete();
            $t->string('name', 200)->default('');
            $t->text('description')->default('');
            $t->unique(['group_id', 'locale_id']);
            $t->index('locale_id');
        });
        Schema::create('features', function (Blueprint $t): void {
            $t->id();
            $t->string('code', 200)->unique();
            $t->string('type', 16);
            $t->string('status', 16)->default('active');
            $t->foreignId('group_id')->constrained('feature_groups')->restrictOnDelete();
            $t->integer('position');
            $t->string('unit', 60)->nullable();
            $t->boolean('required')->default(false);
            $t->boolean('filterable')->default(false);
            $t->boolean('comparison')->default(false);
            $t->bigInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->index(['group_id', 'position', 'id']);
            $t->index(['status', 'type']);
            $t->unique(['id', 'type']);
        });
        Schema::create('feature_translations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('feature_id')->constrained('features')->cascadeOnDelete();
            $t->foreignId('locale_id')->constrained('locales')->restrictOnDelete();
            $t->string('name', 200)->default('');
            $t->text('description')->default('');
            $t->string('slug', 200)->nullable();
            $t->string('seo_title', 200)->default('');
            $t->string('seo_description', 500)->default('');
            $t->unique(['feature_id', 'locale_id']);
            $t->unique(['locale_id', 'slug']);
        });
        Schema::create('feature_categories', function (Blueprint $t): void {
            $t->foreignId('feature_id')->constrained('features')->cascadeOnDelete();
            // Restrictions must never silently become global when a category is removed.
            $t->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $t->primary(['feature_id', 'category_id']);
            $t->index('category_id');
        });
        Schema::create('feature_options', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('feature_id')->constrained('features')->cascadeOnDelete();
            $t->string('status', 16)->default('active');
            $t->integer('position');
            $t->bigInteger('lock_version')->default(1);
            $t->unique(['id', 'feature_id']);
            $t->index(['feature_id', 'position', 'id']);
        });
        Schema::create('feature_option_translations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('option_id')->constrained('feature_options')->cascadeOnDelete();
            $t->foreignId('locale_id')->constrained('locales')->restrictOnDelete();
            $t->string('label', 200)->default('');
            $t->unique(['option_id', 'locale_id']);
            $t->index('locale_id');
        });
        Schema::create('product_feature_assignments', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $t->foreignId('feature_id')->constrained('features')->restrictOnDelete();
            $t->string('type', 16);
            $t->boolean('is_applicable')->default(true);
            $t->decimal('number_value', 20, 6)->nullable();
            $t->boolean('boolean_value')->nullable();
            $t->bigInteger('lock_version')->default(1);
            $t->unique(['product_id', 'feature_id']);
            $t->unique(['id', 'feature_id', 'type']);
            $t->index(['feature_id', 'is_applicable']);
            $t->foreign(['feature_id', 'type'])->references(['id', 'type'])->on('features')->restrictOnDelete();
        });
        Schema::create('product_feature_texts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('assignment_id')->constrained('product_feature_assignments')->cascadeOnDelete();
            $t->foreignId('locale_id')->constrained('locales')->restrictOnDelete();
            $t->text('value');
            $t->unique(['assignment_id', 'locale_id']);
            $t->index('locale_id');
        });
        Schema::create('product_feature_choices', function (Blueprint $t): void {
            $t->foreignId('assignment_id')->constrained('product_feature_assignments')->cascadeOnDelete();
            $t->foreignId('feature_id');
            $t->foreignId('option_id');
            $t->string('type', 16);
            $t->primary(['assignment_id', 'option_id']);
            $t->foreign(['assignment_id', 'feature_id', 'type'])->references(['id', 'feature_id', 'type'])->on('product_feature_assignments')->cascadeOnDelete();
            $t->foreign(['option_id', 'feature_id'])->references(['id', 'feature_id'])->on('feature_options')->restrictOnDelete();
            $t->index(['option_id', 'feature_id']);
            $t->index('feature_id');
        });
        Schema::create('feature_audits', function (Blueprint $t): void {
            $t->id();
            $t->string('entity', 20);
            $t->bigInteger('entity_id')->index();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('action', 60);
            $t->jsonb('context');
            $t->timestampTz('occurred_at')->useCurrent();
        });
        Schema::create('feature_demo_records', function (Blueprint $t): void {
            $t->string('key')->primary();
            $t->string('entity', 20);
            $t->bigInteger('entity_id');
        });
        DB::statement("ALTER TABLE features ADD CONSTRAINT feature_configuration CHECK (type IN ('text','single','number','boolean','multiple') AND status IN ('active','archived') AND (type = 'number' OR unit IS NULL) AND (type <> 'text' OR NOT filterable) AND position > 0 AND lock_version > 0)");
        DB::statement("ALTER TABLE feature_options ADD CONSTRAINT feature_option_configuration CHECK (status IN ('active','archived') AND position > 0 AND lock_version > 0)");
        DB::statement("ALTER TABLE feature_translations ADD CONSTRAINT feature_slug CHECK (slug IS NULL OR slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$')");
        DB::statement("ALTER TABLE product_feature_assignments ADD CONSTRAINT feature_scalar_type CHECK ((type = 'number' OR number_value IS NULL) AND (type = 'boolean' OR boolean_value IS NULL) AND lock_version > 0 AND (number_value IS NULL OR number_value <> 'NaN'::numeric))");
        DB::statement("ALTER TABLE product_feature_choices ADD CONSTRAINT feature_choice_type CHECK (type IN ('single','multiple'))");
        DB::statement("CREATE UNIQUE INDEX feature_single_choice ON product_feature_choices (assignment_id) WHERE type = 'single'");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION cms_feature_identity_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.code IS DISTINCT FROM OLD.code OR NEW.type IS DISTINCT FROM OLD.type THEN
        RAISE EXCEPTION 'Feature identity and type are immutable' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER feature_identity BEFORE UPDATE ON features FOR EACH ROW EXECUTE FUNCTION cms_feature_identity_guard();
CREATE OR REPLACE FUNCTION cms_feature_group_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.system_key IS NOT NULL THEN RAISE EXCEPTION 'General is protected' USING ERRCODE = '23514'; END IF;
        RETURN OLD;
    END IF;
    IF NEW.system_key IS DISTINCT FROM OLD.system_key THEN RAISE EXCEPTION 'Group identity is immutable' USING ERRCODE = '23514'; END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER feature_group_identity BEFORE UPDATE OR DELETE ON feature_groups FOR EACH ROW EXECUTE FUNCTION cms_feature_group_guard();
CREATE OR REPLACE FUNCTION cms_feature_group_translation_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF EXISTS (SELECT 1 FROM feature_groups WHERE id = NEW.group_id AND system_key IS NOT NULL) THEN
        RAISE EXCEPTION 'General names use UI translations' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER feature_group_translation BEFORE INSERT OR UPDATE ON feature_group_translations FOR EACH ROW EXECUTE FUNCTION cms_feature_group_translation_guard();
CREATE OR REPLACE FUNCTION cms_feature_option_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM features WHERE id = NEW.feature_id AND type IN ('single','multiple')) THEN
        RAISE EXCEPTION 'Options require a choice feature' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER feature_option_type BEFORE INSERT OR UPDATE ON feature_options FOR EACH ROW EXECUTE FUNCTION cms_feature_option_guard();
CREATE OR REPLACE FUNCTION cms_feature_text_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM product_feature_assignments WHERE id = NEW.assignment_id AND type = 'text') THEN
        RAISE EXCEPTION 'Text requires a text feature' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER feature_text_type BEFORE INSERT OR UPDATE ON product_feature_texts FOR EACH ROW EXECUTE FUNCTION cms_feature_text_guard();
SQL);
    }

    public function down(): void
    {
        foreach (['feature_demo_records', 'feature_audits', 'product_feature_choices', 'product_feature_texts', 'product_feature_assignments', 'feature_option_translations', 'feature_options', 'feature_categories', 'feature_translations', 'features', 'feature_group_translations', 'feature_groups', 'feature_catalog_state'] as $table) {
            Schema::dropIfExists($table);
        }
        foreach (['cms_feature_identity_guard', 'cms_feature_group_guard', 'cms_feature_text_guard', 'cms_feature_group_translation_guard', 'cms_feature_option_guard'] as $function) {
            DB::statement('DROP FUNCTION IF EXISTS '.$function.'()');
        }
    }
};
