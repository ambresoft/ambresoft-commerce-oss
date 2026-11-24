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
        // A customer who deletes their own account is kept, not erased: orders, addresses, reviews and lists stay as they
        // were. A deleted profile is always disabled, so nothing treats it as an active customer.
        Schema::table('customers', fn (Blueprint $t) => $t->timestampTz('deleted_at')->nullable());
        DB::statement("ALTER TABLE customers ADD CONSTRAINT customer_deleted_is_disabled CHECK (deleted_at IS NULL OR status = 'disabled')");
        $this->audit(AuditTables::customerDeletion()['customers'][1]);

        // The street number and where and how to deliver. Personal data: kept out of the audit ledger like the rest of the address.
        Schema::table('customer_addresses', function (Blueprint $t): void {
            $t->string('street_number', 20)->nullable();
            $t->string('floor', 20)->nullable();
            $t->string('bell_name', 100)->nullable();
            $t->string('notes', 500)->nullable();
        });
        // Addresses need a postal code. Older rows without one stay readable; the rule applies when they are saved.
        DB::statement("ALTER TABLE customer_addresses ADD CONSTRAINT customer_address_postal_code CHECK (postal_code IS NOT NULL AND btrim(postal_code) <> '') NOT VALID");
        DB::statement("ALTER TABLE order_addresses ADD CONSTRAINT order_address_postal_code CHECK (postal_code IS NOT NULL AND btrim(postal_code) <> '') NOT VALID");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE order_addresses DROP CONSTRAINT IF EXISTS order_address_postal_code');
        DB::statement('ALTER TABLE customer_addresses DROP CONSTRAINT IF EXISTS customer_address_postal_code');
        Schema::table('customer_addresses', fn (Blueprint $t) => $t->dropColumn(['street_number', 'floor', 'bell_name', 'notes']));
        $this->audit(AuditTables::customerAccounts()['customers'][1]);
        DB::statement('ALTER TABLE customers DROP CONSTRAINT IF EXISTS customer_deleted_is_disabled');
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn('deleted_at'));
    }

    private function audit(string $fields): void
    {
        DB::statement('DROP TRIGGER IF EXISTS cms_audit_mutation ON customers');
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON customers FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('customer', '$fields')");
    }
};
