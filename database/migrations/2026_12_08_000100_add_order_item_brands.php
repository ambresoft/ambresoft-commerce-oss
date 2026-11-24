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
        // The brand a line was sold under, kept with the order like its name and price: moving a product to another
        // brand (or deleting the brand) never rewrites past brand sales. The name survives a deleted brand.
        Schema::table('order_items', function (Blueprint $t): void {
            $t->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $t->string('brand_name', 200)->nullable();
            $t->index('brand_id');
        });
        // Past orders: the best available classification is the product's current brand.
        DB::statement(<<<'SQL'
            UPDATE order_items oi SET brand_id = p.brand_id, brand_name = (
                SELECT bt.name FROM brand_translations bt JOIN locales l ON l.id = bt.locale_id
                WHERE bt.brand_id = p.brand_id AND btrim(bt.name) <> '' ORDER BY l.is_default DESC, l.id LIMIT 1)
            FROM products p WHERE p.id = oi.product_id AND p.brand_id IS NOT NULL
            SQL);
        $this->trigger(AuditTables::orderItemBrands()['order_items'][1]);
    }

    public function down(): void
    {
        $this->trigger(AuditTables::coupons()['order_items'][1]);
        Schema::table('order_items', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('brand_id');
            $t->dropColumn('brand_name');
        });
    }

    private function trigger(string $fields): void
    {
        DB::statement('DROP TRIGGER IF EXISTS cms_audit_mutation ON order_items');
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON order_items FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('order_item', '$fields')");
    }
};
