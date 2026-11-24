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
        // The printable packing slip per language (Orders → Files). Null uses the built-in layout, so it follows CMS updates
        // until a merchant edits it. It is never e-mailed.
        Schema::table('order_email_layouts', function (Blueprint $t): void {
            $t->text('packing_slip_body_html')->nullable();
            $t->text('packing_slip_item_html')->nullable();
        });
        DB::statement('ALTER TABLE order_email_layouts ADD CONSTRAINT packing_slip_length CHECK (length(packing_slip_body_html) <= 100000 AND length(packing_slip_item_html) <= 100000)');
        $this->trigger(AuditTables::packingSlips()['order_email_layouts'][1]);
    }

    public function down(): void
    {
        $this->trigger('locale_id,header_html,footer_html,invoice_subject,invoice_body_html,invoice_item_html,invoice_total_html');
        DB::statement('ALTER TABLE order_email_layouts DROP CONSTRAINT packing_slip_length');
        Schema::table('order_email_layouts', fn (Blueprint $t) => $t->dropColumn(['packing_slip_body_html', 'packing_slip_item_html']));
    }

    private function trigger(string $fields): void
    {
        DB::statement('DROP TRIGGER IF EXISTS cms_audit_mutation ON order_email_layouts');
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON order_email_layouts FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('order_email_layout', '$fields')");
    }
};
