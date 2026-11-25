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
        Schema::create('faqs', function (Blueprint $t): void {
            $t->id();
            $t->string('type', 12)->default('general');
            $t->string('status', 12)->default('active');
            $t->integer('position')->default(0);
            $t->unsignedBigInteger('lock_version')->default(1);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->unique(['id', 'type']);
            $t->index(['type', 'status', 'position', 'id']);
            $t->index('created_by');
            $t->index('updated_by');
        });
        DB::statement("ALTER TABLE faqs ADD CONSTRAINT faq_configuration CHECK (type IN ('general','category','product') AND status IN ('active','disabled') AND position >= 0 AND lock_version > 0)");
        Schema::create('faq_translations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('faq_id')->constrained('faqs')->cascadeOnDelete();
            $t->foreignId('locale_id')->constrained('locales')->restrictOnDelete();
            $t->string('question', 500);
            $t->text('answer');
            $t->unique(['faq_id', 'locale_id']);
            $t->index('locale_id');
        });
        DB::statement("ALTER TABLE faq_translations ADD CONSTRAINT faq_content CHECK (btrim(question) <> '' AND btrim(answer) <> '' AND char_length(answer) <= 20000)");
        foreach (['category' => 'categories', 'product' => 'products', 'page' => 'pages'] as $owner => $target) {
            Schema::create($owner.'_faqs', function (Blueprint $t) use ($owner, $target): void {
                $t->foreignId($owner.'_id')->constrained($target)->cascadeOnDelete();
                $t->unsignedBigInteger('faq_id');
                $t->string('faq_type', 12);
                $t->integer('position');
                $t->primary([$owner.'_id', 'faq_id']);
                $t->foreign(['faq_id', 'faq_type'])->references(['id', 'type'])->on('faqs')->restrictOnDelete()->restrictOnUpdate();
                $t->index(['faq_id', 'faq_type']);
            });
            $allowed = $owner === 'page' ? "'general'" : "'general','$owner'";
            DB::statement("ALTER TABLE {$owner}_faqs ADD CONSTRAINT {$owner}_faq_type CHECK (faq_type IN ($allowed) AND position > 0)");
        }
        Schema::create('faq_ai_settings', function (Blueprint $t): void {
            $t->unsignedSmallInteger('id')->primary();
            $t->boolean('enabled')->default(false);
            $t->text('api_key')->nullable();
            $t->string('model', 100)->default('claude-sonnet-4-6');
            $t->integer('max_tokens')->default(3000);
            $t->integer('hourly_limit')->default(50);
            $t->unsignedBigInteger('lock_version')->default(1);
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('updated_at')->nullable();
            $t->index('updated_by');
        });
        DB::statement('ALTER TABLE faq_ai_settings ADD CONSTRAINT faq_ai_configuration CHECK (id = 1 AND max_tokens BETWEEN 256 AND 8192 AND hourly_limit BETWEEN 1 AND 1000 AND lock_version > 0 AND (NOT enabled OR api_key IS NOT NULL))');
        DB::table('faq_ai_settings')->insert(['id' => 1]);
        Schema::create('faq_ai_calls', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('object_type', 12);
            $t->unsignedBigInteger('object_id')->nullable();
            $t->string('model', 100);
            $t->string('status', 20)->default('pending');
            $t->integer('input_tokens')->nullable();
            $t->integer('output_tokens')->nullable();
            $t->integer('duration_ms')->nullable();
            $t->smallInteger('http_status')->nullable();
            $t->string('error_code', 40)->nullable();
            $t->string('provider_request_id', 100)->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['created_at', 'id']);
            $t->index(['user_id', 'created_at']);
        });
        DB::statement("ALTER TABLE faq_ai_calls ADD CONSTRAINT faq_ai_call_valid CHECK (object_type IN ('general','category','product','page') AND status IN ('pending','success','error') AND (input_tokens IS NULL OR input_tokens >= 0) AND (output_tokens IS NULL OR output_tokens >= 0) AND (duration_ms IS NULL OR duration_ms >= 0))");
        foreach (AuditTables::faqs() as $table => [$entity, $fields]) {
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
    }

    public function down(): void
    {
        foreach (['faq_ai_calls', 'faq_ai_settings', 'page_faqs', 'product_faqs', 'category_faqs', 'faq_translations', 'faqs'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
