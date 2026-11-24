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
        // An optional cover for phones (e.g. a portrait crop); without one, phones show the main cover.
        Schema::table('blog_posts', function (Blueprint $t): void {
            $t->foreignId('cover_mobile_media_id')->nullable()->constrained('media_files')->restrictOnDelete();
            $t->index('cover_mobile_media_id');
        });
        $this->trigger(AuditTables::blogMobileCovers()['blog_posts'][1]);
    }

    public function down(): void
    {
        $this->trigger(AuditTables::blogs()['blog_posts'][1]);
        Schema::table('blog_posts', fn (Blueprint $t) => $t->dropConstrainedForeignId('cover_mobile_media_id'));
    }

    private function trigger(string $fields): void
    {
        DB::statement('DROP TRIGGER IF EXISTS cms_audit_mutation ON blog_posts');
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON blog_posts FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('blog_post', '$fields')");
    }
};
