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
        // The store logo for the storefront; the mobile logo is optional and falls back to the main one.
        Schema::table('company_settings', function (Blueprint $t): void {
            $t->foreignId('logo_media_id')->nullable()->constrained('media_files')->restrictOnDelete();
            $t->foreignId('logo_mobile_media_id')->nullable()->constrained('media_files')->restrictOnDelete();
            $t->index('logo_media_id');
            $t->index('logo_mobile_media_id');
        });
        // Mobile logo without a main logo makes no sense: the fallback chain is mobile -> main.
        DB::statement('ALTER TABLE company_settings ADD CONSTRAINT company_settings_logos CHECK (logo_mobile_media_id IS NULL OR logo_media_id IS NOT NULL)');
        $this->trigger(AuditTables::company());
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE company_settings DROP CONSTRAINT IF EXISTS company_settings_logos');
        $without = AuditTables::company();
        foreach ($without as $table => [$entity, $fields]) {
            $without[$table] = [$entity, implode(',', array_diff(explode(',', $fields), ['logo_media_id', 'logo_mobile_media_id']))];
        }
        $this->trigger($without);
        Schema::table('company_settings', function (Blueprint $t): void {
            $t->dropIndex(['logo_media_id']);
            $t->dropIndex(['logo_mobile_media_id']);
            $t->dropConstrainedForeignId('logo_media_id');
            $t->dropConstrainedForeignId('logo_mobile_media_id');
        });
    }

    /** @param array<string, array{string, string}> $tables */
    private function trigger(array $tables): void
    {
        foreach ($tables as $table => [$entity, $fields]) {
            DB::statement("DROP TRIGGER IF EXISTS cms_audit_mutation ON $table");
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
    }
};
