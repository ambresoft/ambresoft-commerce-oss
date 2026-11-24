<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Where a click on the category's banner image goes (a store path or a full address); none leaves it a picture.
        Schema::table('categories', fn (Blueprint $t) => $t->string('banner_link', 2048)->nullable());
        DB::statement("ALTER TABLE categories ADD CONSTRAINT categories_banner_link_check CHECK (banner_link IS NULL OR (banner_link <> '' AND system_key IS NULL))");
        $this->audit(fn (string $fields): string => $fields.',banner_link');
    }

    public function down(): void
    {
        $this->audit(fn (string $fields): string => implode(',', array_diff(explode(',', $fields), ['banner_link'])));
        DB::statement('ALTER TABLE categories DROP CONSTRAINT categories_banner_link_check');
        Schema::table('categories', fn (Blueprint $t) => $t->dropColumn('banner_link'));
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
