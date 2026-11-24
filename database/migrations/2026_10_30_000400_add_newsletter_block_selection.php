<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A newsletter block says which newsletter list its visitors join.
        Schema::table('storefront_block_selections', function (Blueprint $t): void {
            $t->foreignId('newsletter_list_id')->nullable()->constrained('newsletter_lists')->cascadeOnDelete();
            $t->index('newsletter_list_id');
        });
        DB::statement('ALTER TABLE storefront_block_selections DROP CONSTRAINT storefront_selection');
        DB::statement('ALTER TABLE storefront_block_selections ADD CONSTRAINT storefront_selection CHECK (num_nonnulls(product_id, category_id, banner_id, banner_group_id, menu_id, newsletter_list_id) = 1 AND position > 0)');
        $this->audit(fn (string $fields): string => $fields.',newsletter_list_id');
    }

    public function down(): void
    {
        $this->audit(fn (string $fields): string => implode(',', array_diff(explode(',', $fields), ['newsletter_list_id'])));
        DB::table('storefront_block_selections')->whereNotNull('newsletter_list_id')->delete();
        DB::statement('ALTER TABLE storefront_block_selections DROP CONSTRAINT storefront_selection');
        Schema::table('storefront_block_selections', fn (Blueprint $t) => $t->dropConstrainedForeignId('newsletter_list_id'));
        DB::statement('ALTER TABLE storefront_block_selections ADD CONSTRAINT storefront_selection CHECK (num_nonnulls(product_id, category_id, banner_id, banner_group_id, menu_id) = 1 AND position > 0)');
    }

    /** Extends (or shrinks) the field list that earlier additive migrations left on the audit trigger. */
    private function audit(callable $fields): void
    {
        $definition = DB::selectOne("SELECT pg_get_triggerdef(oid) AS def FROM pg_trigger WHERE tgname = 'cms_audit_mutation' AND tgrelid = 'storefront_block_selections'::regclass")->def;
        if (preg_match("/cms_capture_audit\('([^']+)', '([^']+)'\)/", $definition, $m)) {
            DB::statement('DROP TRIGGER cms_audit_mutation ON storefront_block_selections');
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON storefront_block_selections FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('{$m[1]}', '{$fields($m[2])}')");
        }
    }
};
