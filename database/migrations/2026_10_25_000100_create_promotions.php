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
        Schema::create('promotions', function (Blueprint $t): void {
            $t->id();
            $t->string('status', 10)->default('active');
            $t->string('mode', 10)->default('automatic');
            // One optional eligibility wrapper per existing coupon. No coupon data is moved.
            $t->foreignId('coupon_id')->nullable()->unique()->constrained('coupons')->restrictOnDelete();
            $t->timestampTz('starts_at')->nullable();
            $t->timestampTz('ends_at')->nullable();
            $t->integer('priority')->default(100);
            $t->boolean('stop_other')->default(false);
            $t->string('match', 3)->default('all');
            $t->string('benefit', 20);
            $t->string('scope', 10)->default('order');
            $t->decimal('amount', 20, 6)->nullable();
            $t->boolean('all_products')->default(true);
            $t->boolean('exclude_sale')->default(false);
            $t->boolean('all_shipping')->default(true);
            $t->bigInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->index(['status', 'priority', 'id']);
        });
        DB::statement("ALTER TABLE promotions ADD CONSTRAINT promotion_values CHECK (status IN ('active','disabled') AND mode IN ('automatic','coupon') AND ((mode = 'coupon' AND coupon_id IS NOT NULL AND benefit = 'coupon' AND amount IS NULL) OR (mode = 'automatic' AND coupon_id IS NULL AND benefit IN ('percentage','fixed','free_shipping'))) AND (starts_at IS NULL OR ends_at IS NULL OR ends_at > starts_at) AND priority BETWEEN 0 AND 99999 AND match IN ('all','any') AND scope IN ('order','products') AND ((benefit IN ('coupon','free_shipping') AND amount IS NULL) OR (amount IS NOT NULL AND amount > 0 AND (benefit <> 'percentage' OR amount <= 100))) AND lock_version > 0)");
        Schema::create('promotion_translations', function (Blueprint $t): void {
            $t->foreignId('promotion_id')->constrained('promotions')->cascadeOnDelete();
            $t->foreignId('locale_id')->constrained('locales')->restrictOnDelete();
            $t->string('name', 128);
            $t->string('description', 2000)->default('');
            $t->primary(['promotion_id', 'locale_id']);
            $t->index('locale_id');
        });
        DB::statement("ALTER TABLE promotion_translations ADD CONSTRAINT promotion_name CHECK (btrim(name) <> '')");
        Schema::create('promotion_conditions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('promotion_id')->constrained('promotions')->cascadeOnDelete();
            $t->string('type', 20);
            $t->decimal('minimum', 20, 6)->nullable();
            $t->decimal('maximum', 20, 6)->nullable();
            $t->integer('quantity')->nullable();
            $t->string('quantity_scope', 10)->default('cart');
            $t->string('match', 3)->default('any');
            $t->unique(['promotion_id', 'type']);
        });
        DB::statement("ALTER TABLE promotion_conditions ADD CONSTRAINT promotion_condition_values CHECK (type IN ('subtotal','products','categories','quantity','groups','shipping') AND (minimum IS NULL OR minimum >= 0) AND (maximum IS NULL OR maximum >= 0) AND (minimum IS NULL OR maximum IS NULL OR maximum >= minimum) AND (type <> 'subtotal' OR minimum IS NOT NULL OR maximum IS NOT NULL) AND (type <> 'quantity' OR (quantity IS NOT NULL AND quantity > 0)) AND quantity_scope IN ('cart','products','categories') AND match IN ('all','any'))");
        foreach (['products' => 'product', 'categories' => 'category', 'customer_groups' => 'group', 'shipping_methods' => 'shipping_method'] as $table => $singular) {
            Schema::create('promotion_condition_'.$table, function (Blueprint $t) use ($table, $singular): void {
                $t->id();
                $t->foreignId('condition_id')->constrained('promotion_conditions')->cascadeOnDelete();
                // Retain a tombstone: deleting the last target must never broaden a rule.
                $t->foreignId($singular.'_id')->nullable()->constrained($table)->nullOnDelete();
                $t->unique(['condition_id', $singular.'_id']);
                $t->index($singular.'_id');
            });
        }
        foreach (['products' => 'product', 'categories' => 'category', 'shipping_methods' => 'shipping_method'] as $table => $singular) {
            Schema::create('promotion_'.$table, function (Blueprint $t) use ($table, $singular): void {
                $t->id();
                $t->foreignId('promotion_id')->constrained('promotions')->cascadeOnDelete();
                $t->foreignId($singular.'_id')->nullable()->constrained($table)->nullOnDelete();
                $t->boolean('excluded')->default(false);
                $t->unique(['promotion_id', $singular.'_id', 'excluded']);
                $t->index($singular.'_id');
            });
        }
        Schema::table('orders', function (Blueprint $t): void {
            $t->decimal('coupon_discount', 20, 6)->default(0);
            $t->decimal('coupon_shipping_discount', 20, 6)->default(0);
            $t->char('submission_key', 64)->nullable()->unique();
            $t->char('submission_fingerprint', 64)->nullable();
        });
        DB::statement('UPDATE orders SET coupon_discount = discount_total, coupon_shipping_discount = shipping_discount WHERE coupon_id IS NOT NULL');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT order_promotion_coupon_values CHECK (coupon_discount >= 0 AND coupon_discount <= discount_total AND coupon_shipping_discount >= 0 AND coupon_shipping_discount <= shipping_discount)');
        Schema::table('order_items', fn (Blueprint $t) => $t->jsonb('pricing_basis')->nullable());
        Schema::create('order_promotions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $t->foreignId('promotion_id')->constrained('promotions')->restrictOnDelete();
            $t->string('name', 128);
            $t->string('coupon_code', 20)->nullable();
            $t->string('benefit', 20);
            $t->decimal('discount', 20, 6);
            $t->decimal('shipping_discount', 20, 6);
            $t->jsonb('allocations');
            $t->char('currency_code', 3);
            $t->smallInteger('currency_decimals');
            $t->timestampTz('applied_at');
            $t->boolean('is_current')->default(true);
            $t->timestampTz('released_at')->nullable();
            $t->unique(['order_id', 'promotion_id']);
            $t->index(['promotion_id', 'released_at']);
        });
        DB::statement("ALTER TABLE order_promotions ADD CONSTRAINT order_promotion_values CHECK (discount >= 0 AND shipping_discount >= 0 AND currency_decimals BETWEEN 0 AND 6 AND benefit IN ('percentage','fixed','free_shipping','coupon'))");
        foreach (AuditTables::promotions() as $table => [$entity, $fields]) {
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
    }

    public function down(): void
    {
        if (DB::table('order_promotions')->exists()) {
            throw new RuntimeException('Restore a database backup to roll back promotions after use.');
        }
        Schema::dropIfExists('order_promotions');
        Schema::table('order_items', fn (Blueprint $t) => $t->dropColumn('pricing_basis'));
        DB::statement('ALTER TABLE orders DROP CONSTRAINT order_promotion_coupon_values');
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['coupon_discount', 'coupon_shipping_discount', 'submission_key', 'submission_fingerprint']));
        foreach (['products', 'categories', 'shipping_methods'] as $table) {
            Schema::dropIfExists('promotion_'.$table);
        }
        foreach (['products', 'categories', 'customer_groups', 'shipping_methods'] as $table) {
            Schema::dropIfExists('promotion_condition_'.$table);
        }
        Schema::dropIfExists('promotion_conditions');
        Schema::dropIfExists('promotion_translations');
        Schema::dropIfExists('promotions');
    }
};
