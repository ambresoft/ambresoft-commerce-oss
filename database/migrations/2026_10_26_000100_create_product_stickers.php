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
        Schema::create('stickers', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 128);
            $t->string('type', 10);
            $t->string('style', 5);
            $t->string('status', 10)->default('active');
            $t->timestampTz('starts_at')->nullable();
            $t->timestampTz('ends_at')->nullable();
            $t->integer('priority')->default(100);
            $t->string('match', 3)->default('all');
            $t->foreignId('image_media_id')->nullable()->constrained('media_files')->nullOnDelete();
            $t->index('image_media_id');
            $t->string('placement', 20)->default('image_top');
            foreach (['card', 'detail', 'desktop', 'tablet', 'mobile'] as $field) {
                $t->boolean('show_'.$field)->default(true);
            }
            $t->string('background', 7)->default('#111827');
            $t->string('color', 7)->default('#ffffff');
            $t->string('border_color', 7)->default('#111827');
            $t->smallInteger('border_width')->default(0);
            $t->smallInteger('radius')->default(4);
            $t->smallInteger('padding')->default(6);
            $t->smallInteger('font_size')->default(12);
            $t->string('alignment', 6)->default('center');
            $t->smallInteger('image_width')->default(64);
            $t->string('css_class', 128)->default('');
            $t->bigInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->index(['status', 'priority', 'id']);
        });
        DB::statement("ALTER TABLE stickers ADD CONSTRAINT sticker_values CHECK (btrim(name) <> '' AND type IN ('manual','dynamic') AND style IN ('text','image') AND status IN ('active','disabled') AND (starts_at IS NULL OR ends_at IS NULL OR ends_at > starts_at) AND priority BETWEEN 0 AND 99999 AND match IN ('all','any') AND placement IN ('image_top','image_bottom','above_price','below_price') AND background ~ '^#[0-9a-fA-F]{6}$' AND color ~ '^#[0-9a-fA-F]{6}$' AND border_color ~ '^#[0-9a-fA-F]{6}$' AND border_width BETWEEN 0 AND 4 AND radius BETWEEN 0 AND 48 AND padding BETWEEN 0 AND 24 AND font_size BETWEEN 10 AND 24 AND alignment IN ('left','center','right') AND image_width BETWEEN 16 AND 160 AND css_class ~ '^[a-zA-Z0-9_ -]*$' AND lock_version > 0)");
        Schema::create('sticker_translations', function (Blueprint $t): void {
            $t->foreignId('sticker_id')->constrained('stickers')->cascadeOnDelete();
            $t->foreignId('locale_id')->constrained('locales')->restrictOnDelete();
            $t->string('text', 200)->default('');
            $t->string('short_text', 80)->default('');
            $t->string('tooltip', 500)->default('');
            $t->primary(['sticker_id', 'locale_id']);
            $t->index('locale_id');
        });
        Schema::create('product_stickers', function (Blueprint $t): void {
            $t->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $t->foreignId('sticker_id')->constrained('stickers')->cascadeOnDelete();
            $t->primary(['product_id', 'sticker_id']);
            $t->index('sticker_id');
        });
        Schema::create('sticker_conditions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('sticker_id')->constrained('stickers')->cascadeOnDelete();
            $t->string('type', 20);
            $t->decimal('minimum', 20, 6)->nullable();
            $t->decimal('maximum', 20, 6)->nullable();
            foreach (['category' => 'categories', 'feature' => 'features', 'option' => 'feature_options'] as $key => $table) {
                $t->foreignId($key.'_id')->nullable()->constrained($table)->nullOnDelete();
                $t->index($key.'_id');
            }
            $t->boolean('include_descendants')->default(true);
            $t->boolean('boolean_value')->nullable();
        });
        DB::statement("ALTER TABLE sticker_conditions ADD CONSTRAINT sticker_condition_values CHECK (type IN ('price','discount_percent','discount_amount','category','feature','in_stock','out_of_stock','quantity','purchasable','new','sales','free_shipping') AND (minimum IS NULL OR minimum >= 0) AND (maximum IS NULL OR maximum >= 0) AND (minimum IS NULL OR maximum IS NULL OR maximum >= minimum) AND (type NOT IN ('price','quantity','sales') OR minimum IS NOT NULL OR maximum IS NOT NULL) AND (type <> 'new' OR (maximum IS NOT NULL AND maximum BETWEEN 1 AND 3650 AND maximum = trunc(maximum))) AND (type <> 'discount_percent' OR (minimum IS NULL OR minimum <= 100) AND (maximum IS NULL OR maximum <= 100)))");
        Schema::create('sticker_display_settings', function (Blueprint $t): void {
            $t->integer('id')->primary();
            $t->smallInteger('card_limit')->default(3);
            $t->smallInteger('detail_limit')->default(5);
            $t->bigInteger('lock_version')->default(1);
        });
        DB::statement('ALTER TABLE sticker_display_settings ADD CONSTRAINT sticker_limits CHECK (id = 1 AND card_limit BETWEEN 0 AND 20 AND detail_limit BETWEEN 0 AND 20 AND lock_version > 0)');
        DB::table('sticker_display_settings')->insert(['id' => 1]);
        foreach (AuditTables::stickers() as $table => [$entity, $fields]) {
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
    }

    public function down(): void
    {
        foreach (['sticker_display_settings', 'sticker_conditions', 'product_stickers', 'sticker_translations', 'stickers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
