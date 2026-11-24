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
        // Simple content pages (privacy policy, about us…). Storefront rendering and layouts are not part of this phase.
        Schema::create('pages', function (Blueprint $t): void {
            $t->id();
            // Parent deletion is refused while children exist; the application also refuses cycles.
            $t->foreignId('parent_id')->nullable()->constrained('pages')->restrictOnDelete();
            $t->integer('position')->default(0);
            // active: listed and reachable; hidden: reachable by its address only; disabled: not reachable.
            $t->string('status', 10)->default('active');
            $t->date('created_on')->default(DB::raw('CURRENT_DATE'));
            $t->timestampTz('available_from')->nullable();
            $t->timestampTz('available_until')->nullable();
            $t->string('facebook_type', 20)->default('website');
            $t->bigInteger('lock_version')->default(1);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->index(['parent_id', 'position', 'id']);
        });
        Schema::create('page_translations', function (Blueprint $t): void {
            $t->foreignId('page_id')->constrained('pages')->cascadeOnDelete();
            $t->foreignId('locale_id')->constrained('locales')->restrictOnDelete();
            $t->string('name', 200);
            // Sanitized HTML (server page-content policy).
            $t->text('content')->default('');
            $t->string('slug', 200);
            $t->string('seo_title', 200)->default('');
            $t->string('meta_description', 500)->default('');
            $t->string('meta_keywords', 500)->default('');
            $t->primary(['page_id', 'locale_id']);
            $t->unique(['locale_id', 'slug']);
        });
        // Empty means every visitor; otherwise members of at least one listed customer group.
        Schema::create('page_customer_groups', function (Blueprint $t): void {
            $t->foreignId('page_id')->constrained('pages')->cascadeOnDelete();
            $t->foreignId('group_id')->constrained('customer_groups')->cascadeOnDelete();
            $t->primary(['page_id', 'group_id']);
            $t->index('group_id');
        });
        DB::statement("ALTER TABLE pages ADD CONSTRAINT page_values CHECK (
            status IN ('active','hidden','disabled') AND lock_version > 0 AND position BETWEEN -99999 AND 99999
            AND (parent_id IS NULL OR parent_id <> id) AND facebook_type ~ '^[a-z_]{2,20}$'
            AND (available_from IS NULL OR available_until IS NULL OR available_until > available_from))");
        DB::statement("ALTER TABLE page_translations ADD CONSTRAINT page_translation_values CHECK (
            btrim(name) <> '' AND slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$' AND length(content) <= 500000)");
        foreach (AuditTables::pages() as $table => [$entity, $fields]) {
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
    }

    public function down(): void
    {
        foreach (['page_customer_groups', 'page_translations', 'pages'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
