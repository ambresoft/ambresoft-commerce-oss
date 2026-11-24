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
        // An optional fee a payment method adds to the order (e.g. cash on delivery), in the primary currency.
        Schema::table('payment_methods', fn (Blueprint $t) => $t->decimal('fee', 20, 6)->nullable());
        DB::statement('ALTER TABLE payment_methods ADD CONSTRAINT payment_method_fee CHECK (fee IS NULL OR fee >= 0)');
        // The fee charged on the order, kept as charged: later changes to the method's fee never re-price an order.
        Schema::table('orders', fn (Blueprint $t) => $t->decimal('payment_fee', 20, 6)->default(0));
        DB::statement('ALTER TABLE orders ADD CONSTRAINT order_payment_fee CHECK (payment_fee >= 0)');
        // The order's total now includes the fee; the database keeps guarding the whole sum.
        $this->totalRule('shipping_total + payment_fee');
        foreach (AuditTables::paymentFees() as $table => [$entity, $fields]) {
            $this->trigger($table, $entity, $fields);
        }
    }

    public function down(): void
    {
        $this->trigger('orders', 'order', AuditTables::coupons()['orders'][1]);
        $this->trigger('payment_methods', 'payment_method', AuditTables::paymentRestrictions()['payment_methods'][1]);
        $this->totalRule('shipping_total');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT order_payment_fee');
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('payment_fee'));
        DB::statement('ALTER TABLE payment_methods DROP CONSTRAINT payment_method_fee');
        Schema::table('payment_methods', fn (Blueprint $t) => $t->dropColumn('fee'));
    }

    /** The orders' value check with the given charges added to the total (its other conditions unchanged). */
    private function totalRule(string $charges): void
    {
        DB::statement('ALTER TABLE orders DROP CONSTRAINT order_values');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT order_values CHECK (btrim(first_name) <> '' AND btrim(last_name) <> '' AND email = lower(btrim(email)) AND email <> ''
            AND currency_code ~ '^[A-Z]{3}$' AND currency_decimals <= 6 AND lock_version > 0 AND subtotal >= 0 AND discount_total >= 0 AND tax_total >= 0
            AND tax_included >= 0 AND tax_included <= tax_total AND shipping_total >= 0 AND total >= 0
            AND total = subtotal - discount_total + (tax_total - tax_included) + $charges AND tax_method IN ('unit_price', 'subtotal'))");
    }

    private function trigger(string $table, string $entity, string $fields): void
    {
        DB::statement("DROP TRIGGER IF EXISTS cms_audit_mutation ON $table");
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
    }
};
