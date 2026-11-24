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
        Schema::create('gift_cards', function (Blueprint $t): void {
            $t->id();
            // Not called "code": the generic audit trigger labels rows by name, label, sku or code, which would copy this
            // spending credential into the audit trail.
            $t->string('number', 19)->unique();
            $t->string('recipient_name', 128);
            $t->string('sender_name', 128);
            $t->string('recipient_email', 254);
            $t->string('message', 2000)->nullable();
            // The language of the emails sent for this card.
            $t->foreignId('locale_id')->constrained('locales')->restrictOnDelete();
            // The face value and balance are in the currency the card was issued in; they are never converted.
            $t->char('currency_code', 3);
            $t->smallInteger('currency_decimals');
            $t->decimal('amount', 20, 6);
            $t->decimal('balance', 20, 6);
            $t->string('status', 10)->default('active');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->bigInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->softDeletesTz();
            $t->index(['status', 'id']);
            $t->index('recipient_email');
        });
        // "used" is the system's word for a card with nothing left: the two always agree.
        DB::statement("ALTER TABLE gift_cards ADD CONSTRAINT gift_card_values CHECK (number ~ '^GC-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$' AND btrim(recipient_name) <> '' AND btrim(sender_name) <> '' AND btrim(recipient_email) <> ''
            AND currency_code ~ '^[A-Z]{3}$' AND currency_decimals BETWEEN 0 AND 6 AND amount > 0 AND balance >= 0 AND balance <= amount
            AND status IN ('pending','active','cancelled','used') AND ((status = 'used') = (balance = 0)) AND lock_version > 0)");

        Schema::create('gift_card_transactions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('gift_card_id')->constrained('gift_cards')->restrictOnDelete();
            $t->string('type', 10);
            $t->decimal('amount', 20, 6);
            $t->decimal('balance_after', 20, 6);
            $t->foreignId('order_id')->nullable()->constrained('orders')->restrictOnDelete();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('created_at');
            $t->index(['gift_card_id', 'id']);
            $t->index('order_id');
        });
        DB::statement("ALTER TABLE gift_card_transactions ADD CONSTRAINT gift_card_transaction_values CHECK (type IN ('issued','redeemed') AND amount > 0 AND balance_after >= 0 AND (type <> 'redeemed' OR order_id IS NOT NULL))");

        // One record per requested email: delivery runs after the change commits, and a failure never undoes it.
        Schema::create('gift_card_emails', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('gift_card_id')->constrained('gift_cards')->restrictOnDelete();
            $t->string('kind', 10);
            $t->string('recipient', 254);
            $t->string('state', 10)->default('pending');
            $t->smallInteger('attempts')->default(0);
            $t->timestampTz('last_attempt_at')->nullable();
            $t->timestampTz('sent_at')->nullable();
            $t->string('last_error', 60)->nullable();
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->index(['gift_card_id', 'id']);
        });
        DB::statement("ALTER TABLE gift_card_emails ADD CONSTRAINT gift_card_email_values CHECK (kind IN ('delivery','cancelled') AND state IN ('pending','sending','sent','failed') AND attempts >= 0)");

        // A gift card used on an order is a captured payment of its own kind, so payment status, reports and the
        // "status after payment" behave exactly as for any other money received.
        Schema::table('order_payments', fn (Blueprint $t) => $t->foreignId('gift_card_id')->nullable()->constrained('gift_cards')->restrictOnDelete());
        $this->paymentConstraint(true);

        foreach (AuditTables::giftCards() as $table => [$entity, $fields]) {
            DB::statement("DROP TRIGGER IF EXISTS cms_audit_mutation ON $table");
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
    }

    private function paymentConstraint(bool $giftCards): void
    {
        DB::statement('ALTER TABLE order_payments DROP CONSTRAINT order_payment_values');
        $kinds = $giftCards ? "'manual','online','gift_card'" : "'manual','online'";
        $extra = $giftCards ? "AND (kind <> 'gift_card' OR (state = 'captured' AND provider_reference IS NULL AND gift_card_id IS NOT NULL)) AND (kind = 'gift_card' OR gift_card_id IS NULL)" : '';
        DB::statement("ALTER TABLE order_payments ADD CONSTRAINT order_payment_values CHECK (
            kind IN ($kinds) AND state IN ('created','processing','pending','captured','failed','cancelled')
            AND amount > 0 AND currency_code ~ '^[A-Z]{3}$'
            AND (kind <> 'manual' OR (state = 'captured' AND provider_reference IS NULL))
            AND (kind <> 'online' OR payment_method_id IS NOT NULL) $extra)");
    }

    public function down(): void
    {
        // Issued balances and the payments made with them cannot be reconstructed by a destructive rollback.
        if (DB::table('gift_cards')->exists()) {
            throw new RuntimeException('Restore a database backup to roll back gift cards after any have been issued.');
        }
        DB::statement('DROP TRIGGER IF EXISTS cms_audit_mutation ON order_payments');
        $this->paymentConstraint(false);
        Schema::table('order_payments', fn (Blueprint $t) => $t->dropConstrainedForeignId('gift_card_id'));
        [$entity, $fields] = AuditTables::payments()['order_payments'];
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON order_payments FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        Schema::dropIfExists('gift_card_emails');
        Schema::dropIfExists('gift_card_transactions');
        Schema::dropIfExists('gift_cards');
    }
};
