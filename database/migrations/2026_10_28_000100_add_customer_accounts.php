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
        // Whether the current password meets the staff minimum (15 characters). Administration requires it, so a
        // customer password (8+) never protects an identity that is later granted staff access. Every existing
        // password was set under the staff rule.
        Schema::table('users', fn (Blueprint $t) => $t->boolean('staff_password')->default(true));

        // A21: the optional one-to-one link between a sign-in identity and its customer profile.
        Schema::table('customers', function (Blueprint $t): void {
            $t->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $t->timestampTz('account_linked_at')->nullable();
        });
        DB::statement('ALTER TABLE customers ADD CONSTRAINT customer_account_link CHECK ((user_id IS NULL) = (account_linked_at IS NULL))');

        // Names given at customer registration, kept until the e-mail is verified and the profile is claimed or created.
        Schema::create('customer_registrations', function (Blueprint $t): void {
            $t->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $t->string('first_name', 100);
            $t->string('last_name', 100);
            $t->timestampTz('created_at');
        });
        DB::statement("ALTER TABLE customer_registrations ADD CONSTRAINT customer_registration_names CHECK (btrim(first_name) <> '' AND btrim(last_name) <> '')");

        $this->audit(AuditTables::customerAccounts()['customers'][1]);
    }

    public function down(): void
    {
        $this->audit(AuditTables::customers()['customers'][1]);
        Schema::dropIfExists('customer_registrations');
        DB::statement('ALTER TABLE customers DROP CONSTRAINT IF EXISTS customer_account_link');
        Schema::table('customers', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('user_id');
            $t->dropColumn('account_linked_at');
        });
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('staff_password'));
    }

    private function audit(string $fields): void
    {
        DB::statement('DROP TRIGGER IF EXISTS cms_audit_mutation ON customers');
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON customers FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('customer', '$fields')");
    }
};
