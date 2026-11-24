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
        Schema::table('brands', function (Blueprint $table): void {
            $table->foreignId('banner_image_media_id')->nullable()->constrained('media_files')->restrictOnDelete();
            $table->index('banner_image_media_id');
            $table->string('banner_link', 2048)->nullable();
        });
        Schema::table('brand_translations', fn (Blueprint $table) => $table->string('banner_alt', 200)->default(''));
        DB::statement("ALTER TABLE brands ADD CONSTRAINT brand_banner_link CHECK (banner_link IS NULL OR btrim(banner_link) <> '')");
        $this->audit(AuditTables::brandBanners());
    }

    public function down(): void
    {
        $this->audit(array_intersect_key(AuditTables::brands(), array_flip(['brands', 'brand_translations'])));
        DB::statement('ALTER TABLE brands DROP CONSTRAINT brand_banner_link');
        Schema::table('brands', function (Blueprint $table): void {
            $table->dropIndex(['banner_image_media_id']);
            $table->dropConstrainedForeignId('banner_image_media_id');
            $table->dropColumn('banner_link');
        });
        Schema::table('brand_translations', fn (Blueprint $table) => $table->dropColumn('banner_alt'));
    }

    /** @param array<string,array{string,string}> $tables */
    private function audit(array $tables): void
    {
        foreach ($tables as $table => [$entity, $fields]) {
            DB::statement("DROP TRIGGER cms_audit_mutation ON $table");
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
    }
};
