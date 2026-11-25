<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table): void {
            $table->foreignId('logo_email_media_id')->nullable()->constrained('media_files')->restrictOnDelete();
            $table->index('logo_email_media_id');
        });
        $this->audit(true);
    }

    public function down(): void
    {
        $this->audit(false);
        Schema::table('company_settings', function (Blueprint $table): void {
            $table->dropIndex(['logo_email_media_id']);
            $table->dropConstrainedForeignId('logo_email_media_id');
        });
    }

    private function audit(bool $include): void
    {
        $definition = DB::selectOne("SELECT pg_get_triggerdef(oid) AS def FROM pg_trigger WHERE tgname = 'cms_audit_mutation' AND tgrelid = 'company_settings'::regclass")->def;
        if (preg_match("/cms_capture_audit\('([^']+)', '([^']+)'\)/", $definition, $matches)) {
            $fields = array_diff(explode(',', $matches[2]), ['logo_email_media_id']);
            if ($include) {
                $fields[] = 'logo_email_media_id';
            }
            $fields = implode(',', $fields);
            DB::statement('DROP TRIGGER cms_audit_mutation ON company_settings');
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON company_settings FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('{$matches[1]}', '$fields')");
        }
    }
};
