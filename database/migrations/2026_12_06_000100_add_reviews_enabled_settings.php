<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Storefront reviews and star ratings: one store-wide switch, and a per-product opt-out. Saved reviews are kept either way.
        Schema::table('general_settings', fn (Blueprint $t) => $t->boolean('reviews_enabled')->default(true));
        Schema::table('products', fn (Blueprint $t) => $t->boolean('reviews_enabled')->default(true));
        $this->audit('general_settings', fn (string $f): string => $f.',reviews_enabled');
        $this->audit('products', fn (string $f): string => $f.',reviews_enabled');
        // The search index drops the rating of a product whose reviews are off, so the switch must rebuild its document.
        $this->searchTrigger(', NEW.reviews_enabled', ', OLD.reviews_enabled');
    }

    public function down(): void
    {
        $this->searchTrigger('', '');
        $this->audit('products', fn (string $f): string => implode(',', array_diff(explode(',', $f), ['reviews_enabled'])));
        $this->audit('general_settings', fn (string $f): string => implode(',', array_diff(explode(',', $f), ['reviews_enabled'])));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('reviews_enabled'));
        Schema::table('general_settings', fn (Blueprint $t) => $t->dropColumn('reviews_enabled'));
    }

    private function searchTrigger(string $new, string $old): void
    {
        DB::unprepared(<<<SQL
CREATE OR REPLACE FUNCTION cms_search_products() RETURNS trigger LANGUAGE plpgsql AS \$\$
BEGIN
    IF TG_OP = 'DELETE' THEN
        PERFORM cms_search_enqueue(ARRAY[OLD.id], true);
    ELSIF TG_OP = 'INSERT' THEN
        PERFORM cms_search_enqueue(ARRAY[NEW.id], false);
    ELSIF (NEW.status, NEW.deleted_at, NEW.category_id, NEW.sku IS NULL, NEW.price IS NULL, NEW.quantity > 0)
        IS DISTINCT FROM (OLD.status, OLD.deleted_at, OLD.category_id, OLD.sku IS NULL, OLD.price IS NULL, OLD.quantity > 0) THEN
        PERFORM cms_search_enqueue(ARRAY[NEW.id], true);
    ELSIF (NEW.sku, NEW.price, NEW.ean, NEW.mpn, NEW.isbn, NEW.upc, NEW.brand_id, NEW.in_stock_status_id, NEW.out_of_stock_status_id, NEW.created_at, NEW.type{$new})
        IS DISTINCT FROM (OLD.sku, OLD.price, OLD.ean, OLD.mpn, OLD.isbn, OLD.upc, OLD.brand_id, OLD.in_stock_status_id, OLD.out_of_stock_status_id, OLD.created_at, OLD.type{$old}) THEN
        PERFORM cms_search_enqueue(ARRAY[NEW.id], false);
    END IF;
    RETURN NULL;
END \$\$;
SQL);
    }

    /** Extends (or shrinks) the field list that earlier migrations left on a table's audit trigger. */
    private function audit(string $table, callable $fields): void
    {
        $definition = DB::selectOne("SELECT pg_get_triggerdef(oid) AS def FROM pg_trigger WHERE tgname = 'cms_audit_mutation' AND tgrelid = '$table'::regclass")?->def;
        if ($definition !== null && preg_match("/cms_capture_audit\('([^']+)', '([^']+)'\)/", $definition, $m)) {
            DB::statement("DROP TRIGGER cms_audit_mutation ON $table");
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('{$m[1]}', '{$fields($m[2])}')");
        }
    }
};
