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
        // URL redirects (Website → SEO Rules). The source is stored in its normalized matching form, so it is unique as matched.
        Schema::create('seo_redirects', function (Blueprint $t): void {
            $t->id();
            $t->string('source_path', 2048)->unique();
            $t->string('destination', 2048);
            $t->smallInteger('status_code')->default(301);
            $t->boolean('enabled')->default(true);
            $t->bigInteger('lock_version')->default(1);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE seo_redirects ADD CONSTRAINT seo_redirect_values CHECK (status_code IN (301, 302) AND source_path LIKE '/_%'
            AND source_path NOT LIKE '%//%' AND source_path NOT LIKE '%/' AND destination <> '' AND destination <> source_path AND lock_version > 0)");
        foreach (AuditTables::seoRedirects() as $table => [$entity, $fields]) {
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_redirects');
    }
};
