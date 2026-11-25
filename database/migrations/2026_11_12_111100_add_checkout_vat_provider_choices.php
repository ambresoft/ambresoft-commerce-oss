<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_settings', function (Blueprint $t): void {
            $t->string('greek_provider', 16)->default('vies');
            $t->string('international_provider', 16)->default('vies');
        });
        DB::statement("ALTER TABLE checkout_settings ADD CONSTRAINT checkout_provider_choices CHECK (greek_provider IN ('aade', 'vies', 'local') AND international_provider IN ('vies', 'vatsense'))");
        DB::statement('DROP TRIGGER cms_audit_mutation ON checkout_settings');
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON checkout_settings FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('checkout_settings', 'id,enabled,greek_provider,international_provider,lock_version')");
        DB::statement('ALTER TABLE order_invoice_verifications DROP CONSTRAINT order_invoice_verification_values');
        DB::statement("ALTER TABLE order_invoice_verifications ADD CONSTRAINT order_invoice_verification_values CHECK (country_code ~ '^[A-Z]{2}$' AND vat_number ~ '^[A-Z0-9]{2,32}$' AND provider IN ('aade', 'vatsense', 'vies', 'local'))");
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER cms_audit_mutation ON checkout_settings');
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON checkout_settings FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('checkout_settings', 'id,enabled,lock_version')");
        // Keep existing VIES/local order evidence when rolling back the provider-selection UI.
        DB::statement('ALTER TABLE checkout_settings DROP CONSTRAINT checkout_provider_choices');
        Schema::table('checkout_settings', fn (Blueprint $t) => $t->dropColumn(['greek_provider', 'international_provider']));
    }
};
