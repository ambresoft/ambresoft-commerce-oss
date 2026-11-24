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
        // A sticker can also be offered as a shopping option in category, brand and collection filters.
        Schema::table('stickers', fn (Blueprint $t) => $t->boolean('is_filter')->default(false));
        $this->trigger(AuditTables::stickerFilters()['stickers'][1]);
    }

    public function down(): void
    {
        $this->trigger(AuditTables::stickers()['stickers'][1]);
        Schema::table('stickers', fn (Blueprint $t) => $t->dropColumn('is_filter'));
    }

    private function trigger(string $fields): void
    {
        DB::statement('DROP TRIGGER IF EXISTS cms_audit_mutation ON stickers');
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON stickers FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('sticker', '$fields')");
    }
};
