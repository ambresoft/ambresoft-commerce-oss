<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The Open Graph object type of the product page, from the same list as pages (PageRules::FACEBOOK_TYPES).
        Schema::table('products', fn (Blueprint $t) => $t->string('facebook_type', 20)->default('product'));
        DB::statement("ALTER TABLE products ADD CONSTRAINT product_facebook_type CHECK (facebook_type ~ '^[a-z_]{2,20}$')");
        $this->audit('products', 'product', fn (string $fields): string => $fields.',facebook_type');
    }

    public function down(): void
    {
        $this->audit('products', 'product', fn (string $fields): string => implode(',', array_diff(explode(',', $fields), ['facebook_type'])));
        DB::statement('ALTER TABLE products DROP CONSTRAINT product_facebook_type');
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('facebook_type'));
    }

    /** Extends (or shrinks) the field list that earlier additive migrations left on a table's audit trigger. */
    private function audit(string $table, string $entity, callable $fields): void
    {
        $definition = DB::selectOne("SELECT pg_get_triggerdef(oid) AS def FROM pg_trigger WHERE tgname = 'cms_audit_mutation' AND tgrelid = '$table'::regclass")->def;
        if (preg_match("/cms_capture_audit\('([^']+)', '([^']+)'\)/", $definition, $m)) {
            DB::statement("DROP TRIGGER cms_audit_mutation ON $table");
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('{$m[1]}', '{$fields($m[2])}')");
        }
    }
};
