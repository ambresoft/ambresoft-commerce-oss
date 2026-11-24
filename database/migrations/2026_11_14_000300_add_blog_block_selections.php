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
        // The blog posts block picks posts by hand, or one blog whose newest posts it shows.
        Schema::table('storefront_block_selections', function (Blueprint $t): void {
            $t->foreignId('blog_id')->nullable()->constrained('blogs')->cascadeOnDelete();
            $t->foreignId('blog_post_id')->nullable()->constrained('blog_posts')->cascadeOnDelete();
            $t->index('blog_id');
            $t->index('blog_post_id');
        });
        $this->selectionRule('brand_id, search_term_id, page_id, blog_id, blog_post_id');
        $this->trigger(AuditTables::blogBlockSelections()['storefront_block_selections'][1]);
        // Structural cache records saved before the new selection columns must be rebuilt.
        DB::table('storefront_layouts')->increment('lock_version');
    }

    public function down(): void
    {
        $this->trigger(AuditTables::checkoutConsents()['storefront_block_selections'][1]);
        DB::table('storefront_block_selections')->whereNotNull('blog_id')->orWhereNotNull('blog_post_id')->delete();
        DB::table('storefront_blocks')->where('type', 'blog_posts')->delete();
        $this->selectionRule('brand_id, search_term_id, page_id');
        Schema::table('storefront_block_selections', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('blog_post_id');
            $t->dropConstrainedForeignId('blog_id');
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
