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
        // A translated short description beside the full description (sanitized rich text, optional).
        Schema::table('product_translations', fn (Blueprint $t) => $t->text('short_description')->default(''));
        DB::statement('ALTER TABLE product_translations ADD CONSTRAINT product_short_description_length CHECK (length(short_description) <= 5000)');
        // Automatic similar products are computed live; the merchant only switches them off per product.
        Schema::table('products', fn (Blueprint $t) => $t->boolean('similar_products')->default(true));
        // Frequently bought together: up to three manually chosen companions per product, in order.
        Schema::create('product_companions', function (Blueprint $t): void {
            $t->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $t->foreignId('companion_id')->constrained('products')->cascadeOnDelete();
            $t->smallInteger('position');
            $t->primary(['product_id', 'companion_id']);
            $t->unique(['product_id', 'position']);
            $t->index('companion_id');
        });
        DB::statement('ALTER TABLE product_companions ADD CONSTRAINT product_companion_values CHECK (product_id <> companion_id AND position BETWEEN 1 AND 3)');
        [$entity, $fields] = AuditTables::productCompanions()['product_companions'];
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON product_companions FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        $this->audit('products', fn (string $f): string => $f.',similar_products');
        $this->audit('product_translations', fn (string $f): string => $f.',short_description');
    }

    public function down(): void
    {
        $this->audit('product_translations', fn (string $f): string => implode(',', array_diff(explode(',', $f), ['short_description'])));
        $this->audit('products', fn (string $f): string => implode(',', array_diff(explode(',', $f), ['similar_products'])));
        Schema::dropIfExists('product_companions');
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('similar_products'));
        DB::statement('ALTER TABLE product_translations DROP CONSTRAINT product_short_description_length');
        Schema::table('product_translations', fn (Blueprint $t) => $t->dropColumn('short_description'));
    }

    /** Extends (or shrinks) the field list that earlier migrations left on a table's audit trigger. */
    private function audit(string $table, callable $fields): void
    {
        $definition = DB::selectOne("SELECT pg_get_triggerdef(oid) AS def FROM pg_trigger WHERE tgname = 'cms_audit_mutation' AND tgrelid = '$table'::regclass")?->def;
        if ($definition !== null && preg_match("/cms_capture_audit\('([^']+)', '([^']+)'\)/", $definition, $m)) {
            DB::statement("DROP TRIGGER cms_audit_mutation ON $table");
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('{$m[1]}', '{$fields($m[2])}')");
        }
    }
};
