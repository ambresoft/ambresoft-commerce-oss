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
        Schema::table('general_settings', function (Blueprint $t): void {
            $t->boolean('storefront_open')->default(true);
            $t->string('date_format', 10)->default('d/m/Y');
            $t->string('time_format', 10)->default('H:i');
            $t->string('timezone', 100)->default('UTC');
            $t->string('calendar_date_format', 10)->default('d/m/Y');
            $t->smallInteger('calendar_week_start')->default(1);
            $t->boolean('warn_unsaved_changes')->default(true);
            $t->boolean('show_menu_descriptions')->default(true);
            $t->smallInteger('notification_duration')->default(5);
            $t->string('phone_validation', 12)->default('formatted');
            $t->string('weight_unit', 20)->default('kg');
            $t->decimal('grams_per_weight_unit', 13, 6)->default(1000);
            $t->integer('low_stock_threshold')->default(0);
            $t->boolean('allow_product_comparison')->default(true);
            $t->boolean('track_inventory')->default(true);
            $t->boolean('single_coupon')->default(true);
        });
        DB::statement("ALTER TABLE general_settings ADD CONSTRAINT general_settings_basic CHECK (date_format IN ('d/m/Y','m/d/Y','Y-m-d','d.m.Y') AND calendar_date_format IN ('d/m/Y','m/d/Y','Y-m-d','d.m.Y') AND time_format IN ('H:i','h:i A') AND calendar_week_start IN (0,1) AND notification_duration BETWEEN 0 AND 300 AND phone_validation IN ('formatted','digits') AND btrim(weight_unit) <> '' AND grams_per_weight_unit > 0 AND grams_per_weight_unit <= 1000000 AND low_stock_threshold >= 0)");
        // Merchant text belongs to locale-linked settings translations, never UI language columns.
        Schema::create('general_settings_translations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('settings_id')->constrained('general_settings')->cascadeOnDelete();
            $t->foreignId('locale_id')->constrained('locales')->restrictOnDelete();
            $t->string('storefront_closed_message', 2000);
            $t->unique(['settings_id', 'locale_id']);
            $t->index('locale_id');
        });
        DB::statement("ALTER TABLE general_settings_translations ADD CONSTRAINT general_settings_closed_message CHECK (btrim(storefront_closed_message) <> '')");
        foreach (AuditTables::generalOptions() as $table => [$entity, $fields]) {
            DB::statement("DROP TRIGGER IF EXISTS cms_audit_mutation ON $table");
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('general_settings_translations');
        DB::statement('DROP TRIGGER IF EXISTS cms_audit_mutation ON general_settings');
        DB::statement('ALTER TABLE general_settings DROP CONSTRAINT general_settings_basic');
        Schema::table('general_settings', fn (Blueprint $t) => $t->dropColumn(['storefront_open', 'date_format', 'time_format', 'timezone', 'calendar_date_format', 'calendar_week_start', 'warn_unsaved_changes', 'show_menu_descriptions', 'notification_duration', 'phone_validation', 'weight_unit', 'grams_per_weight_unit', 'low_stock_threshold', 'allow_product_comparison', 'track_inventory', 'single_coupon']));
        [$entity, $fields] = AuditTables::taxes()['general_settings'];
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON general_settings FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
    }
};
