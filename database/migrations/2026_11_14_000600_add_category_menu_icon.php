<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A small picture for the category in storefront menus, separate from the category's own image.
        Schema::table('categories', function (Blueprint $t): void {
            $t->foreignId('menu_icon_media_id')->nullable()->constrained('media_files')->restrictOnDelete();
            $t->index('menu_icon_media_id');
        });
        $this->audit('categories', 'category', fn (string $fields): string => $fields.',menu_icon_media_id');
    }

    public function down(): void
    {
        $this->audit('categories', 'category', fn (string $fields): string => implode(',', array_diff(explode(',', $fields), ['menu_icon_media_id'])));
        Schema::table('categories', function (Blueprint $t): void {
            $t->dropIndex(['menu_icon_media_id']);
            $t->dropConstrainedForeignId('menu_icon_media_id');
        });
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
