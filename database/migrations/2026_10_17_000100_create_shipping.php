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
        // Settings of a carrier provider (core or add-on), declared by the provider. Secrets are one encrypted JSON object.
        Schema::create('shipping_carrier_settings', function (Blueprint $t): void {
            $t->id();
            $t->string('provider', 60)->unique();
            $t->jsonb('settings')->default('{}');
            $t->text('secrets')->nullable();
            $t->bigInteger('lock_version')->default(1);
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE shipping_carrier_settings ADD CONSTRAINT shipping_carrier_settings_values CHECK (provider ~ '^[a-z][a-z0-9_.-]{1,59}$' AND jsonb_typeof(settings) = 'object' AND lock_version > 0)");

        // Named carrier accounts (e.g. two courier contracts). Fields are declared by the provider; credentials never leave the server.
        Schema::create('shipping_carrier_accounts', function (Blueprint $t): void {
            $t->id();
            $t->string('provider', 60);
            $t->string('name', 100);
            $t->jsonb('settings')->default('{}');
            $t->text('secrets')->nullable();
            // HMAC of the credentials the carrier last accepted ("Test connection").
            $t->string('verified_fingerprint', 64)->nullable();
            $t->timestampTz('verified_at')->nullable();
            $t->integer('position')->default(0);
            $t->bigInteger('lock_version')->default(1);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->unique(['id', 'provider']);
        });
        DB::statement("ALTER TABLE shipping_carrier_accounts ADD CONSTRAINT shipping_carrier_account_values CHECK (provider ~ '^[a-z][a-z0-9_.-]{1,59}$' AND btrim(name) <> '' AND jsonb_typeof(settings) = 'object' AND lock_version > 0)");
        DB::statement('CREATE UNIQUE INDEX shipping_carrier_account_name ON shipping_carrier_accounts (provider, lower(name))');

        Schema::create('shipping_methods', function (Blueprint $t): void {
            $t->id();
            // manual: administrator-defined rates; carrier: a real-time quote from a registered provider.
            $t->string('calculation', 10);
            // Provider used for carrier rates and/or shipments (vouchers, labels, tracking); optional for manual methods.
            $t->string('provider', 60)->nullable();
            $t->unsignedBigInteger('account_id')->nullable();
            $t->boolean('is_active')->default(false);
            $t->integer('position')->default(0);
            // Weight limits in the store's weight unit; outside them the method is not offered.
            $t->decimal('min_weight', 15, 6)->nullable();
            $t->decimal('max_weight', 15, 6)->nullable();
            // Products marked "free shipping" are left out of this method's charge.
            $t->boolean('free_shipping_applies')->default(true);
            // Carrier quotes: charge the carrier amount with its VAT (gross) or without it (net), plus a fixed surcharge.
            $t->string('carrier_amount', 5)->default('gross');
            $t->decimal('carrier_surcharge', 20, 6)->default(0);
            $t->bigInteger('lock_version')->default(1);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->index(['position', 'id']);
        });
        DB::statement("ALTER TABLE shipping_methods ADD CONSTRAINT shipping_method_values CHECK (
            calculation IN ('manual','carrier') AND (calculation <> 'carrier' OR provider IS NOT NULL) AND (account_id IS NULL OR provider IS NOT NULL)
            AND (provider IS NULL OR provider ~ '^[a-z][a-z0-9_.-]{1,59}$') AND carrier_amount IN ('gross','net') AND carrier_surcharge >= 0
            AND (min_weight IS NULL OR min_weight >= 0) AND (max_weight IS NULL OR max_weight > 0) AND (min_weight IS NULL OR max_weight IS NULL OR max_weight >= min_weight)
            AND position BETWEEN -99999 AND 99999 AND lock_version > 0)");
        // The account always belongs to the method's provider.
        DB::statement('ALTER TABLE shipping_methods ADD CONSTRAINT shipping_method_account FOREIGN KEY (account_id, provider) REFERENCES shipping_carrier_accounts (id, provider) ON DELETE RESTRICT');

        Schema::create('shipping_method_translations', function (Blueprint $t): void {
            $t->foreignId('method_id')->constrained('shipping_methods')->cascadeOnDelete();
            $t->foreignId('locale_id')->constrained('locales')->restrictOnDelete();
            $t->string('name', 100);
            $t->string('description', 500)->default('');
            $t->string('delivery_time', 100)->default('');
            $t->primary(['method_id', 'locale_id']);
        });
        DB::statement("ALTER TABLE shipping_method_translations ADD CONSTRAINT shipping_method_name CHECK (btrim(name) <> '')");

        // Manual rates: per rate area and weight bracket, base + per weight unit above the bracket start (primary currency).
        Schema::create('shipping_method_rates', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('method_id')->constrained('shipping_methods')->cascadeOnDelete();
            $t->foreignId('rate_area_id')->constrained('rate_areas')->restrictOnDelete();
            $t->decimal('weight_from', 15, 6)->default(0);
            $t->decimal('weight_to', 15, 6)->nullable();
            $t->decimal('base', 20, 6)->default(0);
            $t->decimal('per_unit', 20, 6)->default(0);
            $t->unique(['method_id', 'rate_area_id', 'weight_from']);
        });
        DB::statement('ALTER TABLE shipping_method_rates ADD CONSTRAINT shipping_method_rate_values CHECK (weight_from >= 0 AND (weight_to IS NULL OR weight_to > weight_from) AND base >= 0 AND per_unit >= 0)');

        // Optional restrictions; no rows means no restriction.
        Schema::create('shipping_method_areas', function (Blueprint $t): void {
            $t->foreignId('method_id')->constrained('shipping_methods')->cascadeOnDelete();
            $t->foreignId('rate_area_id')->constrained('rate_areas')->restrictOnDelete();
            $t->primary(['method_id', 'rate_area_id']);
        });
        Schema::create('shipping_method_taxes', function (Blueprint $t): void {
            $t->foreignId('method_id')->constrained('shipping_methods')->cascadeOnDelete();
            $t->foreignId('tax_id')->constrained('taxes')->restrictOnDelete();
            $t->primary(['method_id', 'tax_id']);
        });
        Schema::create('shipping_method_payment_methods', function (Blueprint $t): void {
            $t->foreignId('method_id')->constrained('shipping_methods')->cascadeOnDelete();
            $t->foreignId('payment_method_id')->constrained('payment_methods')->restrictOnDelete();
            $t->primary(['method_id', 'payment_method_id']);
        });
        Schema::create('shipping_method_customer_groups', function (Blueprint $t): void {
            $t->foreignId('method_id')->constrained('shipping_methods')->cascadeOnDelete();
            $t->foreignId('group_id')->constrained('customer_groups')->restrictOnDelete();
            $t->primary(['method_id', 'group_id']);
        });

        // The order keeps the method, its name at that time and how the charge was calculated (never credentials).
        Schema::table('orders', function (Blueprint $t): void {
            $t->foreignId('shipping_method_id')->nullable()->constrained('shipping_methods')->restrictOnDelete();
            $t->string('shipping_method_name', 100)->nullable();
            $t->jsonb('shipping_details')->nullable();
        });
        DB::statement('ALTER TABLE orders ADD CONSTRAINT order_shipping_method CHECK ((shipping_method_id IS NULL) = (shipping_method_name IS NULL))');

        // Carrier shipments (e.g. courier vouchers). Historical rows outlive methods, accounts and add-ons.
        Schema::create('order_shipments', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $t->foreignId('shipping_method_id')->nullable()->constrained('shipping_methods')->restrictOnDelete();
            $t->string('provider', 60);
            $t->foreignId('account_id')->nullable()->constrained('shipping_carrier_accounts')->restrictOnDelete();
            $t->string('account_name', 100)->nullable();
            // creating (claimed, carrier call in progress) → created; or failed / cancelled.
            $t->string('state', 12);
            // Idempotency key of the staff request; the same request never creates a second shipment.
            $t->uuid('request_key')->unique();
            $t->string('tracking_number', 60)->nullable();
            $t->jsonb('extra_numbers')->default('[]');
            $t->date('pickup_date')->nullable();
            $t->decimal('weight_kg', 10, 3)->nullable();
            $t->smallInteger('packages')->default(1);
            $t->decimal('cod_amount', 20, 6)->nullable();
            $t->char('currency_code', 3)->nullable();
            // Safe summary of what was sent (no credentials).
            $t->jsonb('details')->default('{}');
            $t->string('manifest_number', 60)->nullable();
            $t->date('manifest_date')->nullable();
            $t->timestampTz('label_printed_at')->nullable();
            $t->string('tracking_state', 12)->nullable();
            $t->string('tracking_code', 30)->nullable();
            $t->string('tracking_summary', 500)->nullable();
            $t->jsonb('tracking_events')->nullable();
            $t->timestampTz('tracking_checked_at')->nullable();
            $t->timestampTz('delivered_at')->nullable();
            $t->timestampTz('returned_at')->nullable();
            // The order status this shipment expects; automatic transitions only apply while the order is still in it.
            $t->foreignId('expected_status_id')->nullable()->constrained('order_statuses')->nullOnDelete();
            $t->jsonb('applied_events')->default('[]');
            $t->string('error', 500)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('cancelled_at')->nullable();
            $t->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->index(['order_id', 'id']);
            $t->index(['provider', 'account_id', 'pickup_date']);
        });
        DB::statement("ALTER TABLE order_shipments ADD CONSTRAINT order_shipment_values CHECK (
            state IN ('creating','created','failed','cancelled') AND (state <> 'created' OR tracking_number IS NOT NULL)
            AND packages BETWEEN 1 AND 99 AND (weight_kg IS NULL OR weight_kg > 0) AND (cod_amount IS NULL OR cod_amount > 0)
            AND (tracking_state IS NULL OR tracking_state IN ('created','in_transit','delivered','returning','returned','exception','unknown'))
            AND jsonb_typeof(extra_numbers) = 'array' AND jsonb_typeof(applied_events) = 'array')");
        DB::statement('CREATE UNIQUE INDEX order_shipment_tracking_number ON order_shipments (provider, tracking_number) WHERE tracking_number IS NOT NULL');
        DB::statement("CREATE UNIQUE INDEX order_shipment_single_creating ON order_shipments (order_id) WHERE state = 'creating'");

        // Carrier pickup lists / manifests (e.g. a courier's daily pickup list).
        Schema::create('shipping_manifests', function (Blueprint $t): void {
            $t->id();
            $t->string('provider', 60);
            $t->foreignId('account_id')->constrained('shipping_carrier_accounts')->restrictOnDelete();
            $t->string('number', 60);
            $t->date('manifest_date');
            $t->integer('voucher_count')->nullable();
            $t->uuid('request_key')->unique();
            $t->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['provider', 'account_id', 'number']);
        });

        foreach (AuditTables::shipping() as $table => [$entity, $fields]) {
            DB::statement("DROP TRIGGER IF EXISTS cms_audit_mutation ON $table");
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
    }

    public function down(): void
    {
        [$entity, $fields] = AuditTables::payments()['orders'];
        DB::statement('DROP TRIGGER IF EXISTS cms_audit_mutation ON orders');
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON orders FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        Schema::dropIfExists('shipping_manifests');
        Schema::dropIfExists('order_shipments');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT order_shipping_method');
        Schema::table('orders', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('shipping_method_id');
            $t->dropColumn(['shipping_method_name', 'shipping_details']);
        });
        foreach (['shipping_method_customer_groups', 'shipping_method_payment_methods', 'shipping_method_taxes', 'shipping_method_areas', 'shipping_method_rates', 'shipping_method_translations', 'shipping_methods', 'shipping_carrier_accounts', 'shipping_carrier_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
