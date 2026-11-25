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
        // Entering a status with this flag emails the invoice (once per order). Completed sends it by default.
        Schema::table('order_statuses', function (Blueprint $t): void {
            $t->boolean('sends_invoice')->default(false);
        });
        DB::table('order_statuses')->where('code', 'completed')->update(['sends_invoice' => true]);

        // A transition can now produce two emails: the status email and the invoice.
        Schema::table('order_notifications', function (Blueprint $t): void {
            $t->dropUnique(['history_id']);
            $t->string('kind', 10)->default('status');
            $t->unique(['history_id', 'kind']);
        });
        DB::statement("ALTER TABLE order_notifications ADD CONSTRAINT order_notification_kind CHECK (kind IN ('status','invoice'))");

        // The invoice shows each tax's registration number as it was when the order was priced.
        Schema::table('order_taxes', function (Blueprint $t): void {
            $t->string('registration_number', 100)->nullable();
        });
        DB::statement('UPDATE order_taxes ot SET registration_number = t.registration_number FROM taxes t WHERE t.id = ot.tax_id');

        // Per language: the header and footer wrapped around every order email, and the invoice email.
        Schema::create('order_email_layouts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('locale_id')->unique()->constrained('locales')->restrictOnDelete();
            $t->text('header_html');
            $t->text('footer_html');
            $t->string('invoice_subject', 250);
            $t->text('invoice_body_html');
            $t->bigInteger('lock_version')->default(1);
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE order_email_layouts ADD CONSTRAINT order_email_layout_values CHECK (btrim(invoice_subject) <> '' AND length(header_html) <= 100000 AND length(footer_html) <= 100000 AND length(invoice_body_html) <= 100000 AND lock_version > 0)");
        OrderEmailDefaults::seedLayouts();
        // Templates still holding the earlier generated text move to the new designed default; edited ones are kept.
        OrderEmailDefaults::upgradeUnchangedBodies();

        foreach (AuditTables::orderEmailLayouts() as $table => [$entity, $fields]) {
            DB::statement("DROP TRIGGER IF EXISTS cms_audit_mutation ON $table");
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
    }

    public function down(): void
    {
        [$entity, $fields] = AuditTables::orders()['order_statuses'];
        DB::statement('DROP TRIGGER IF EXISTS cms_audit_mutation ON order_statuses');
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON order_statuses FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        Schema::dropIfExists('order_email_layouts');
        Schema::table('order_taxes', fn (Blueprint $t) => $t->dropColumn('registration_number'));
        // Rollback keeps one email per transition: invoice emails are removed first.
        DB::table('order_notifications')->where('kind', 'invoice')->delete();
        DB::statement('ALTER TABLE order_notifications DROP CONSTRAINT order_notification_kind');
        Schema::table('order_notifications', function (Blueprint $t): void {
            $t->dropUnique(['history_id', 'kind']);
            $t->dropColumn('kind');
            $t->unique('history_id');
        });
        Schema::table('order_statuses', fn (Blueprint $t) => $t->dropColumn('sends_invoice'));
    }
};
