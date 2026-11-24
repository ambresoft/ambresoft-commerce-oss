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
        Schema::create('brands', function (Blueprint $t): void {
            $t->id();
            $t->string('code', 80)->unique();
            $t->string('status', 10)->default('active');
            $t->integer('position')->default(0);
            $t->foreignId('image_media_id')->nullable()->constrained('media_files')->restrictOnDelete();
            $t->bigInteger('lock_version')->default(1);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->index(['status', 'position', 'id']);
            $t->index('image_media_id');
        });
        DB::statement("ALTER TABLE brands ADD CONSTRAINT brand_values CHECK (code ~ '^[a-z0-9][a-z0-9_-]{0,79}$' AND status IN ('active','disabled') AND position >= 0 AND lock_version > 0)");
        Schema::create('brand_translations', function (Blueprint $t): void {
            $t->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $t->foreignId('locale_id')->constrained('locales')->restrictOnDelete();
            $t->string('name', 200);
            $t->text('description')->default('');
            $t->string('image_alt', 200)->default('');
            $t->primary(['brand_id', 'locale_id']);
            $t->index('locale_id');
        });
        DB::statement("ALTER TABLE brand_translations ADD CONSTRAINT brand_name CHECK (btrim(name) <> '')");
        Schema::table('products', function (Blueprint $t): void {
            $t->foreignId('brand_id')->nullable()->constrained('brands')->restrictOnDelete();
            $t->index(['brand_id', 'status', 'id']);
        });
        Schema::table('storefront_block_selections', function (Blueprint $t): void {
            $t->foreignId('brand_id')->nullable()->constrained('brands')->cascadeOnDelete();
            $t->index('brand_id');
        });
        DB::statement('ALTER TABLE storefront_block_selections DROP CONSTRAINT storefront_selection');
        DB::statement('ALTER TABLE storefront_block_selections ADD CONSTRAINT storefront_selection CHECK (num_nonnulls(product_id, category_id, banner_id, banner_group_id, menu_id, newsletter_list_id, brand_id) = 1 AND position > 0)');
        foreach (AuditTables::brands() as $table => [$entity, $fields]) {
            DB::statement("DROP TRIGGER IF EXISTS cms_audit_mutation ON $table");
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
        // Structural cache records saved before the new selection column must be rebuilt.
        DB::table('storefront_layouts')->increment('lock_version');
    }

    public function down(): void
    {
        foreach (['products' => AuditTables::productCompanions()['products'], 'storefront_block_selections' => AuditTables::storefront()['storefront_block_selections']] as $table => [$entity, $fields]) {
            DB::statement("DROP TRIGGER cms_audit_mutation ON $table");
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
        DB::table('storefront_block_selections')->whereNotNull('brand_id')->delete();
        DB::statement('ALTER TABLE storefront_block_selections DROP CONSTRAINT storefront_selection');
        Schema::table('storefront_block_selections', fn (Blueprint $t) => $t->dropConstrainedForeignId('brand_id'));
        DB::statement('ALTER TABLE storefront_block_selections ADD CONSTRAINT storefront_selection CHECK (num_nonnulls(product_id, category_id, banner_id, banner_group_id, menu_id, newsletter_list_id) = 1 AND position > 0)');
        Schema::table('products', function (Blueprint $t): void {
            $t->dropIndex(['brand_id', 'status', 'id']);
            $t->dropConstrainedForeignId('brand_id');
        });
        Schema::dropIfExists('brand_translations');
        Schema::dropIfExists('brands');
    }
};
