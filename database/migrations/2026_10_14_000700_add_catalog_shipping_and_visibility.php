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
        Schema::table('products', function (Blueprint $t): void {
            $t->boolean('free_shipping')->default(false);
            // Product-specific shipping charge in the primary currency (exact decimal, never float). NULL: none configured.
            $t->decimal('shipping_cost', 20, 6)->nullable();
            // Release/pre-order date: purchasable from this calendar day. NULL: no release restriction.
            $t->date('available_from')->nullable();
            // Optional box packaging. NULL counts mean packaging constraints are disabled.
            $t->unsignedInteger('box_min_items')->nullable();
            $t->unsignedInteger('box_max_items')->nullable();
            foreach (['box_length', 'box_width', 'box_height'] as $field) {
                $t->decimal($field, 15, 6)->nullable();
            }
        });
        DB::statement("ALTER TABLE products ADD CONSTRAINT product_shipping_packaging CHECK (
            (shipping_cost IS NULL OR (shipping_cost >= 0 AND shipping_cost <> 'NaN'::numeric))
            AND (box_min_items IS NULL OR box_min_items > 0) AND (box_max_items IS NULL OR box_max_items > 0)
            AND (box_min_items IS NULL OR box_max_items IS NULL OR box_max_items >= box_min_items)
            AND (box_length IS NULL OR box_length >= 0) AND (box_width IS NULL OR box_width >= 0) AND (box_height IS NULL OR box_height >= 0)
            AND ((box_min_items IS NULL AND box_max_items IS NULL) OR (COALESCE(box_length, 0) > 0 AND COALESCE(box_width, 0) > 0 AND COALESCE(box_height, 0) > 0)))");

        Schema::table('categories', function (Blueprint $t): void {
            // Hides the category's products from site search and product highlight widgets; never from the category itself.
            $t->boolean('exclude_from_discovery')->default(false);
        });
        DB::statement('ALTER TABLE categories ADD CONSTRAINT categories_system_discovery_check CHECK (system_key IS NULL OR NOT exclude_from_discovery)');

        // Empty means every visitor; otherwise members of at least one listed customer group. Deleting a group that
        // still restricts catalog content is refused rather than silently widening visibility.
        Schema::create('category_customer_groups', function (Blueprint $t): void {
            $t->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $t->foreignId('group_id')->constrained('customer_groups')->restrictOnDelete();
            $t->primary(['category_id', 'group_id']);
            $t->index('group_id');
        });
        Schema::create('product_customer_groups', function (Blueprint $t): void {
            $t->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $t->foreignId('group_id')->constrained('customer_groups')->restrictOnDelete();
            $t->primary(['product_id', 'group_id']);
            $t->index('group_id');
        });

        foreach (AuditTables::catalogVisibility() as $table => [$entity, $fields]) {
            DB::statement("DROP TRIGGER IF EXISTS cms_audit_mutation ON $table");
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
    }

    public function down(): void
    {
        foreach (['products' => AuditTables::stock()['products'], 'categories' => AuditTables::categoryArticle39a()['categories']] as $table => [$entity, $fields]) {
            DB::statement("DROP TRIGGER IF EXISTS cms_audit_mutation ON $table");
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
        Schema::dropIfExists('product_customer_groups');
        Schema::dropIfExists('category_customer_groups');
        DB::statement('ALTER TABLE categories DROP CONSTRAINT categories_system_discovery_check');
        DB::statement('ALTER TABLE products DROP CONSTRAINT product_shipping_packaging');
        // Rollback discards these settings; use it only before merchants enter them.
        Schema::table('categories', fn (Blueprint $t) => $t->dropColumn('exclude_from_discovery'));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['free_shipping', 'shipping_cost', 'available_from', 'box_min_items', 'box_max_items', 'box_length', 'box_width', 'box_height']));
    }
};
