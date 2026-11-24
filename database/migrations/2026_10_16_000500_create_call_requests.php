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
        $this->privateAuditLabels(true);
        Schema::create('call_requests', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 200)->nullable();
            $t->string('phone', 40);
            $t->string('phone_digits', 40);
            $t->string('convenient_time', 200)->nullable();
            $t->text('notes')->nullable();
            $t->string('status', 16)->default('new');
            $t->foreignId('responsible_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $t->bigInteger('order_number')->nullable();
            $t->bigInteger('lock_version')->default(1);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->index(['created_at', 'id']);
            $t->index(['status', 'created_at', 'id']);
            $t->index(['responsible_id', 'created_at', 'id']);
            $t->index('order_id');
            $t->index('created_by');
            $t->index('updated_by');
        });
        DB::statement("ALTER TABLE call_requests ADD CONSTRAINT call_requests_valid CHECK (status IN ('new','in_progress','completed','no_answer') AND lock_version > 0 AND btrim(phone) <> '' AND phone_digits ~ '^[0-9]{1,40}$' AND (name IS NULL OR btrim(name) <> '') AND (order_number IS NULL OR order_number > 0) AND (notes IS NULL OR length(notes) <= 10000))");
        Schema::create('call_request_products', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('call_request_id')->constrained('call_requests')->cascadeOnDelete();
            $t->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $t->string('name', 250);
            $t->string('sku', 200)->nullable();
            $t->integer('quantity');
            $t->integer('position');
            $t->unique(['call_request_id', 'position']);
            $t->unique(['call_request_id', 'product_id']);
            $t->index('product_id');
        });
        DB::statement("ALTER TABLE call_request_products ADD CONSTRAINT call_request_products_valid CHECK (quantity BETWEEN 1 AND 1000000 AND position BETWEEN 1 AND 100 AND btrim(name) <> '')");
        foreach (AuditTables::callRequests() as $table => [$entity, $fields]) {
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields', 'private')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('call_request_products');
        Schema::dropIfExists('call_requests');
        $this->privateAuditLabels(false);
    }

    private function privateAuditLabels(bool $install): void
    {
        // An opt-in argument suppresses automatic name/SKU labels for private contact records.
        // Preserve the existing transactional audit implementation and every other table's behavior.
        $definition = DB::selectOne("SELECT pg_get_functiondef('cms_capture_audit()'::regprocedure) AS definition")->definition;
        $label = "left(regexp_replace(coalesce(row_value->>'name',row_value->>'label',row_value->>'sku',row_value->>'code'),'[[:cntrl:]]','','g'),254)";
        $private = "CASE WHEN TG_NARGS > 2 AND TG_ARGV[2] = 'private' THEN NULL ELSE $label END";
        $updated = $install ? str_replace($label, $private, $definition) : str_replace($private, $label, $definition);
        if ($updated === $definition) {
            throw new RuntimeException('Unexpected transactional audit label implementation.');
        }
        DB::unprepared($updated);
    }
};
