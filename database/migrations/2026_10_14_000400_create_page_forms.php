<?php

use App\Audit\Infrastructure\AuditTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['page_form_variant_translations', 'page_form_variants', 'page_form_field_translations', 'page_form_translations', 'page_forms', 'page_form_fields'];

    public function up(): void
    {
        // Form definition built on a page (form builder). Submissions and e-mail delivery are later phases.
        Schema::create('page_form_fields', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('page_id')->constrained('pages')->cascadeOnDelete();
            $t->integer('position')->default(0);
            $t->string('type', 12);
            $t->boolean('required')->default(false);
            $t->string('status', 10)->default('active');
            $t->index(['page_id', 'position', 'id']);
            $t->unique(['page_id', 'id']);
        });
        Schema::create('page_forms', function (Blueprint $t): void {
            $t->foreignId('page_id')->primary()->constrained('pages')->cascadeOnDelete();
            $t->boolean('enabled')->default(false);
            // before / after the page content, or at the [form] marker inside it.
            $t->string('placement', 6)->default('after');
            // form_name: the page name; field: the value of one form field; custom: per-language text.
            $t->string('subject_mode', 9)->default('form_name');
            $t->unsignedBigInteger('subject_field_id')->nullable();
            // Comma-separated recipient addresses (validated, at most 10).
            $t->string('recipients', 1000)->default('');
            // The subject field must belong to the same page; removing it falls back to the form name in the application.
            $t->foreign(['page_id', 'subject_field_id'])->references(['page_id', 'id'])->on('page_form_fields');
        });
        Schema::create('page_form_translations', function (Blueprint $t): void {
            $t->foreignId('page_id')->constrained('pages')->cascadeOnDelete();
            $t->foreignId('locale_id')->constrained('locales')->restrictOnDelete();
            $t->text('submit_text')->default('');
            $t->string('custom_subject', 200)->default('');
            $t->primary(['page_id', 'locale_id']);
        });
        Schema::create('page_form_field_translations', function (Blueprint $t): void {
            $t->foreignId('field_id')->constrained('page_form_fields')->cascadeOnDelete();
            $t->foreignId('locale_id')->constrained('locales')->restrictOnDelete();
            $t->string('name', 200);
            $t->primary(['field_id', 'locale_id']);
        });
        Schema::create('page_form_variants', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('field_id')->constrained('page_form_fields')->cascadeOnDelete();
            $t->integer('position')->default(0);
            $t->index(['field_id', 'position', 'id']);
        });
        Schema::create('page_form_variant_translations', function (Blueprint $t): void {
            $t->foreignId('variant_id')->constrained('page_form_variants')->cascadeOnDelete();
            $t->foreignId('locale_id')->constrained('locales')->restrictOnDelete();
            $t->string('label', 200);
            $t->primary(['variant_id', 'locale_id']);
        });
        $types = "'header','separator','input','textarea','select','radio','checkboxes','checkbox','date','email','number','phone','country','region','file'";
        DB::statement("ALTER TABLE page_form_fields ADD CONSTRAINT page_form_field_values CHECK (type IN ($types) AND status IN ('active','disabled') AND position BETWEEN -99999 AND 99999 AND (NOT required OR type NOT IN ('header','separator')))");
        DB::statement("ALTER TABLE page_forms ADD CONSTRAINT page_form_values CHECK (placement IN ('before','after','marker') AND subject_mode IN ('form_name','field','custom') AND ((subject_mode = 'field') = (subject_field_id IS NOT NULL)) AND (NOT enabled OR btrim(recipients) <> ''))");
        DB::statement("ALTER TABLE page_form_field_translations ADD CONSTRAINT page_form_field_name CHECK (btrim(name) <> '')");
        DB::statement("ALTER TABLE page_form_variant_translations ADD CONSTRAINT page_form_variant_label CHECK (btrim(label) <> '')");
        DB::statement('ALTER TABLE page_form_variants ADD CONSTRAINT page_form_variant_position CHECK (position BETWEEN -99999 AND 99999)');
        foreach (AuditTables::pageForms() as $table => [$entity, $fields]) {
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }
};
