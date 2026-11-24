<?php

use App\Audit\Infrastructure\AuditTables;
use App\Order\Infrastructure\OrderEmailDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Configurable order statuses. Orders reference the stable ID; `code` is a stable key for integrations.
        Schema::create('order_statuses', function (Blueprint $t): void {
            $t->id();
            $t->string('code', 40)->unique();
            $t->string('color', 7)->default('#64748b');
            $t->integer('position')->default(0);
            $t->boolean('is_active')->default(true);
            // The single status every new order starts in.
            $t->boolean('is_initial')->default(false);
            // Item, price and address edits on an order in a final status need an explicit confirmation.
            $t->boolean('is_final')->default(false);
            // Seeded statuses cannot be deleted; they can still be renamed or disabled (except the initial one).
            $t->boolean('is_system')->default(false);
            // none | decrease (take ordered quantities from stock) | restore (return what this order took).
            $t->string('stock_action', 10)->default('none');
            $t->bigInteger('lock_version')->default(1);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->index(['position', 'id']);
        });
        DB::statement("ALTER TABLE order_statuses ADD CONSTRAINT order_status_values CHECK (code ~ '^[a-z][a-z0-9_]{1,39}$' AND color ~ '^#[0-9a-f]{6}$' AND stock_action IN ('none','decrease','restore') AND lock_version > 0 AND position BETWEEN -99999 AND 99999 AND (NOT is_initial OR is_active))");
        DB::statement('CREATE UNIQUE INDEX order_status_single_initial ON order_statuses (is_initial) WHERE is_initial');
        Schema::create('order_status_translations', function (Blueprint $t): void {
            $t->foreignId('status_id')->constrained('order_statuses')->cascadeOnDelete();
            $t->foreignId('locale_id')->constrained('locales')->restrictOnDelete();
            $t->string('name', 100);
            $t->string('description', 500)->default('');
            $t->primary(['status_id', 'locale_id']);
        });
        DB::statement("ALTER TABLE order_status_translations ADD CONSTRAINT order_status_name CHECK (btrim(name) <> '')");

        // One customer email template per status and language; generated with the status and kept when it is disabled.
        Schema::create('order_email_templates', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('status_id')->constrained('order_statuses')->cascadeOnDelete();
            $t->foreignId('locale_id')->constrained('locales')->restrictOnDelete();
            $t->string('subject', 250);
            // Sanitized HTML with {{variable}} placeholders from the allow-list; never executed.
            $t->text('body_html');
            $t->bigInteger('lock_version')->default(1);
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->unique(['status_id', 'locale_id']);
        });
        DB::statement("ALTER TABLE order_email_templates ADD CONSTRAINT order_email_template_values CHECK (btrim(subject) <> '' AND length(body_html) <= 100000 AND lock_version > 0)");

        // Human-readable numbers come from a sequence: unique under concurrency, never reused after a rollback.
        DB::statement('CREATE SEQUENCE IF NOT EXISTS order_number_seq START WITH 100001');

        Schema::create('orders', function (Blueprint $t): void {
            $t->id();
            $t->string('order_number', 40)->unique();
            $t->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $t->foreignId('status_id')->constrained('order_statuses')->restrictOnDelete();
            // Language of the customer's emails.
            $t->foreignId('locale_id')->constrained('locales')->restrictOnDelete();
            // Contact snapshot: independent of later customer profile changes.
            $t->string('first_name', 100);
            $t->string('last_name', 100);
            $t->string('email', 254);
            $t->string('phone', 40)->nullable();
            // The customer's request IP from the trusted request context; NULL for staff-created orders.
            $t->ipAddress('ip_address')->nullable();
            // Order-time currency snapshot. Amounts are never converted when the primary currency changes later.
            $t->char('currency_code', 3);
            $t->unsignedSmallInteger('currency_decimals');
            $t->decimal('subtotal', 20, 6);
            $t->decimal('discount_total', 20, 6)->default(0);
            // All tax, including the part already contained in tax-inclusive prices (tax_included).
            $t->decimal('tax_total', 20, 6)->default(0);
            $t->decimal('tax_included', 20, 6)->default(0);
            // Explicit for the future shipping phase; nothing calculates it yet.
            $t->decimal('shipping_total', 20, 6)->default(0);
            $t->decimal('total', 20, 6);
            $t->string('tax_method', 20);
            $t->boolean('tax_exempt')->default(false);
            $t->text('customer_note')->nullable();
            $t->text('internal_note')->nullable();
            // Whether the order currently holds stock taken by a "decrease" status.
            $t->boolean('stock_held')->default(false);
            $t->bigInteger('lock_version')->default(1);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->index(['created_at', 'id']);
            $t->index(['updated_at', 'id']);
            $t->index(['status_id', 'id']);
            $t->index(['total', 'id']);
            $t->index('customer_id');
        });
        DB::statement("ALTER TABLE orders ADD CONSTRAINT order_values CHECK (
            btrim(first_name) <> '' AND btrim(last_name) <> '' AND email = lower(btrim(email)) AND email <> ''
            AND currency_code ~ '^[A-Z]{3}$' AND currency_decimals <= 6 AND lock_version > 0
            AND subtotal >= 0 AND discount_total >= 0 AND tax_total >= 0 AND tax_included >= 0 AND tax_included <= tax_total
            AND shipping_total >= 0 AND total >= 0
            AND total = subtotal - discount_total + (tax_total - tax_included) + shipping_total
            AND tax_method IN ('unit_price','subtotal'))");
        // Owned by the column, so dropping the orders table (including migrate:fresh) drops the sequence too.
        DB::statement('ALTER SEQUENCE order_number_seq OWNED BY orders.order_number');
        DB::statement('CREATE INDEX order_customer_name ON orders (lower(last_name), lower(first_name), id)');
        DB::statement('CREATE INDEX order_email ON orders (email)');

        Schema::create('order_addresses', function (Blueprint $t): void {
            $t->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $t->string('type', 10);
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
            $t->primary(['order_id', 'type']);
        });
        DB::statement("ALTER TABLE order_addresses ADD CONSTRAINT order_address_values CHECK (type IN ('billing','shipping') AND country_code ~ '^[A-Z]{2}$' AND (region_code IS NULL OR region_code ~ '^[A-Z0-9]{1,10}$') AND btrim(line1) <> '' AND btrim(city) <> '' AND btrim(first_name) <> '' AND btrim(last_name) <> '')");

        Schema::create('order_items', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            // Kept while the product exists; the snapshot below keeps the line readable after permanent deletion.
            $t->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $t->integer('position');
            $t->string('name', 200);
            $t->string('sku', 200)->nullable();
            $t->string('variation', 500)->nullable();
            $t->integer('quantity');
            // Catalog price when the line was priced, and the unit price actually charged (an override never changes the catalog).
            $t->decimal('catalog_price', 20, 6);
            $t->decimal('unit_price', 20, 6);
            $t->boolean('price_overridden')->default(false);
            $t->decimal('subtotal', 20, 6);
            $t->decimal('tax_total', 20, 6)->default(0);
            $t->decimal('tax_included', 20, 6)->default(0);
            $t->decimal('total', 20, 6);
            // Units this line has taken from the product's stock; restored exactly, never twice.
            $t->integer('deducted_quantity')->default(0);
            $t->unique(['order_id', 'position']);
            $t->index('product_id');
        });
        DB::statement("ALTER TABLE order_items ADD CONSTRAINT order_item_values CHECK (btrim(name) <> '' AND quantity > 0 AND deducted_quantity >= 0 AND deducted_quantity <= quantity AND position > 0
            AND catalog_price >= 0 AND unit_price >= 0 AND subtotal >= 0 AND tax_total >= 0 AND tax_included >= 0 AND tax_included <= tax_total
            AND total = subtotal + (tax_total - tax_included))");

        // Order-time tax breakdown (no foreign key: a tax deleted later must not change the record).
        Schema::create('order_taxes', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $t->unsignedBigInteger('tax_id');
            $t->string('name', 200);
            $t->integer('priority');
            $t->boolean('included');
            $t->string('rate_type', 10)->nullable();
            $t->decimal('rate_value', 20, 6)->nullable();
            $t->decimal('amount', 20, 6);
            $t->unique(['order_id', 'tax_id']);
        });

        Schema::create('order_status_history', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $t->foreignId('from_status_id')->nullable()->constrained('order_statuses')->restrictOnDelete();
            $t->foreignId('to_status_id')->constrained('order_statuses')->restrictOnDelete();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            // admin | system | checkout
            $t->string('source', 20);
            $t->string('note', 1000)->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['order_id', 'created_at', 'id']);
            $t->index('to_status_id');
        });
        DB::statement("ALTER TABLE order_status_history ADD CONSTRAINT order_status_history_values CHECK (source IN ('admin','system','checkout') AND (from_status_id IS NULL OR from_status_id <> to_status_id))");
        // Transitions are append-only; only the actor reference may be cleared when a staff account is removed.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION cms_guard_order_history() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'UPDATE' AND NEW.actor_id IS NULL AND OLD.actor_id IS NOT NULL
        AND (NEW.id, NEW.order_id, NEW.from_status_id, NEW.to_status_id, NEW.source, NEW.note, NEW.created_at) IS NOT DISTINCT FROM (OLD.id, OLD.order_id, OLD.from_status_id, OLD.to_status_id, OLD.source, OLD.note, OLD.created_at) THEN
        RETURN NEW;
    END IF;
    RAISE EXCEPTION 'Order status history is append-only' USING ERRCODE = '55000';
END;
$$;
CREATE TRIGGER order_status_history_append_only BEFORE UPDATE ON order_status_history FOR EACH ROW EXECUTE FUNCTION cms_guard_order_history();
SQL);

        // One customer email per transition (including order creation); retries update the same record.
        Schema::create('order_notifications', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $t->foreignId('history_id')->unique()->constrained('order_status_history')->cascadeOnDelete();
            $t->foreignId('status_id')->constrained('order_statuses')->restrictOnDelete();
            $t->foreignId('template_id')->nullable()->constrained('order_email_templates')->nullOnDelete();
            $t->string('recipient', 254);
            $t->string('subject', 300)->nullable();
            // pending | sending | sent | failed
            $t->string('state', 10)->default('pending');
            $t->unsignedInteger('attempts')->default(0);
            $t->string('last_error', 500)->nullable();
            $t->timestampTz('last_attempt_at')->nullable();
            $t->timestampTz('sent_at')->nullable();
            $t->foreignId('retried_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->index(['order_id', 'id']);
        });
        DB::statement("ALTER TABLE order_notifications ADD CONSTRAINT order_notification_values CHECK (state IN ('pending','sending','sent','failed') AND (state <> 'sent' OR sent_at IS NOT NULL))");

        Schema::create('order_comments', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $t->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            // internal: staff only, never emailed; customer: may be shown to the customer in a future account area.
            $t->string('visibility', 10);
            $t->text('body');
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['order_id', 'created_at', 'id']);
        });
        DB::statement("ALTER TABLE order_comments ADD CONSTRAINT order_comment_values CHECK (visibility IN ('internal','customer') AND btrim(body) <> '' AND length(body) <= 5000)");

        OrderEmailDefaults::seedStatuses();

        foreach (AuditTables::orders() as $table => [$entity, $fields]) {
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
    }

    public function down(): void
    {
        foreach (['order_comments', 'order_notifications', 'order_status_history', 'order_taxes', 'order_items', 'order_addresses', 'orders', 'order_email_templates', 'order_status_translations', 'order_statuses'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::statement('DROP FUNCTION IF EXISTS cms_guard_order_history()');
        DB::statement('DROP SEQUENCE IF EXISTS order_number_seq');
    }
};
