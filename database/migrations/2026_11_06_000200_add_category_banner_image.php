<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A wide picture shown under the category's title on its storefront page, independent of the category and block images.
        Schema::table('categories', function (Blueprint $t): void {
            $t->foreignId('banner_image_media_id')->nullable()->unique()->constrained('media_files')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE categories ADD CONSTRAINT categories_system_banner_image_check CHECK (system_key IS NULL OR banner_image_media_id IS NULL)');
        $this->audit(fn (string $fields): string => $fields.',banner_image_media_id');
    }

    public function down(): void
    {
        $this->audit(fn (string $fields): string => implode(',', array_diff(explode(',', $fields), ['banner_image_media_id'])));
        DB::statement('ALTER TABLE categories DROP CONSTRAINT categories_system_banner_image_check');
        Schema::table('categories', fn (Blueprint $t) => $t->dropConstrainedForeignId('banner_image_media_id'));
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
