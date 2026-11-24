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
        // The checkout's main content block picks pages (terms of use…) the buyer accepts with a checkbox.
        Schema::table('storefront_block_selections', function (Blueprint $t): void {
            $t->foreignId('page_id')->nullable()->constrained('pages')->cascadeOnDelete();
            $t->index('page_id');
        });
        $this->selectionRule('brand_id, search_term_id, page_id');
        // Whether the buyer asked for an invoice at checkout.
        Schema::table('orders', fn (Blueprint $t) => $t->boolean('invoice_requested')->default(false));
        foreach (AuditTables::checkoutConsents() as $table => [$entity, $fields]) {
            $this->trigger($table, $entity, $fields);
        }
        // Structural cache records saved before the new selection column must be rebuilt.
        DB::table('storefront_layouts')->increment('lock_version');
    }

    public function down(): void
    {
        $this->trigger('orders', 'order', AuditTables::paymentFees()['orders'][1]);
        $this->trigger('storefront_block_selections', 'storefront_selection', AuditTables::popularSearches()['storefront_block_selections'][1]);
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('invoice_requested'));
        DB::table('storefront_block_selections')->whereNotNull('page_id')->delete();
        DB::statement('ALTER TABLE storefront_block_selections DROP CONSTRAINT storefront_selection');
        Schema::table('storefront_block_selections', fn (Blueprint $t) => $t->dropConstrainedForeignId('page_id'));
        DB::statement('ALTER TABLE storefront_block_selections ADD CONSTRAINT storefront_selection CHECK (num_nonnulls(product_id, category_id, banner_id, banner_group_id, menu_id, newsletter_list_id, brand_id, search_term_id) = 1 AND position > 0)');
    }

    private function selectionRule(string $added): void
    {
        DB::statement('ALTER TABLE storefront_block_selections DROP CONSTRAINT storefront_selection');
        DB::statement("ALTER TABLE storefront_block_selections ADD CONSTRAINT storefront_selection CHECK (num_nonnulls(product_id, category_id, banner_id, banner_group_id, menu_id, newsletter_list_id, $added) = 1 AND position > 0)");
    }

    private function trigger(string $table, string $entity, string $fields): void
    {
        DB::statement("DROP TRIGGER IF EXISTS cms_audit_mutation ON $table");
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
    }
};
