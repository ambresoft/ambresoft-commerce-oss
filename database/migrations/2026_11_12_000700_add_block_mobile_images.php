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
        // An optional phone version of the block's picture; without it phones get the desktop picture.
        Schema::table('storefront_blocks', function (Blueprint $t): void {
            $t->foreignId('image_mobile_media_id')->nullable()->constrained('media_files')->nullOnDelete();
            $t->index('image_mobile_media_id');
        });
        $this->trigger(AuditTables::blockImages()['storefront_blocks'][1]);
        DB::table('storefront_layouts')->increment('lock_version');
    }

    public function down(): void
    {
        $this->trigger(AuditTables::storefront()['storefront_blocks'][1].',image_media_id');
        Schema::table('storefront_blocks', fn (Blueprint $t) => $t->dropConstrainedForeignId('image_mobile_media_id'));
    }

    private function trigger(string $fields): void
    {
        DB::statement('DROP TRIGGER IF EXISTS cms_audit_mutation ON storefront_blocks');
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON storefront_blocks FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('storefront_block', '$fields')");
    }
};
