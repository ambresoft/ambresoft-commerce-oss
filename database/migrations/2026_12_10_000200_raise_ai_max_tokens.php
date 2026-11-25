<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // The 5.5 models think before answering and that counts toward the output limit.
        $this->constraint(16000);
    }

    public function down(): void
    {
        DB::table('ai_settings')->where('max_tokens', '>', 8192)->update(['max_tokens' => 8192]);
        $this->constraint(8192);
    }

    private function constraint(int $max): void
    {
        DB::statement('ALTER TABLE ai_settings DROP CONSTRAINT faq_ai_configuration');
        DB::statement("ALTER TABLE ai_settings ADD CONSTRAINT faq_ai_configuration CHECK (id = 1 AND max_tokens BETWEEN 256 AND $max AND hourly_limit BETWEEN 1 AND 1000 AND lock_version > 0 AND (NOT enabled OR api_key IS NOT NULL))");
    }
};
