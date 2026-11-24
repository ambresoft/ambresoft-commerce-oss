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
        // How order emails leave the server (CS-Cart-like): PHP mail(), an SMTP server or the sendmail program.
        Schema::table('general_settings', function (Blueprint $t): void {
            $t->string('mail_method', 10)->default('php_mail');
            $t->string('smtp_host', 255)->nullable();
            $t->integer('smtp_port')->nullable();
            $t->string('smtp_encryption', 4)->default('none');
            $t->boolean('smtp_auth')->default(false);
            $t->string('smtp_username', 255)->nullable();
            // Encrypted with the application key; never returned to the browser or written to the audit ledger.
            $t->text('smtp_password')->nullable();
            $t->string('sendmail_path', 255)->default('/usr/sbin/sendmail');
        });
        DB::statement("ALTER TABLE general_settings ADD CONSTRAINT general_settings_mail_delivery CHECK (
            mail_method IN ('php_mail','smtp','sendmail') AND smtp_encryption IN ('none','tls','ssl')
            AND (smtp_port IS NULL OR smtp_port BETWEEN 1 AND 65535)
            AND (smtp_host IS NULL OR smtp_host ~ '^[A-Za-z0-9.:\\[\\]-]{1,255}$')
            AND sendmail_path ~ '^/[A-Za-z0-9._/-]{1,254}$'
            AND (mail_method <> 'smtp' OR smtp_host IS NOT NULL))");
        $this->capture(AuditTables::mailDelivery()['general_settings']);
    }

    public function down(): void
    {
        $this->capture(AuditTables::orderEmailSender()['general_settings']);
        DB::statement('ALTER TABLE general_settings DROP CONSTRAINT general_settings_mail_delivery');
        Schema::table('general_settings', fn (Blueprint $t) => $t->dropColumn(['mail_method', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_auth', 'smtp_username', 'smtp_password', 'sendmail_path']));
    }

    /** @param array{string, string} $definition */
    private function capture(array $definition): void
    {
        [$entity, $fields] = $definition;
        DB::statement('DROP TRIGGER IF EXISTS cms_audit_mutation ON general_settings');
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON general_settings FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
    }
};
