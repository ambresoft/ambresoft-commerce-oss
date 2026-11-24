<?php

use App\Audit\Infrastructure\AuditTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Shareable filter addresses use a readable name (?product-label=black-friday), generated from the sticker name.
        Schema::table('stickers', fn (Blueprint $t) => $t->string('filter_slug', 80)->nullable()->unique());
        $used = [];
        foreach (DB::table('stickers')->orderBy('id')->get(['id', 'name']) as $sticker) {
            $base = substr(Str::slug($sticker->name), 0, 70) ?: 'label';
            $slug = $base;
            for ($n = 2; isset($used[$slug]); $n++) {
                $slug = $base.'-'.$n;
            }
            $used[$slug] = true;
            DB::table('stickers')->where('id', $sticker->id)->update(['filter_slug' => $slug]);
        }
        DB::statement("ALTER TABLE stickers ADD CONSTRAINT sticker_filter_slug CHECK (filter_slug IS NULL OR filter_slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$')");
        $this->trigger(AuditTables::stickerFilterSlugs()['stickers'][1]);
    }

    public function down(): void
    {
        $this->trigger(AuditTables::stickerFilters()['stickers'][1]);
        DB::statement('ALTER TABLE stickers DROP CONSTRAINT IF EXISTS sticker_filter_slug');
        Schema::table('stickers', fn (Blueprint $t) => $t->dropColumn('filter_slug'));
    }

    private function trigger(string $fields): void
    {
        DB::statement('DROP TRIGGER IF EXISTS cms_audit_mutation ON stickers');
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON stickers FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('sticker', '$fields')");
    }
};
