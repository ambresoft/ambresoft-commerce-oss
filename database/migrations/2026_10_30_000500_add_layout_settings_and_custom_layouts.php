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
        // Every layout carries its own page settings; merchants can add layouts and link each to one static page.
        Schema::table('storefront_layouts', function (Blueprint $t): void {
            $t->foreignId('page_id')->nullable()->unique()->constrained('pages')->nullOnDelete();
            $t->integer('position')->default(0);
            $t->text('custom_html')->default('');
            // Search engines may index the page unless the merchant says otherwise.
            $t->boolean('indexable')->default(true);
            $t->bigInteger('settings_version')->default(1);
        });
        DB::statement("ALTER TABLE storefront_layouts ADD CONSTRAINT storefront_layout_settings CHECK (length(custom_html) <= 20000 AND settings_version > 0 AND position BETWEEN -99999 AND 99999 AND (page_id IS NULL OR page_type LIKE 'custom.%'))");
        Schema::create('storefront_layout_translations', function (Blueprint $t): void {
            $t->foreignId('layout_id')->constrained('storefront_layouts')->cascadeOnDelete();
            $t->foreignId('locale_id')->constrained('locales')->restrictOnDelete();
            $t->string('name', 100)->default('');
            $t->string('page_title', 200)->default('');
            $t->string('meta_description', 500)->default('');
            $t->string('meta_keywords', 500)->default('');
            $t->primary(['layout_id', 'locale_id']);
            $t->index('locale_id');
        });
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON storefront_layout_translations FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('storefront_layout_translation', '".AuditTables::storefrontLayoutSettings()['storefront_layout_translations'][1]."')");
        $this->audit(fn (string $fields): string => $fields.',page_id,position,settings_version,indexable');
    }

    public function down(): void
    {
        $this->audit(fn (string $fields): string => implode(',', array_diff(explode(',', $fields), ['page_id', 'position', 'settings_version', 'indexable'])));
        Schema::dropIfExists('storefront_layout_translations');
        // Custom layouts only exist with this migration.
        DB::table('storefront_layouts')->where('page_type', 'like', 'custom.%')->delete();
        DB::statement('ALTER TABLE storefront_layouts DROP CONSTRAINT storefront_layout_settings');
        Schema::table('storefront_layouts', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('page_id');
            $t->dropColumn(['position', 'custom_html', 'settings_version', 'indexable']);
        });
    }

    /** Extends (or shrinks) the field list that earlier additive migrations left on the audit trigger. */
    private function audit(callable $fields): void
    {
        $definition = DB::selectOne("SELECT pg_get_triggerdef(oid) AS def FROM pg_trigger WHERE tgname = 'cms_audit_mutation' AND tgrelid = 'storefront_layouts'::regclass")->def;
        if (preg_match("/cms_capture_audit\('([^']+)', '([^']+)'\)/", $definition, $m)) {
            DB::statement('DROP TRIGGER cms_audit_mutation ON storefront_layouts');
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON storefront_layouts FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('{$m[1]}', '{$fields($m[2])}')");
        }
    }
};
