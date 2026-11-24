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
        // Lifecycle state of add-ons (bundled in modules/ or uploaded). Files are discovered on disk; this records what was
        // installed (and at which version) and whether it is enabled. Uninstalling removes the row and keeps owned data.
        Schema::create('addons', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 100)->unique();
            $t->string('source', 10);
            $t->string('version', 50);
            $t->string('status', 10)->default('disabled');
            $t->bigInteger('lock_version')->default(1);
            $t->foreignId('installed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE addons ADD CONSTRAINT addon_values CHECK (
            name ~ '^[a-z0-9][a-z0-9_.-]*/[a-z0-9][a-z0-9_.-]*$' AND source IN ('bundled','uploaded') AND status IN ('disabled','enabled')
            AND version ~ '^[0-9]+\\.[0-9]+\\.[0-9]+(-[0-9A-Za-z.-]+)?$' AND lock_version > 0)");

        // One row per registered payment method code (core or add-on). Rows outlive their add-on so historical orders
        // keep a readable method. Secrets are encrypted with the application key and never leave the server.
        Schema::create('payment_methods', function (Blueprint $t): void {
            $t->id();
            $t->string('code', 60)->unique();
            // `core` or the add-on that registers the method; another add-on can never take over the row.
            $t->string('owner', 100);
            $t->boolean('is_active')->default(false);
            $t->integer('position')->default(0);
            // Optional order status applied once when an order becomes fully paid with this method.
            $t->foreignId('paid_status_id')->nullable()->constrained('order_statuses')->nullOnDelete();
            $t->jsonb('settings')->default('{}');
            $t->text('secrets')->nullable();
            // HMAC of the credential values that the provider last accepted; a change requires a new verification.
            $t->string('verified_fingerprint', 64)->nullable();
            $t->timestampTz('verified_at')->nullable();
            $t->bigInteger('lock_version')->default(1);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->index(['position', 'id']);
        });
        DB::statement("ALTER TABLE payment_methods ADD CONSTRAINT payment_method_values CHECK (
            code ~ '^[a-z][a-z0-9_.-]{1,59}$' AND (owner = 'core' OR owner ~ '^[a-z0-9][a-z0-9_.-]*/[a-z0-9][a-z0-9_.-]*$') AND jsonb_typeof(settings) = 'object' AND position BETWEEN -99999 AND 99999 AND lock_version > 0)");
        Schema::create('payment_method_translations', function (Blueprint $t): void {
            $t->foreignId('method_id')->constrained('payment_methods')->cascadeOnDelete();
            $t->foreignId('locale_id')->constrained('locales')->restrictOnDelete();
            $t->string('name', 100);
            $t->string('description', 500)->default('');
            $t->primary(['method_id', 'locale_id']);
        });
        DB::statement("ALTER TABLE payment_method_translations ADD CONSTRAINT payment_method_name CHECK (btrim(name) <> '')");

        // The method chosen for the order, with its name at that time (the order stays readable whatever happens later).
        Schema::table('orders', function (Blueprint $t): void {
            $t->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->restrictOnDelete();
            $t->string('payment_method_name', 100)->nullable();
        });
        DB::statement('ALTER TABLE orders ADD CONSTRAINT order_payment_method CHECK ((payment_method_id IS NULL) = (payment_method_name IS NULL))');

        // Money recorded against an order: manual records (cash collected on delivery) and online attempts (redirect
        // providers). Payment state is independent of the order status.
        Schema::create('order_payments', function (Blueprint $t): void {
            $t->id();
            // Public reference sent to providers; also the base of idempotency keys.
            $t->uuid('reference')->unique();
            $t->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $t->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->restrictOnDelete();
            $t->string('kind', 10);
            $t->string('state', 12);
            $t->decimal('amount', 20, 6);
            $t->char('currency_code', 3);
            $t->string('provider_reference', 100)->nullable();
            $t->string('capture_reference', 100)->nullable();
            $t->string('redirect_url', 2048)->nullable();
            // Short machine code for the last outcome (e.g. instrument_declined); never a provider message or secret.
            $t->string('detail', 60)->nullable();
            $t->string('note', 1000)->nullable();
            $t->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('claimed_at')->nullable();
            $t->timestampTz('completed_at')->nullable();
            $t->timestampsTz();
            $t->index(['order_id', 'id']);
        });
        DB::statement("ALTER TABLE order_payments ADD CONSTRAINT order_payment_values CHECK (
            kind IN ('manual','online') AND state IN ('created','processing','pending','captured','failed','cancelled')
            AND amount > 0 AND currency_code ~ '^[A-Z]{3}$'
            AND (kind <> 'manual' OR (state = 'captured' AND provider_reference IS NULL))
            AND (kind <> 'online' OR payment_method_id IS NOT NULL))");
        // A provider order or capture is recorded once; one online attempt per order may be open at a time.
        DB::statement('CREATE UNIQUE INDEX order_payment_provider_reference ON order_payments (payment_method_id, provider_reference) WHERE provider_reference IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX order_payment_capture_reference ON order_payments (payment_method_id, capture_reference) WHERE capture_reference IS NOT NULL');
        DB::statement("CREATE UNIQUE INDEX order_payment_single_open ON order_payments (order_id) WHERE kind = 'online' AND state IN ('created','processing')");

        foreach (AuditTables::payments() as $table => [$entity, $fields]) {
            DB::statement("DROP TRIGGER IF EXISTS cms_audit_mutation ON $table");
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
    }

    public function down(): void
    {
        [$entity, $fields] = AuditTables::orders()['orders'];
        DB::statement('DROP TRIGGER IF EXISTS cms_audit_mutation ON orders');
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON orders FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        Schema::dropIfExists('order_payments');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT order_payment_method');
        Schema::table('orders', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('payment_method_id');
            $t->dropColumn('payment_method_name');
        });
        Schema::dropIfExists('payment_method_translations');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('addons');
    }
};
