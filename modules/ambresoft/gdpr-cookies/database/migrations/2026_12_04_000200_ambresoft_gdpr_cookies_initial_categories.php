<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ambresoft_gdpr_settings', function (Blueprint $table): void {
            $table->boolean('initial_preferences')->default(false);
            $table->boolean('initial_statistics')->default(false);
            $table->boolean('initial_marketing')->default(false);
        });
        DB::statement('DROP TRIGGER cms_audit_mutation ON ambresoft_gdpr_settings');
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON ambresoft_gdpr_settings FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('gdpr_cookie_settings', 'version,consent_months,privacy_url,reopen_button,initial_preferences,initial_statistics,initial_marketing')");
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER cms_audit_mutation ON ambresoft_gdpr_settings');
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON ambresoft_gdpr_settings FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('gdpr_cookie_settings', 'version,consent_months,privacy_url,reopen_button')");
        Schema::table('ambresoft_gdpr_settings', function (Blueprint $table): void {
            $table->dropColumn(['initial_preferences', 'initial_statistics', 'initial_marketing']);
        });
    }
};
