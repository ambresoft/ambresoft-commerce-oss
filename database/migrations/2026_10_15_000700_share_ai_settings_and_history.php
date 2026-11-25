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
        Schema::rename('faq_ai_settings', 'ai_settings');
        Schema::rename('faq_ai_calls', 'ai_calls');
        Schema::table('ai_calls', function (Blueprint $t): void {
            $t->string('feature', 64)->default('faq_generation');
            $t->index(['feature', 'created_at']);
        });
        DB::statement("ALTER TABLE ai_calls ADD CONSTRAINT ai_call_feature CHECK (feature ~ '^[a-z][a-z0-9_]{0,63}$')");
        DB::statement('DROP TRIGGER cms_audit_mutation ON ai_settings');
        [$entity, $fields] = AuditTables::aiSettings()['ai_settings'];
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON ai_settings FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        // Preserve any explicitly assigned log capability when moving the feature.
        if (! DB::table('permissions')->where('name', 'ai.logs.view')->exists()) {
            DB::table('permissions')->where('name', 'faqs.ai-log')->update(['name' => 'ai.logs.view']);
        }
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER cms_audit_mutation ON ai_settings');
        [$entity, $fields] = AuditTables::faqs()['faq_ai_settings'];
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON ai_settings FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        DB::statement('ALTER TABLE ai_calls DROP CONSTRAINT ai_call_feature');
        Schema::table('ai_calls', function (Blueprint $t): void {
            $t->dropIndex(['feature', 'created_at']);
            $t->dropColumn('feature');
        });
        Schema::rename('ai_settings', 'faq_ai_settings');
        Schema::rename('ai_calls', 'faq_ai_calls');
        if (! DB::table('permissions')->where('name', 'faqs.ai-log')->exists()) {
            DB::table('permissions')->where('name', 'ai.logs.view')->update(['name' => 'faqs.ai-log']);
        }
    }
};
