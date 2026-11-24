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
        Schema::create('product_sales', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $t->foreignId('customer_group_id')->constrained('customer_groups')->restrictOnDelete();
            $t->integer('minimum_quantity')->default(1);
            $t->integer('priority')->default(1);
            $t->string('kind', 12);
            $t->decimal('amount', 20, 6);
            $t->integer('stock_limit')->nullable();
            $t->date('starts_on')->nullable();
            $t->date('ends_on')->nullable();
            $t->boolean('is_enabled')->default(true);
            $t->timestampsTz();
            $t->index(['product_id', 'is_enabled']);
            $t->index('customer_group_id');
        });
        DB::statement("ALTER TABLE product_sales ADD CONSTRAINT product_sales_valid CHECK (minimum_quantity BETWEEN 1 AND 1000000 AND priority BETWEEN 0 AND 99999 AND kind IN ('fixed','percentage') AND amount >= 0 AND (kind <> 'percentage' OR amount <= 100) AND (stock_limit IS NULL OR stock_limit >= 0) AND (starts_on IS NULL OR ends_on IS NULL OR starts_on <= ends_on))");
        Schema::create('product_sale_allocations', function (Blueprint $t): void {
            $t->foreignId('item_id')->primary()->constrained('order_items')->cascadeOnDelete();
            $t->foreignId('sale_id')->constrained('product_sales')->cascadeOnDelete();
            $t->integer('quantity');
            $t->index('sale_id');
        });
        DB::statement('ALTER TABLE product_sale_allocations ADD CONSTRAINT product_sale_allocations_quantity CHECK (quantity >= 0)');
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION catalog_check_sale_allocation() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE offer product_sales%ROWTYPE; used bigint; item_product bigint;
BEGIN
    SELECT * INTO STRICT offer FROM product_sales WHERE id = NEW.sale_id FOR UPDATE;
    SELECT product_id INTO item_product FROM order_items WHERE id = NEW.item_id;
    IF item_product IS DISTINCT FROM offer.product_id THEN
        RAISE EXCEPTION 'Sale belongs to another product' USING ERRCODE = '23514';
    END IF;
    SELECT coalesce(sum(quantity), 0) INTO used FROM product_sale_allocations WHERE sale_id = NEW.sale_id AND item_id <> NEW.item_id;
    IF offer.stock_limit IS NOT NULL AND used + NEW.quantity > offer.stock_limit THEN
        RAISE EXCEPTION 'Sale stock exceeded' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$
SQL);
        DB::statement('CREATE TRIGGER product_sale_allocation_guard BEFORE INSERT OR UPDATE ON product_sale_allocations FOR EACH ROW EXECUTE FUNCTION catalog_check_sale_allocation()');
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION catalog_check_sale_limit() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.stock_limit IS NOT NULL AND NEW.stock_limit < (SELECT coalesce(sum(quantity), 0) FROM product_sale_allocations WHERE sale_id = NEW.id) THEN
        RAISE EXCEPTION 'Sale stock below reserved units' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$
SQL);
        DB::statement('CREATE TRIGGER product_sale_limit_guard BEFORE UPDATE OF stock_limit ON product_sales FOR EACH ROW EXECUTE FUNCTION catalog_check_sale_limit()');
        foreach (AuditTables::productSales() as $table => [$entity, $fields]) {
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
    }

    public function down(): void
    {
        // Added by a later migration; rolling this one back directly must not be blocked by it.
        Schema::dropIfExists('product_sale_translations');
        Schema::dropIfExists('product_sale_allocations');
        Schema::dropIfExists('product_sales');
        DB::statement('DROP FUNCTION IF EXISTS catalog_check_sale_allocation()');
        DB::statement('DROP FUNCTION IF EXISTS catalog_check_sale_limit()');
    }
};
