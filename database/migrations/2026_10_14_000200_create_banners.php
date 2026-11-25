<?php

use App\Audit\Infrastructure\AuditTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Children first. */
    private const TABLES = ['banner_customer_groups', 'banner_products', 'banner_contents', 'banner_layouts', 'banner_translations', 'banners', 'banner_groups', 'media_videos'];

    public function up(): void
    {
        // Private MP4 files (banner background videos). Images stay in media_files with their image-only constraint.
        Schema::create('media_videos', function (Blueprint $t): void {
            $t->id();
            $t->string('disk', 40);
            $t->string('storage_key')->unique();
            $t->string('mime_type', 40);
            $t->unsignedInteger('byte_size');
            $t->timestampsTz();
        });
        // Admin-only organisation of banners; the name is an internal label, never shown on the storefront.
        Schema::create('banner_groups', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 120);
            $t->timestampsTz();
        });
        // Advanced banner: settings that are the same on every device. Device-dependent settings live in banner_layouts,
        // language-dependent texts and media in banner_contents.
        Schema::create('banners', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('group_id')->nullable()->constrained('banner_groups')->nullOnDelete();
            $t->integer('position')->default(0);
            $t->string('status', 10)->default('active');
            $t->date('created_on')->default(DB::raw('CURRENT_DATE'));
            // Optional availability window; outside it the banner is not live even when active.
            $t->timestampTz('available_from')->nullable();
            $t->timestampTz('available_until')->nullable();
            $t->string('color_scheme', 5)->default('light');
            $t->string('object_type', 8)->default('image');
            $t->boolean('youtube_autoplay')->default(false);
            $t->boolean('youtube_hide_controls')->default(false);
            $t->string('products_template', 10)->default('grid');
            $t->smallInteger('products_columns')->default(4);
            $t->smallInteger('products_rows')->default(1);
            $t->string('object_valign', 6)->default('center');
            $t->string('object_halign', 6)->default('center');
            $t->char('background_color', 7)->nullable();
            $t->string('background_halign', 6)->default('center');
            $t->char('title_color', 7)->nullable();
            $t->string('title_weight', 6)->default('normal');
            $t->string('title_tag', 3)->default('div');
            $t->char('description_color', 7)->nullable();
            $t->char('description_background', 7)->nullable();
            $t->string('button_style', 8)->default('standard');
            $t->string('url', 2048)->nullable();
            $t->boolean('new_window')->default(false);
            $t->boolean('tablet_custom')->default(false);
            $t->boolean('phone_custom')->default(false);
            $t->bigInteger('lock_version')->default(1);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->index(['group_id', 'position', 'id']);
        });
        Schema::create('banner_translations', function (Blueprint $t): void {
            $t->foreignId('banner_id')->constrained('banners')->cascadeOnDelete();
            $t->foreignId('locale_id')->constrained('locales')->restrictOnDelete();
            $t->string('name', 200);
            $t->primary(['banner_id', 'locale_id']);
        });
        // One row per device. The PC row is complete; tablet/phone columns are NULL when they inherit the PC value.
        Schema::create('banner_layouts', function (Blueprint $t): void {
            $t->foreignId('banner_id')->constrained('banners')->cascadeOnDelete();
            $t->string('device', 6);
            $t->string('valign', 6)->nullable();
            $t->string('halign', 6)->nullable();
            $t->string('paddings', 60)->nullable();
            $t->boolean('full_width')->nullable();
            $t->smallInteger('title_size')->nullable();
            $t->boolean('title_shadow')->nullable();
            $t->smallInteger('description_size')->nullable();
            $t->string('background_type', 5)->nullable();
            $t->string('background_size', 7)->nullable();
            $t->string('background_valign', 6)->nullable();
            $t->boolean('button_enabled')->nullable();
            $t->char('button_text_color', 7)->nullable();
            $t->char('button_color', 7)->nullable();
            $t->string('mask_type', 8)->nullable();
            $t->string('mask_position', 7)->nullable();
            $t->smallInteger('mask_opacity')->nullable();
            $t->string('mask_direction', 6)->nullable();
            $t->char('mask_color', 7)->nullable();
            $t->string('css_classes', 300)->nullable();
            $t->primary(['banner_id', 'device']);
        });
        // Per device and language: texts and media. Tablet/phone NULLs inherit the PC value of the same language.
        Schema::create('banner_contents', function (Blueprint $t): void {
            $t->foreignId('banner_id')->constrained('banners')->cascadeOnDelete();
            $t->string('device', 6);
            $t->foreignId('locale_id')->constrained('locales')->restrictOnDelete();
            $t->string('title', 500)->nullable();
            $t->string('description', 1000)->nullable();
            $t->string('button_text', 120)->nullable();
            $t->string('youtube_video', 11)->nullable();
            $t->foreignId('main_image_media_id')->nullable()->constrained('media_files')->restrictOnDelete();
            $t->foreignId('background_image_media_id')->nullable()->constrained('media_files')->restrictOnDelete();
            $t->foreignId('background_video_id')->nullable()->constrained('media_videos')->restrictOnDelete();
            $t->primary(['banner_id', 'device', 'locale_id']);
            $t->index('main_image_media_id');
            $t->index('background_image_media_id');
            $t->index('background_video_id');
        });
        Schema::create('banner_products', function (Blueprint $t): void {
            $t->foreignId('banner_id')->constrained('banners')->cascadeOnDelete();
            $t->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $t->smallInteger('position');
            $t->primary(['banner_id', 'product_id']);
            $t->index('product_id');
        });
        // Empty means every visitor; otherwise only members of at least one listed customer group.
        Schema::create('banner_customer_groups', function (Blueprint $t): void {
            $t->foreignId('banner_id')->constrained('banners')->cascadeOnDelete();
            $t->foreignId('group_id')->constrained('customer_groups')->cascadeOnDelete();
            $t->primary(['banner_id', 'group_id']);
            $t->index('group_id');
        });

        $hex = fn (string $c): string => "($c IS NULL OR $c ~ '^#[0-9a-f]{6}$')";
        $align = fn (string $c, string $values): string => "($c IS NULL OR $c IN ($values))";
        DB::statement("ALTER TABLE media_videos ADD CONSTRAINT media_video_check CHECK (mime_type = 'video/mp4' AND byte_size > 0 AND byte_size <= 16777216)");
        DB::statement("ALTER TABLE banner_groups ADD CONSTRAINT banner_group_name CHECK (btrim(name) <> '')");
        DB::statement('CREATE UNIQUE INDEX banner_group_name_unique ON banner_groups (lower(name))');
        DB::statement("ALTER TABLE banners ADD CONSTRAINT banner_values CHECK (
            status IN ('active','disabled') AND lock_version > 0 AND position BETWEEN -99999 AND 99999
            AND (available_from IS NULL OR available_until IS NULL OR available_until > available_from)
            AND color_scheme IN ('light','dark') AND object_type IN ('image','youtube','products')
            AND products_template IN ('grid','small','thumbnails') AND products_columns BETWEEN 1 AND 6 AND products_rows BETWEEN 1 AND 4
            AND object_valign IN ('top','center','bottom') AND object_halign IN ('left','center','right') AND background_halign IN ('left','center','right')
            AND title_weight IN ('thin','normal','bold','heavy') AND title_tag IN ('div','h1','h2','h3','h4','h5','h6')
            AND button_style IN ('standard','outline','text')
            AND {$hex('background_color')} AND {$hex('title_color')} AND {$hex('description_color')} AND {$hex('description_background')})");
        DB::statement("ALTER TABLE banner_translations ADD CONSTRAINT banner_name CHECK (btrim(name) <> '')");
        DB::statement("ALTER TABLE banner_layouts ADD CONSTRAINT banner_layout_values CHECK (
            device IN ('pc','tablet','phone')
            AND {$align('valign', "'top','center','bottom'")} AND {$align('halign', "'left','center','right'")} AND {$align('background_valign', "'top','center','bottom'")}
            AND (paddings IS NULL OR paddings ~ '^[0-9]{1,4}(px|%)( [0-9]{1,4}(px|%)){0,3}$')
            AND (title_size IS NULL OR title_size BETWEEN 6 AND 200) AND (description_size IS NULL OR description_size BETWEEN 6 AND 200)
            AND {$align('background_type', "'image','video'")} AND {$align('background_size', "'cover','contain'")}
            AND {$align('mask_type', "'none','semi','blur','gradient','color'")} AND {$align('mask_position', "'banner','content'")}
            AND (mask_opacity IS NULL OR mask_opacity BETWEEN 0 AND 100) AND {$align('mask_direction', "'auto','top','bottom','left','right'")}
            AND {$hex('button_text_color')} AND {$hex('button_color')} AND {$hex('mask_color')}
            AND (css_classes IS NULL OR css_classes ~ '^[A-Za-z_-][A-Za-z0-9_-]*( [A-Za-z_-][A-Za-z0-9_-]*)*$')
            AND (device <> 'pc' OR (valign IS NOT NULL AND halign IS NOT NULL AND paddings IS NOT NULL AND full_width IS NOT NULL
                AND title_size IS NOT NULL AND title_shadow IS NOT NULL AND description_size IS NOT NULL AND background_type IS NOT NULL
                AND background_size IS NOT NULL AND background_valign IS NOT NULL AND button_enabled IS NOT NULL AND mask_type IS NOT NULL
                AND mask_position IS NOT NULL AND mask_opacity IS NOT NULL AND mask_direction IS NOT NULL)))");
        DB::statement("ALTER TABLE banner_contents ADD CONSTRAINT banner_content_values CHECK (
            device IN ('pc','tablet','phone') AND (youtube_video IS NULL OR (device = 'pc' AND youtube_video ~ '^[A-Za-z0-9_-]{11}$')))");
        DB::statement('ALTER TABLE banner_products ADD CONSTRAINT banner_product_position CHECK (position BETWEEN 0 AND 999)');
        foreach (AuditTables::banners() as $table => [$entity, $fields]) {
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }
};
