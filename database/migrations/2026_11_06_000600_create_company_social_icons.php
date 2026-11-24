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
        Schema::create('company_social_icons', function (Blueprint $t): void {
            $t->id();
            $t->string('network', 20)->unique();
            $t->foreignId('media_file_id')->unique()->constrained('media_files')->restrictOnDelete();
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE company_social_icons ADD CONSTRAINT company_social_icons_network CHECK (network IN ('facebook','instagram','tiktok','linkedin','x','youtube','pinterest'))");
        foreach (AuditTables::companySocialIcons() as $table => [$entity, $fields]) {
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('company_social_icons');
    }
};
