<?php

use App\Audit\Infrastructure\AuditTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // A store-wide allowance over the last 24 hours, next to the per-user hourly one.
        DB::statement('ALTER TABLE ai_settings ADD COLUMN daily_limit integer NOT NULL DEFAULT 200');
        $this->constraint('AND daily_limit BETWEEN 1 AND 100000 ');
        $this->trigger(AuditTables::aiSettingsDailyLimit()['ai_settings']);
    }

    public function down(): void
    {
        $this->trigger(AuditTables::aiSettings()['ai_settings']);
        $this->constraint('');
        DB::statement('ALTER TABLE ai_settings DROP COLUMN daily_limit');
    }

    private function constraint(string $daily): void
    {
        DB::statement('ALTER TABLE ai_settings DROP CONSTRAINT faq_ai_configuration');
        DB::statement("ALTER TABLE ai_settings ADD CONSTRAINT faq_ai_configuration CHECK (id = 1 AND max_tokens BETWEEN 256 AND 16000 AND hourly_limit BETWEEN 1 AND 1000 {$daily}AND lock_version > 0 AND (NOT enabled OR api_key IS NOT NULL))");
    }

    /** @param array{string, string} $capture */
    private function trigger(array $capture): void
    {
        [$entity, $fields] = $capture;
        DB::statement('DROP TRIGGER cms_audit_mutation ON ai_settings');
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON ai_settings FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
    }
};
