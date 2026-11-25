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
        Schema::table('product_sales', function (Blueprint $t): void {
            // Optional clock times in the store's time zone: no start time begins at midnight, no end time runs to the end of the day.
            $t->time('starts_time')->nullable();
            $t->time('ends_time')->nullable();
        });
        DB::statement('ALTER TABLE product_sales DROP CONSTRAINT product_sales_valid');
        DB::statement("ALTER TABLE product_sales ADD CONSTRAINT product_sales_valid CHECK (minimum_quantity BETWEEN 1 AND 1000000 AND priority BETWEEN 0 AND 99999 AND kind IN ('fixed','percentage') AND amount >= 0 AND (kind <> 'percentage' OR amount <= 100) AND (stock_limit IS NULL OR stock_limit >= 0) AND (starts_time IS NULL OR starts_on IS NOT NULL) AND (ends_time IS NULL OR ends_on IS NOT NULL) AND (starts_on IS NULL OR ends_on IS NULL OR starts_on + coalesce(starts_time, time '00:00') <= ends_on + coalesce(ends_time, time '23:59:59')))");
        Schema::create('product_sale_translations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('sale_id')->constrained('product_sales')->cascadeOnDelete();
            $t->foreignId('locale_id')->constrained('locales')->restrictOnDelete();
            $t->string('title', 150);
            $t->unique(['sale_id', 'locale_id']);
            $t->index('locale_id');
        });
        DB::statement("ALTER TABLE product_sale_translations ADD CONSTRAINT product_sale_translations_title CHECK (btrim(title) <> '')");
        $this->audit(AuditTables::productSaleTimesAndTitles());
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS cms_audit_mutation ON product_sale_translations');
        Schema::dropIfExists('product_sale_translations');
        DB::statement('ALTER TABLE product_sales DROP CONSTRAINT product_sales_valid');
        Schema::table('product_sales', fn (Blueprint $t) => $t->dropColumn(['starts_time', 'ends_time']));
        DB::statement("ALTER TABLE product_sales ADD CONSTRAINT product_sales_valid CHECK (minimum_quantity BETWEEN 1 AND 1000000 AND priority BETWEEN 0 AND 99999 AND kind IN ('fixed','percentage') AND amount >= 0 AND (kind <> 'percentage' OR amount <= 100) AND (stock_limit IS NULL OR stock_limit >= 0) AND (starts_on IS NULL OR ends_on IS NULL OR starts_on <= ends_on))");
        $this->audit(['product_sales' => ['product_sale', 'product_id,customer_group_id,minimum_quantity,priority,kind,amount,stock_limit,starts_on,ends_on,is_enabled']]);
    }

    /** @param array<string, array{string, string}> $tables */
    private function audit(array $tables): void
    {
        foreach ($tables as $table => [$entity, $fields]) {
            DB::statement("DROP TRIGGER IF EXISTS cms_audit_mutation ON $table");
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
    }
};
