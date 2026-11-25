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
        // Who order emails come from. Separate from the Company contact addresses, which never affect delivery.
        Schema::table('general_settings', function (Blueprint $t): void {
            $t->string('order_email_from', 254)->nullable();
            $t->string('order_email_from_name', 200)->nullable();
            $t->string('order_email_reply_to', 254)->nullable();
        });
        DB::statement("ALTER TABLE general_settings ADD CONSTRAINT general_settings_order_email CHECK (
            (order_email_from IS NULL OR (order_email_from = lower(btrim(order_email_from)) AND order_email_from ~ '^[^@\\s]+@[^@\\s]+$'))
            AND (order_email_reply_to IS NULL OR (order_email_reply_to = lower(btrim(order_email_reply_to)) AND order_email_reply_to ~ '^[^@\\s]+@[^@\\s]+$'))
            AND (order_email_from_name IS NULL OR order_email_from_name !~ '[\\r\\n]'))");
        $this->capture(AuditTables::orderEmailSender()['general_settings']);
    }

    public function down(): void
    {
        $this->capture(AuditTables::generalOptions()['general_settings']);
        DB::statement('ALTER TABLE general_settings DROP CONSTRAINT general_settings_order_email');
        Schema::table('general_settings', fn (Blueprint $t) => $t->dropColumn(['order_email_from', 'order_email_from_name', 'order_email_reply_to']));
    }

    /** @param array{string, string} $definition */
    private function capture(array $definition): void
    {
        [$entity, $fields] = $definition;
        DB::statement('DROP TRIGGER IF EXISTS cms_audit_mutation ON general_settings');
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON general_settings FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
    }
};
