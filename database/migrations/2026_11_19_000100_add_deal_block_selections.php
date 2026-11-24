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
        // Each manually picked deal has a real, indexed foreign key.
        Schema::table('storefront_block_selections', function (Blueprint $t): void {
            $t->foreignId('coupon_id')->nullable()->constrained('coupons')->cascadeOnDelete();
            $t->foreignId('promotion_id')->nullable()->constrained('promotions')->cascadeOnDelete();
            $t->index('coupon_id');
            $t->index('promotion_id');
        });
        $this->selectionRule('brand_id, search_term_id, page_id, blog_id, blog_post_id, coupon_id, promotion_id');
        $this->trigger(AuditTables::dealBlockSelections()['storefront_block_selections'][1]);
        // Structural cache records saved before the new selection columns must be rebuilt.
        DB::table('storefront_layouts')->increment('lock_version');
    }

    public function down(): void
    {
        $this->trigger(AuditTables::blogBlockSelections()['storefront_block_selections'][1]);
        DB::table('storefront_block_selections')->whereNotNull('coupon_id')->orWhereNotNull('promotion_id')->delete();
        DB::table('storefront_blocks')->whereIn('type', ['coupons', 'offers'])->delete();
        $this->selectionRule('brand_id, search_term_id, page_id, blog_id, blog_post_id');
        Schema::table('storefront_block_selections', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('promotion_id');
            $t->dropConstrainedForeignId('coupon_id');
        });
    }

    private function selectionRule(string $added): void
    {
        DB::statement('ALTER TABLE storefront_block_selections DROP CONSTRAINT storefront_selection');
        DB::statement("ALTER TABLE storefront_block_selections ADD CONSTRAINT storefront_selection CHECK (num_nonnulls(product_id, category_id, banner_id, banner_group_id, menu_id, newsletter_list_id, $added) = 1 AND position > 0)");
    }

    private function trigger(string $fields): void
    {
        DB::statement('DROP TRIGGER IF EXISTS cms_audit_mutation ON storefront_block_selections');
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON storefront_block_selections FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('storefront_selection', '$fields')");
    }
};
