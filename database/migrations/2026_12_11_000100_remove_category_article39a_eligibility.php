<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Article 39a eligibility leaves core; a future add-on owns it.
        $this->audit(fn (string $fields): string => implode(',', array_diff(explode(',', $fields), ['article_39a_eligible'])));
        DB::statement('ALTER TABLE categories DROP CONSTRAINT IF EXISTS categories_system_article39a_check');
        Schema::table('categories', fn (Blueprint $t) => $t->dropColumn('article_39a_eligible'));
    }

    public function down(): void
    {
        Schema::table('categories', fn (Blueprint $t) => $t->boolean('article_39a_eligible')->default(false));
        DB::statement('ALTER TABLE categories ADD CONSTRAINT categories_system_article39a_check CHECK (system_key IS NULL OR NOT article_39a_eligible)');
        $this->audit(fn (string $fields): string => $fields.',article_39a_eligible');
    }

    private function audit(callable $fields): void
    {
        $definition = DB::selectOne("SELECT pg_get_triggerdef(oid) AS def FROM pg_trigger WHERE tgname = 'cms_audit_mutation' AND tgrelid = 'categories'::regclass")->def;
        if (preg_match("/cms_capture_audit\('([^']+)', '([^']+)'\)/", $definition, $m)) {
            DB::statement('DROP TRIGGER cms_audit_mutation ON categories');
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON categories FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('{$m[1]}', '{$fields($m[2])}')");
        }
    }
};
