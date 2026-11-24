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
        // How the banner's button reacts to the pointer and how long the change takes (seconds, 0 for none).
        Schema::table('banners', function (Blueprint $t): void {
            $t->string('button_hover', 8)->default('none');
            $t->decimal('button_transition', 2, 1)->default(0.3);
        });
        DB::statement("ALTER TABLE banners ADD CONSTRAINT banner_button_hover CHECK (button_hover IN ('none','darken','lighten','lift','grow','glow','invert') AND button_transition BETWEEN 0 AND 2)");
        $this->trigger(AuditTables::bannerButtonHover()['banners'][1]);
    }

    public function down(): void
    {
        $this->trigger(AuditTables::banners()['banners'][1]);
        DB::statement('ALTER TABLE banners DROP CONSTRAINT banner_button_hover');
        Schema::table('banners', fn (Blueprint $t) => $t->dropColumn(['button_hover', 'button_transition']));
    }

    private function trigger(string $fields): void
    {
        DB::statement('DROP TRIGGER IF EXISTS cms_audit_mutation ON banners');
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON banners FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('banner', '$fields')");
    }
};
