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
        Schema::create('product_reviews', function (Blueprint $t): void {
            $t->id();
            // The exact reviewed product record (a variation is its own product); physical deletion removes its reviews, trash keeps them.
            $t->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            // Reviews are the customer's own content and go with the customer record if it is ever erased.
            $t->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $t->smallInteger('rating');
            $t->string('title', 150)->nullable();
            $t->text('body');
            $t->string('status', 10)->default('pending');
            // Calculated by the server from paid, non-void orders; never accepted from a client.
            $t->boolean('verified_purchase')->default(false);
            // Internal staff note; never part of a public projection.
            $t->text('moderation_note')->nullable();
            $t->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('moderated_at')->nullable();
            // Last staff correction of rating, title or text.
            $t->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('edited_at')->nullable();
            $t->bigInteger('lock_version')->default(1);
            $t->timestampsTz();
            // One review per customer and product; also serves product lookups.
            $t->unique(['product_id', 'customer_id']);
            $t->index('customer_id');
            $t->index('moderated_by');
            $t->index('edited_by');
            $t->index(['created_at', 'id']);
            $t->index(['status', 'created_at', 'id']);
        });
        DB::statement("ALTER TABLE product_reviews ADD CONSTRAINT product_review_values CHECK (rating BETWEEN 1 AND 5 AND status IN ('pending','approved','rejected','spam') AND btrim(body) <> '' AND length(body) <= 5000 AND (title IS NULL OR (btrim(title) <> '' AND length(title) <= 150)) AND (moderation_note IS NULL OR (btrim(moderation_note) <> '' AND length(moderation_note) <= 2000)) AND (status = 'pending' OR moderated_at IS NOT NULL) AND lock_version > 0)");
        // Public reads (rating summaries and approved lists) only ever touch approved rows.
        DB::statement("CREATE INDEX product_reviews_approved ON product_reviews (product_id, created_at DESC, id DESC) INCLUDE (rating) WHERE status = 'approved'");
        foreach (AuditTables::reviews() as $table => [$entity, $fields]) {
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_reviews');
    }
};
