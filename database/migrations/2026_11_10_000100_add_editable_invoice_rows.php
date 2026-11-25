<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_email_layouts', function (Blueprint $t): void {
            $t->text('invoice_item_html')->nullable();
            $t->text('invoice_total_html')->nullable();
        });
        DB::statement('ALTER TABLE order_email_layouts ADD CONSTRAINT invoice_rows_length CHECK (length(invoice_item_html) <= 100000 AND length(invoice_total_html) <= 100000)');
        $this->trigger(true);
    }

    public function down(): void
    {
        $this->trigger(false);
        DB::statement('ALTER TABLE order_email_layouts DROP CONSTRAINT invoice_rows_length');
        Schema::table('order_email_layouts', fn (Blueprint $t) => $t->dropColumn(['invoice_item_html', 'invoice_total_html']));
    }

    private function trigger(bool $extended): void
    {
        $fields = 'locale_id,header_html,footer_html,invoice_subject,invoice_body_html'.($extended ? ',invoice_item_html,invoice_total_html' : '');
        DB::statement('DROP TRIGGER IF EXISTS cms_audit_mutation ON order_email_layouts');
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON order_email_layouts FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('order_email_layout', '$fields')");
    }
};
