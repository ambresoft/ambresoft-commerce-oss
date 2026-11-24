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
        // Customer groups: reusable segments for later pricing, promotions and access rules. Membership alone changes nothing.
        Schema::create('customer_groups', function (Blueprint $t): void {
            $t->id();
            $t->string('status', 10)->default('active');
            // New customers created without an explicit choice join the default group.
            $t->boolean('is_default')->default(false);
            $t->bigInteger('lock_version')->default(1);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
        });
        Schema::create('customer_group_translations', function (Blueprint $t): void {
            $t->foreignId('group_id')->constrained('customer_groups')->cascadeOnDelete();
            $t->foreignId('locale_id')->constrained('locales')->restrictOnDelete();
            $t->string('name', 120);
            $t->string('description', 1000)->default('');
            $t->primary(['group_id', 'locale_id']);
        });

        // Purchaser profiles, separate from staff identities (users). No credentials are stored here.
        Schema::create('customers', function (Blueprint $t): void {
            $t->id();
            $t->string('email', 254);
            $t->string('first_name', 100);
            $t->string('last_name', 100);
            $t->string('phone', 40)->nullable();
            $t->string('status', 10)->default('active');
            // Read by the Phase 8 tax calculation through its customer port.
            $t->boolean('tax_exempt')->default(false);
            $t->text('notes')->nullable();
            $t->unsignedBigInteger('default_billing_address_id')->nullable();
            $t->unsignedBigInteger('default_shipping_address_id')->nullable();
            $t->bigInteger('lock_version')->default(1);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->unique('email');
            $t->index(['created_at', 'id']);
        });
        Schema::create('customer_addresses', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $t->string('label', 60)->nullable();
            $t->string('first_name', 100);
            $t->string('last_name', 100);
            $t->string('company', 150)->nullable();
            $t->string('line1', 200);
            $t->string('line2', 200)->nullable();
            $t->string('city', 100);
            $t->string('postal_code', 20)->nullable();
            $t->char('country_code', 2);
            $t->string('region_code', 10)->nullable();
            $t->string('phone', 40)->nullable();
            $t->timestampsTz();
            // Target of the same-customer default-address foreign keys.
            $t->unique(['customer_id', 'id']);
        });
        Schema::create('customer_group_members', function (Blueprint $t): void {
            $t->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            // A group with members cannot be deleted silently; deletion reassigns or is refused.
            $t->foreignId('group_id')->constrained('customer_groups')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->primary(['customer_id', 'group_id']);
            $t->index('group_id');
        });

        DB::statement("ALTER TABLE customer_groups ADD CONSTRAINT customer_group_configuration CHECK (status IN ('active','disabled') AND lock_version > 0 AND (NOT is_default OR status = 'active'))");
        DB::statement('CREATE UNIQUE INDEX customer_group_single_default ON customer_groups (is_default) WHERE is_default');
        DB::statement("ALTER TABLE customer_group_translations ADD CONSTRAINT customer_group_name CHECK (btrim(name) <> '')");
        DB::statement('CREATE UNIQUE INDEX customer_group_name_unique ON customer_group_translations (locale_id, lower(name))');
        // Same normalization as staff identities: trimmed, lower-case, unique.
        DB::statement("ALTER TABLE customers ADD CONSTRAINT customer_profile CHECK (email = lower(btrim(email)) AND email <> '' AND btrim(first_name) <> '' AND btrim(last_name) <> '' AND status IN ('active','disabled') AND lock_version > 0)");
        DB::statement('CREATE INDEX customer_name_sort ON customers (lower(last_name), lower(first_name), id)');
        DB::statement("ALTER TABLE customer_addresses ADD CONSTRAINT customer_address_values CHECK (country_code ~ '^[A-Z]{2}$' AND (region_code IS NULL OR region_code ~ '^[A-Z0-9]{1,10}$') AND btrim(line1) <> '' AND btrim(city) <> '' AND btrim(first_name) <> '' AND btrim(last_name) <> '')");
        // A default address must belong to the same customer; deleting it clears only that default.
        DB::statement('ALTER TABLE customers ADD CONSTRAINT customer_default_billing FOREIGN KEY (id, default_billing_address_id) REFERENCES customer_addresses (customer_id, id) ON DELETE SET NULL (default_billing_address_id)');
        DB::statement('ALTER TABLE customers ADD CONSTRAINT customer_default_shipping FOREIGN KEY (id, default_shipping_address_id) REFERENCES customer_addresses (customer_id, id) ON DELETE SET NULL (default_shipping_address_id)');

        foreach (AuditTables::customers() as $table => [$entity, $fields]) {
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE customers DROP CONSTRAINT IF EXISTS customer_default_billing');
        DB::statement('ALTER TABLE customers DROP CONSTRAINT IF EXISTS customer_default_shipping');
        foreach (['customer_group_members', 'customer_addresses', 'customers', 'customer_group_translations', 'customer_groups'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
