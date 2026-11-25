<?php

use App\Audit\Infrastructure\AuditTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tables created here, children first. */
    private const TABLES = ['product_taxes', 'tax_rates', 'tax_translations', 'taxes', 'rate_area_regions', 'rate_area_countries', 'rate_area_translations', 'rate_areas'];

    public function up(): void
    {
        // Reusable geographic rate areas (taxes now, shipping zones later). Membership is relational, never a JSON list.
        Schema::create('rate_areas', function (Blueprint $t): void {
            $t->id();
            $t->string('status', 10)->default('active');
            // The all-countries fallback area: carries no memberships and matches only when nothing more specific does.
            $t->boolean('is_default')->default(false);
            $t->bigInteger('lock_version')->default(1);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
        });
        Schema::create('rate_area_translations', function (Blueprint $t): void {
            $t->foreignId('rate_area_id')->constrained('rate_areas')->cascadeOnDelete();
            $t->foreignId('locale_id')->constrained('locales')->restrictOnDelete();
            $t->string('name', 120);
            $t->primary(['rate_area_id', 'locale_id']);
        });
        Schema::create('rate_area_countries', function (Blueprint $t): void {
            $t->foreignId('rate_area_id')->constrained('rate_areas')->cascadeOnDelete();
            $t->char('country_code', 2);
            $t->primary(['rate_area_id', 'country_code']);
        });
        // A country with region rows is restricted to those regions; without rows the whole country is a member.
        Schema::create('rate_area_regions', function (Blueprint $t): void {
            $t->unsignedBigInteger('rate_area_id');
            $t->char('country_code', 2);
            $t->string('region_code', 10);
            $t->primary(['rate_area_id', 'country_code', 'region_code']);
            $t->foreign(['rate_area_id', 'country_code'])->references(['rate_area_id', 'country_code'])->on('rate_area_countries')->cascadeOnDelete();
        });
        Schema::create('taxes', function (Blueprint $t): void {
            $t->id();
            $t->string('registration_number', 100)->nullable();
            $t->integer('priority')->default(0);
            $t->string('status', 10)->default('active');
            $t->boolean('price_includes_tax')->default(false);
            $t->string('address_basis', 10)->default('shipping');
            $t->bigInteger('lock_version')->default(1);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
        });
        Schema::create('tax_translations', function (Blueprint $t): void {
            $t->foreignId('tax_id')->constrained('taxes')->cascadeOnDelete();
            $t->foreignId('locale_id')->constrained('locales')->restrictOnDelete();
            $t->string('name', 120);
            $t->primary(['tax_id', 'locale_id']);
        });
        Schema::create('tax_rates', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tax_id')->constrained('taxes')->cascadeOnDelete();
            // An area referenced by a rate cannot be deleted: no rate is ever orphaned or silently re-targeted.
            $t->foreignId('rate_area_id')->constrained('rate_areas')->restrictOnDelete();
            $t->string('rate_type', 10);
            // Percentage as an exact fraction (0.24 means 24%); fixed amount in the primary currency like products.price.
            $t->decimal('percent_rate', 12, 8)->nullable();
            $t->decimal('fixed_amount', 20, 6)->nullable();
            $t->timestampsTz();
            $t->unique(['tax_id', 'rate_area_id']);
            $t->index('rate_area_id');
        });
        Schema::create('product_taxes', function (Blueprint $t): void {
            $t->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            // Referenced taxes cannot be deleted; disabling is the safe retirement path.
            $t->foreignId('tax_id')->constrained('taxes')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->primary(['product_id', 'tax_id']);
            $t->index('tax_id');
        });

        DB::statement("ALTER TABLE rate_areas ADD CONSTRAINT rate_area_configuration CHECK (status IN ('active','disabled') AND lock_version > 0)");
        DB::statement('CREATE UNIQUE INDEX rate_area_single_default ON rate_areas (is_default) WHERE is_default');
        DB::statement("ALTER TABLE rate_area_translations ADD CONSTRAINT rate_area_name CHECK (btrim(name) <> '')");
        DB::statement('CREATE UNIQUE INDEX rate_area_name_unique ON rate_area_translations (locale_id, lower(name))');
        DB::statement("ALTER TABLE rate_area_countries ADD CONSTRAINT rate_area_country_code CHECK (country_code ~ '^[A-Z]{2}$')");
        DB::statement("ALTER TABLE rate_area_regions ADD CONSTRAINT rate_area_region_code CHECK (region_code ~ '^[A-Z0-9]{1,10}$')");
        DB::statement("ALTER TABLE taxes ADD CONSTRAINT tax_configuration CHECK (status IN ('active','disabled') AND address_basis IN ('shipping','billing') AND priority BETWEEN 0 AND 9999 AND lock_version > 0)");
        DB::statement("ALTER TABLE tax_translations ADD CONSTRAINT tax_name CHECK (btrim(name) <> '')");
        DB::statement("ALTER TABLE tax_rates ADD CONSTRAINT tax_rate_value CHECK ((rate_type = 'percent' AND percent_rate BETWEEN 0 AND 1 AND fixed_amount IS NULL) OR (rate_type = 'fixed' AND fixed_amount >= 0 AND percent_rate IS NULL))");

        // Tax calculation settings join the single installation-wide settings row (A68); no competing settings store.
        Schema::table('general_settings', function (Blueprint $t): void {
            $t->string('tax_calculation_method', 12)->default('unit_price');
            $t->boolean('display_catalog_prices_with_tax')->default(false);
            $t->boolean('display_cart_prices_with_tax')->default(false);
            $t->boolean('tax_estimate_without_address')->default(false);
            $t->char('tax_default_country', 2)->nullable();
            $t->string('tax_default_region', 10)->nullable();
        });
        DB::statement("ALTER TABLE general_settings ADD CONSTRAINT general_settings_tax CHECK (tax_calculation_method IN ('unit_price','subtotal') AND (tax_default_country IS NULL OR tax_default_country ~ '^[A-Z]{2}$') AND (tax_default_region IS NULL OR (tax_default_country IS NOT NULL AND tax_default_region ~ '^[A-Z0-9]{1,10}$')) AND (NOT tax_estimate_without_address OR tax_default_country IS NOT NULL))");

        DB::statement('DROP TRIGGER IF EXISTS cms_audit_mutation ON general_settings');
        foreach (AuditTables::taxes() as $table => [$entity, $fields]) {
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
    }

    public function down(): void
    {
        foreach (array_keys(AuditTables::taxes()) as $table) {
            DB::statement("DROP TRIGGER IF EXISTS cms_audit_mutation ON $table");
        }
        DB::statement('ALTER TABLE general_settings DROP CONSTRAINT IF EXISTS general_settings_tax');
        Schema::table('general_settings', function (Blueprint $t): void {
            $t->dropColumn(['tax_calculation_method', 'display_catalog_prices_with_tax', 'display_cart_prices_with_tax', 'tax_estimate_without_address', 'tax_default_country', 'tax_default_region']);
        });
        foreach (AuditTables::settings() as $table => [$entity, $fields]) {
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }
};
