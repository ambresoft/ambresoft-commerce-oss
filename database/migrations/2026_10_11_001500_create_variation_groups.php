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
        // Third mutually exclusive purpose; like landing pages it is Single choice only.
        DB::statement('ALTER TABLE features DROP CONSTRAINT feature_purpose');
        DB::statement("ALTER TABLE features ADD CONSTRAINT feature_purpose CHECK (purpose IN ('standard','landing_pages','variations') AND (purpose = 'standard' OR type = 'single'))");
        Schema::table('features', function (Blueprint $t): void {
            // Shared presentation setting for every group using the feature; kept while another purpose is active.
            $t->string('variation_style', 16)->default('text');
        });
        DB::statement("ALTER TABLE features ADD CONSTRAINT feature_variation_style CHECK (variation_style IN ('text','color','image','dropdown'))");
        Schema::table('feature_options', function (Blueprint $t): void {
            $t->char('swatch_color', 7)->nullable();
            // Separate from landing_image_media_id: purpose or style changes never move either association.
            $t->foreignId('swatch_image_media_id')->nullable()->unique()->constrained('media_files')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE feature_options ADD CONSTRAINT feature_option_swatch_color CHECK (swatch_color IS NULL OR swatch_color ~ '^#[0-9A-F]{6}$')");

        Schema::create('variation_groups', function (Blueprint $t): void {
            $t->id();
            // Admin-only, non-localized, non-unique label; never an identifier.
            $t->string('name', 200);
            $t->bigInteger('lock_version')->default(1);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
        });
        DB::statement('ALTER TABLE variation_groups ADD CONSTRAINT variation_group_configuration CHECK (length(btrim(name)) > 0 AND lock_version > 0)');
        Schema::create('variation_group_features', function (Blueprint $t): void {
            $t->foreignId('group_id')->constrained('variation_groups')->cascadeOnDelete();
            // A feature referenced by a group cannot be deleted underneath it.
            $t->foreignId('feature_id')->constrained('features')->restrictOnDelete();
            // Group-specific selector order; option order stays the shared feature order.
            $t->integer('position');
            $t->primary(['group_id', 'feature_id']);
            $t->unique(['group_id', 'position']);
            $t->index('feature_id');
        });
        DB::statement('ALTER TABLE variation_group_features ADD CONSTRAINT variation_group_feature_position CHECK (position > 0)');
        Schema::create('variation_group_members', function (Blueprint $t): void {
            $t->foreignId('group_id')->constrained('variation_groups')->cascadeOnDelete();
            // UNIQUE product: a product belongs to zero or one group. Physical product deletion removes membership.
            $t->foreignId('product_id')->unique()->constrained('products')->cascadeOnDelete();
            // Server-built from sorted "feature:option" stable IDs; an index aid, not the product identity.
            $t->string('signature', 2000);
            $t->timestampTz('joined_at')->useCurrent();
            $t->primary(['group_id', 'product_id']);
            $t->unique(['group_id', 'signature']);
        });
        DB::statement("ALTER TABLE variation_group_members ADD CONSTRAINT variation_group_member_signature CHECK (signature ~ '^[0-9]+:[0-9]+(,[0-9]+:[0-9]+)*$')");
        Schema::create('variation_group_intents', function (Blueprint $t): void {
            // A product created by "Create variation" stays standalone; this only records the target group.
            $t->foreignId('product_id')->primary()->constrained('products')->cascadeOnDelete();
            $t->foreignId('group_id')->constrained('variation_groups')->cascadeOnDelete();
            $t->index('group_id');
        });
        Schema::create('variation_group_audits', function (Blueprint $t): void {
            $t->id();
            // Group identity survives disbanding, like product audits survive permanent deletion.
            $t->bigInteger('group_id')->index();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('action', 60);
            $t->jsonb('context');
            $t->timestampTz('occurred_at')->useCurrent();
        });

        // Central ledger capture for the new tables and the new reviewed feature/option columns.
        foreach (AuditTables::variationGroups() as $table => [$entity, $fields]) {
            DB::statement("DROP TRIGGER IF EXISTS cms_audit_mutation ON $table");
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
    }

    public function down(): void
    {
        $previous = AuditTables::all();
        foreach (array_keys(AuditTables::variationGroups()) as $table) {
            if (Schema::hasTable($table)) {
                DB::statement("DROP TRIGGER IF EXISTS cms_audit_mutation ON $table");
            }
            if (isset($previous[$table])) {
                [$entity, $fields] = $previous[$table];
                DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
            }
        }
        Schema::dropIfExists('variation_group_audits');
        Schema::dropIfExists('variation_group_intents');
        Schema::dropIfExists('variation_group_members');
        Schema::dropIfExists('variation_group_features');
        Schema::dropIfExists('variation_groups');
        // Rollback discards swatch data and leaves swatch image files as unreferenced media.
        DB::statement('ALTER TABLE feature_options DROP CONSTRAINT feature_option_swatch_color');
        Schema::table('feature_options', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('swatch_image_media_id');
            $t->dropColumn('swatch_color');
        });
        DB::statement('ALTER TABLE features DROP CONSTRAINT feature_variation_style');
        Schema::table('features', fn (Blueprint $t) => $t->dropColumn('variation_style'));
        // Variation-purpose features fall back to Standard Attribute so the original constraint holds.
        DB::table('features')->where('purpose', 'variations')->update(['purpose' => 'standard']);
        DB::statement('ALTER TABLE features DROP CONSTRAINT feature_purpose');
        DB::statement("ALTER TABLE features ADD CONSTRAINT feature_purpose CHECK (purpose IN ('standard','landing_pages') AND (purpose = 'standard' OR type = 'single'))");
    }
};
