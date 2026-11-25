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
        Schema::create('coupons', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 128);
            $t->string('code', 20)->unique();
            $t->string('type', 20);
            $t->decimal('amount', 20, 6);
            $t->decimal('minimum', 20, 6)->default(0);
            $t->boolean('login_required')->default(false);
            $t->boolean('free_shipping')->default(false);
            // Explicit all-products intent survives deletion of the last restricted target.
            $t->boolean('all_products')->default(true);
            $t->date('starts_on')->nullable();
            $t->date('ends_on')->nullable();
            $t->integer('total_limit')->nullable();
            $t->integer('customer_limit')->nullable();
            $t->string('status', 20)->default('active');
            $t->bigInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->softDeletesTz();
        });
        DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupon_values CHECK (length(btrim(name)) BETWEEN 3 AND 128 AND code ~ '^[A-Za-z0-9_-]{3,20}$' AND type IN ('percentage','fixed') AND amount >= 0 AND minimum >= 0 AND (amount > 0 OR free_shipping) AND (type <> 'percentage' OR amount <= 100) AND (starts_on IS NULL OR ends_on IS NULL OR ends_on >= starts_on) AND (total_limit IS NULL OR total_limit > 0) AND (customer_limit IS NULL OR customer_limit > 0) AND status IN ('active','disabled') AND lock_version > 0)");
        foreach (['products' => 'product', 'categories' => 'category'] as $table => $singular) {
            Schema::create('coupon_'.$table, function (Blueprint $t) use ($table, $singular): void {
                $t->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
                $t->foreignId($singular.'_id')->constrained($table)->cascadeOnDelete();
                $t->primary(['coupon_id', $singular.'_id']);
                $t->index($singular.'_id');
            });
        }
        Schema::table('orders', function (Blueprint $t): void {
            $t->foreignId('coupon_id')->nullable()->constrained('coupons')->restrictOnDelete();
            $t->string('coupon_code', 20)->nullable();
            $t->decimal('shipping_discount', 20, 6)->default(0);
        });
        Schema::table('order_items', fn (Blueprint $t) => $t->decimal('discount_total', 20, 6)->default(0));
        $this->itemConstraint(true);
        DB::statement('ALTER TABLE orders ADD CONSTRAINT order_coupon_values CHECK (shipping_discount >= 0 AND ((coupon_id IS NULL AND coupon_code IS NULL) OR (coupon_id IS NOT NULL AND coupon_code IS NOT NULL)))');
        Schema::create('coupon_redemptions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('coupon_id')->constrained('coupons')->restrictOnDelete();
            $t->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $t->string('customer_key', 80);
            $t->string('code', 20);
            $t->decimal('discount', 20, 6);
            $t->decimal('shipping_discount', 20, 6);
            $t->char('currency_code', 3);
            $t->smallInteger('currency_decimals');
            $t->timestampTz('created_at');
            $t->timestampTz('released_at')->nullable();
            $t->index(['coupon_id', 'customer_key', 'released_at']);
            $t->index('order_id');
        });
        DB::statement('CREATE UNIQUE INDEX coupon_order_active ON coupon_redemptions (order_id) WHERE released_at IS NULL');
        DB::statement('ALTER TABLE coupon_redemptions ADD CONSTRAINT coupon_redemption_values CHECK (discount >= 0 AND shipping_discount >= 0 AND currency_decimals BETWEEN 0 AND 6)');
        // The row lock serializes even writers outside the normal order operation. Existing usage remains valid
        // when a merchant lowers a limit; new or reactivated uses must fit the current allowance.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION cms_coupon_capacity() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c coupons%ROWTYPE;
BEGIN
    IF NEW.released_at IS NOT NULL THEN RETURN NEW; END IF;
    IF TG_OP = 'UPDATE' THEN
        IF OLD.released_at IS NULL AND OLD.coupon_id = NEW.coupon_id AND OLD.customer_key = NEW.customer_key THEN RETURN NEW; END IF;
    END IF;
    SELECT * INTO c FROM coupons WHERE id = NEW.coupon_id FOR UPDATE;
    IF (c.total_limit IS NOT NULL AND (SELECT count(*) FROM coupon_redemptions WHERE coupon_id = c.id AND released_at IS NULL AND id <> NEW.id) >= c.total_limit)
       OR (c.customer_limit IS NOT NULL AND (SELECT count(*) FROM coupon_redemptions WHERE coupon_id = c.id AND customer_key = NEW.customer_key AND released_at IS NULL AND id <> NEW.id) >= c.customer_limit) THEN
        RAISE EXCEPTION 'Coupon allowance exhausted' USING ERRCODE = '23514', CONSTRAINT = 'coupon_capacity';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER coupon_capacity BEFORE INSERT OR UPDATE ON coupon_redemptions FOR EACH ROW EXECUTE FUNCTION cms_coupon_capacity();
SQL);
        foreach (AuditTables::coupons() as $table => [$entity, $fields]) {
            if (in_array($table, ['orders', 'order_items'], true)) {
                DB::statement("DROP TRIGGER cms_audit_mutation ON $table");
            }
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
    }

    private function itemConstraint(bool $discount): void
    {
        DB::statement('ALTER TABLE order_items DROP CONSTRAINT order_item_values');
        $extra = $discount ? 'AND discount_total >= 0 AND discount_total <= subtotal' : '';
        $net = $discount ? 'subtotal - discount_total' : 'subtotal';
        DB::statement("ALTER TABLE order_items ADD CONSTRAINT order_item_values CHECK (btrim(name) <> '' AND quantity > 0 AND deducted_quantity >= 0 AND deducted_quantity <= quantity AND position > 0 AND catalog_price >= 0 AND unit_price >= 0 AND subtotal >= 0 AND tax_total >= 0 AND tax_included >= 0 AND tax_included <= tax_total $extra AND total = $net + (tax_total - tax_included))");
    }

    public function down(): void
    {
        // Financial snapshots cannot be reconstructed by a destructive rollback.
        if (DB::table('coupon_redemptions')->exists()) {
            throw new RuntimeException('Restore a database backup to roll back coupons after redemption.');
        }
        Schema::dropIfExists('coupon_redemptions');
        DB::statement('DROP FUNCTION IF EXISTS cms_coupon_capacity()');
        foreach (['orders' => AuditTables::shipping()['orders'], 'order_items' => AuditTables::orders()['order_items']] as $table => [$entity, $fields]) {
            DB::statement("DROP TRIGGER cms_audit_mutation ON $table");
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
        $this->itemConstraint(false);
        Schema::table('order_items', fn (Blueprint $t) => $t->dropColumn('discount_total'));
        DB::statement('ALTER TABLE orders DROP CONSTRAINT order_coupon_values');
        Schema::table('orders', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('coupon_id');
            $t->dropColumn(['coupon_code', 'shipping_discount']);
        });
        Schema::dropIfExists('coupon_categories');
        Schema::dropIfExists('coupon_products');
        Schema::dropIfExists('coupons');
    }
};
