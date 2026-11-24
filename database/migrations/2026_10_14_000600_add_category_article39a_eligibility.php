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
        Schema::table('categories', function (Blueprint $table): void {
            $table->boolean('article_39a_eligible')->default(false);
        });
        DB::statement('ALTER TABLE categories ADD CONSTRAINT categories_system_article39a_check CHECK (system_key IS NULL OR NOT article_39a_eligible)');
        $this->capture(AuditTables::categoryArticle39a()['categories']);
    }

    public function down(): void
    {
        $this->capture(AuditTables::all()['categories']);
        DB::statement('ALTER TABLE categories DROP CONSTRAINT categories_system_article39a_check');
        Schema::table('categories', fn (Blueprint $table) => $table->dropColumn('article_39a_eligible'));
    }

    /** @param array{string, string} $definition */
    private function capture(array $definition): void
    {
        [$entity, $fields] = $definition;
        DB::statement('DROP TRIGGER IF EXISTS cms_audit_mutation ON categories');
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON categories FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
    }
};
