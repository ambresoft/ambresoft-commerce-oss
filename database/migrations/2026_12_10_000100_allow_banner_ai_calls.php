<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // The banner, blog and newsletter assistants log their calls with what they write as the source.
        $this->constraint("'general','category','product','page','banner','blog_post','newsletter'");
    }

    public function down(): void
    {
        DB::table('ai_calls')->whereIn('object_type', ['banner', 'blog_post', 'newsletter'])->delete();
        $this->constraint("'general','category','product','page'");
    }

    private function constraint(string $types): void
    {
        DB::statement('ALTER TABLE ai_calls DROP CONSTRAINT faq_ai_call_valid');
        DB::statement("ALTER TABLE ai_calls ADD CONSTRAINT faq_ai_call_valid CHECK (object_type IN ($types) AND status IN ('pending','success','error') AND (input_tokens IS NULL OR input_tokens >= 0) AND (output_tokens IS NULL OR output_tokens >= 0) AND (duration_ms IS NULL OR duration_ms >= 0))");
    }
};
