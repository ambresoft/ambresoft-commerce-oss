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
        // Products a payment method cannot pay for: a whole product type (cash on delivery for digital products, for
        // example) and categories, each including all of its subcategories. Checkout offers a method only when no
        // product in the cart is excluded.
        Schema::table('payment_methods', function (Blueprint $t): void {
            $t->boolean('exclude_physical')->default(false);
            $t->boolean('exclude_digital')->default(false);
        });
        // Excluding both types would leave the method unusable for anything.
        DB::statement('ALTER TABLE payment_methods ADD CONSTRAINT payment_method_product_types CHECK (NOT (exclude_physical AND exclude_digital))');
        Schema::create('payment_method_excluded_categories', function (Blueprint $t): void {
            $t->foreignId('method_id')->constrained('payment_methods')->cascadeOnDelete();
            $t->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $t->primary(['method_id', 'category_id']);
            $t->index('category_id');
        });
        $this->trigger(AuditTables::paymentRestrictions());
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS cms_audit_mutation ON payment_method_excluded_categories');
        Schema::dropIfExists('payment_method_excluded_categories');
        $this->trigger(['payment_methods' => AuditTables::methodImages()['payment_methods']]);
        DB::statement('ALTER TABLE payment_methods DROP CONSTRAINT payment_method_product_types');
        Schema::table('payment_methods', fn (Blueprint $t) => $t->dropColumn(['exclude_physical', 'exclude_digital']));
    }

    /** @param array<string, array{string, string}> $tables */
    private function trigger(array $tables): void
    {
        foreach ($tables as $table => [$entity, $fields]) {
            DB::statement("DROP TRIGGER IF EXISTS cms_audit_mutation ON $table");
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
    }
};
