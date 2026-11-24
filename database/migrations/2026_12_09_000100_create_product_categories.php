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
        // Extra categories a product is also listed in. products.category_id stays the main category: it alone sets the
        // product's address, breadcrumb, features and report category.
        Schema::create('product_categories', function (Blueprint $t): void {
            $t->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $t->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $t->integer('position');
            $t->primary(['product_id', 'category_id']);
            $t->index('category_id');
        });
        DB::statement('ALTER TABLE product_categories ADD CONSTRAINT product_category_position CHECK (position > 0)');
        foreach (AuditTables::productExtraCategories() as $table => [$entity, $fields]) {
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
        // Category listings are cached by the storefront: link changes invalidate them like product changes.
        DB::unprepared('CREATE TRIGGER cms_storefront_cache AFTER INSERT OR UPDATE OR DELETE OR TRUNCATE ON product_categories FOR EACH STATEMENT EXECUTE FUNCTION cms_invalidate_storefront_cache(\'products\')');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_categories');
    }
};
