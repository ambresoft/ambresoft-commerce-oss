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
        // What shoppers search for, counted per normalized text (no visitor, address or time of each search is kept).
        // `hidden` keeps a term out of the Popular searches block; picking it in a block still shows it there.
        Schema::create('search_terms', function (Blueprint $t): void {
            $t->id();
            $t->string('term', 100)->unique();
            $t->bigInteger('searches')->default(0);
            $t->integer('results')->default(0);
            $t->boolean('hidden')->default(false);
            $t->timestampTz('first_searched_at');
            $t->timestampTz('last_searched_at');
            $t->index(['hidden', 'searches']);
        });
        DB::statement("ALTER TABLE search_terms ADD CONSTRAINT search_terms_values CHECK (btrim(term) <> '' AND term = lower(term) AND searches >= 0 AND results >= 0)");
        // Staff hiding a term is audited; shoppers' searches (new terms and counts) are not.
        [$entity, $fields] = AuditTables::popularSearches()['search_terms'];
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER UPDATE OF hidden ON search_terms FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");

        // The Popular searches block can pick terms.
        Schema::table('storefront_block_selections', function (Blueprint $t): void {
            $t->foreignId('search_term_id')->nullable()->constrained('search_terms')->cascadeOnDelete();
            $t->index('search_term_id');
        });
        DB::statement('ALTER TABLE storefront_block_selections DROP CONSTRAINT storefront_selection');
        DB::statement('ALTER TABLE storefront_block_selections ADD CONSTRAINT storefront_selection CHECK (num_nonnulls(product_id, category_id, banner_id, banner_group_id, menu_id, newsletter_list_id, brand_id, search_term_id) = 1 AND position > 0)');
        $this->trigger(AuditTables::popularSearches()['storefront_block_selections']);
        // Structural cache records saved before the new selection column must be rebuilt.
        DB::table('storefront_layouts')->increment('lock_version');
    }

    public function down(): void
    {
        $this->trigger(AuditTables::brands()['storefront_block_selections']);
        DB::table('storefront_block_selections')->whereNotNull('search_term_id')->delete();
        DB::statement('ALTER TABLE storefront_block_selections DROP CONSTRAINT storefront_selection');
        Schema::table('storefront_block_selections', fn (Blueprint $t) => $t->dropConstrainedForeignId('search_term_id'));
        DB::statement('ALTER TABLE storefront_block_selections ADD CONSTRAINT storefront_selection CHECK (num_nonnulls(product_id, category_id, banner_id, banner_group_id, menu_id, newsletter_list_id, brand_id) = 1 AND position > 0)');
        DB::table('storefront_blocks')->where('type', 'popular_searches')->delete();
        Schema::dropIfExists('search_terms');
    }

    /** @param array{string, string} $audit */
    private function trigger(array $audit): void
    {
        [$entity, $fields] = $audit;
        DB::statement('DROP TRIGGER IF EXISTS cms_audit_mutation ON storefront_block_selections');
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON storefront_block_selections FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
    }
};
