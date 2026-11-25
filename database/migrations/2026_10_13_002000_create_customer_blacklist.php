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
        // Emails and phones that must not place orders. Entries are released, not deleted, so the history and
        // the reason stay available. Matching is by normalized value, independent of any customer record.
        Schema::create('customer_blacklist_entries', function (Blueprint $t): void {
            $t->id();
            $t->string('kind', 5);
            $t->string('value', 254);
            $t->text('reason');
            // The customer it was created from, when it was. Deleting that record never lifts the block.
            $t->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->timestampTz('released_at')->nullable();
            $t->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $t->index('value');
            $t->index('customer_id');
        });
        DB::statement("ALTER TABLE customer_blacklist_entries ADD CONSTRAINT blacklist_entry_values CHECK (btrim(reason) <> '' AND ((kind = 'email' AND value = lower(btrim(value)) AND value LIKE '%@%') OR (kind = 'phone' AND value ~ '^[0-9]{5,15}$')) AND ((released_at IS NULL) = (released_by IS NULL OR released_at IS NULL)))");
        // At most one active entry per value; a released one may be added again later.
        DB::statement('CREATE UNIQUE INDEX blacklist_active_value ON customer_blacklist_entries (kind, value) WHERE released_at IS NULL');
        foreach (AuditTables::customerBlacklist() as $table => [$entity, $fields]) {
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_blacklist_entries');
    }
};
