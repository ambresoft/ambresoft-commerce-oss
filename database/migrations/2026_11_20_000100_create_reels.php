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
        Schema::create('reels', function (Blueprint $t): void {
            $t->id();
            $t->string('status', 16)->default('active');
            $t->foreignId('video_id')->constrained('media_videos')->restrictOnDelete();
            $t->foreignId('cover_media_id')->constrained('media_files')->restrictOnDelete();
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->index(['status', 'created_at', 'id']);
            $t->index('video_id');
            $t->index('cover_media_id');
        });
        DB::statement("ALTER TABLE reels ADD CONSTRAINT reels_valid CHECK (status IN ('active','disabled') AND lock_version > 0)");
        Schema::create('reel_translations', function (Blueprint $t): void {
            $t->foreignId('reel_id')->constrained('reels')->cascadeOnDelete();
            $t->foreignId('locale_id')->constrained('locales')->restrictOnDelete();
            $t->string('name', 200);
            $t->primary(['reel_id', 'locale_id']);
            $t->index('locale_id');
        });
        Schema::create('reel_products', function (Blueprint $t): void {
            $t->foreignId('reel_id')->constrained('reels')->cascadeOnDelete();
            $t->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $t->unsignedSmallInteger('position');
            $t->primary(['reel_id', 'product_id']);
            $t->unique(['reel_id', 'position']);
            $t->index('product_id');
        });
        DB::statement('ALTER TABLE reel_products ADD CONSTRAINT reel_product_position CHECK (position BETWEEN 1 AND 24)');
        Schema::create('reel_views', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('reel_id')->constrained('reels')->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->char('viewer_key', 64);
            $t->unsignedBigInteger('view_bucket');
            $t->timestampTz('viewed_at');
            $t->unique(['reel_id', 'viewer_key', 'view_bucket']);
            $t->index(['reel_id', 'viewed_at']);
            $t->index(['viewed_at', 'reel_id']);
            $t->index(['user_id', 'viewed_at']);
        });
        Schema::table('general_settings', fn (Blueprint $t) => $t->unsignedSmallInteger('reels_days')->default(90));
        DB::statement('ALTER TABLE general_settings ADD CONSTRAINT reels_days_valid CHECK (reels_days BETWEEN 1 AND 3650)');
        Schema::table('storefront_block_selections', function (Blueprint $t): void {
            $t->foreignId('reel_id')->nullable()->constrained('reels')->cascadeOnDelete();
            $t->index('reel_id');
        });
        $this->selectionRule(', reel_id');
        foreach (AuditTables::reels() as $table => [$entity, $fields]) {
            $this->trigger($table, $entity, $fields);
        }
        DB::table('storefront_layouts')->increment('lock_version');
    }

    public function down(): void
    {
        $this->trigger('storefront_block_selections', 'storefront_selection', AuditTables::dealBlockSelections()['storefront_block_selections'][1]);
        $this->trigger('general_settings', 'general_settings', AuditTables::checkoutAccountSetting()['general_settings'][1]);
        DB::table('storefront_block_selections')->whereNotNull('reel_id')->delete();
        DB::table('storefront_blocks')->where('type', 'reels')->delete();
        $this->selectionRule('');
        Schema::table('storefront_block_selections', fn (Blueprint $t) => $t->dropConstrainedForeignId('reel_id'));
        DB::statement('ALTER TABLE general_settings DROP CONSTRAINT reels_days_valid');
        Schema::table('general_settings', fn (Blueprint $t) => $t->dropColumn('reels_days'));
        foreach (['reel_views', 'reel_products', 'reel_translations', 'reels'] as $table) {
            Schema::drop($table);
        }
        DB::table('storefront_layouts')->increment('lock_version');
    }

    private function selectionRule(string $extra): void
    {
        DB::statement('ALTER TABLE storefront_block_selections DROP CONSTRAINT storefront_selection');
        DB::statement("ALTER TABLE storefront_block_selections ADD CONSTRAINT storefront_selection CHECK (num_nonnulls(product_id, category_id, banner_id, banner_group_id, menu_id, newsletter_list_id, brand_id, search_term_id, page_id, blog_id, blog_post_id, coupon_id, promotion_id$extra) = 1 AND position > 0)");
    }

    private function trigger(string $table, string $entity, string $fields): void
    {
        DB::statement("DROP TRIGGER IF EXISTS cms_audit_mutation ON $table");
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
    }
};
