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
            // One global quantity per product record; every variation is its own product and owns its quantity.
            // Existing rows start at 0: no earlier stock value exists, and none is invented.
            $t->integer('quantity')->default(0);
            // Reference/strike-through price. NULL for existing products: price is never copied into it.
            $t->decimal('list_price', 20, 6)->nullable();
            $t->unsignedInteger('min_qty')->nullable();
            $t->unsignedInteger('max_qty')->nullable();
            $t->unsignedInteger('qty_step')->nullable();
            // N = do not allow purchase at zero stock (default), B = allow backorders.
            $t->string('out_of_stock_actions', 1)->default('N');
        });
        DB::statement('ALTER TABLE products ADD CONSTRAINT product_stock_ordering CHECK (quantity >= 0 AND (list_price IS NULL OR (list_price >= 0 AND list_price <> \'NaN\'::numeric)) AND (min_qty IS NULL OR min_qty > 0) AND (max_qty IS NULL OR max_qty > 0) AND (qty_step IS NULL OR qty_step > 0) AND (min_qty IS NULL OR max_qty IS NULL OR max_qty >= min_qty) AND out_of_stock_actions IN (\'N\', \'B\'))');

        // Central ledger capture includes the new reviewed fields.
        [$entity, $fields] = AuditTables::stock()['products'];
        DB::statement('DROP TRIGGER IF EXISTS cms_audit_mutation ON products');
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON products FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
    }

    public function down(): void
    {
        [$entity, $fields] = AuditTables::all()['products'];
        DB::statement('DROP TRIGGER IF EXISTS cms_audit_mutation ON products');
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON products FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        DB::statement('ALTER TABLE products DROP CONSTRAINT product_stock_ordering');
        // Rollback discards stock quantities and ordering rules; use it only before merchants enter them.
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['quantity', 'list_price', 'min_qty', 'max_qty', 'qty_step', 'out_of_stock_actions']));
    }
};
