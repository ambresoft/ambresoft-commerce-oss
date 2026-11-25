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
        Schema::table('general_settings', function (Blueprint $t): void {
            $t->integer('bestsellers_days')->default(0);
            $t->integer('bestsellers_min_units')->default(1);
            $t->integer('bestsellers_min_orders')->default(1);
        });
        DB::statement('ALTER TABLE general_settings ADD CONSTRAINT bestseller_settings_valid CHECK (bestsellers_days BETWEEN 0 AND 3650 AND bestsellers_min_units BETWEEN 1 AND 1000000 AND bestsellers_min_orders BETWEEN 1 AND 1000000)');
        Schema::create('bestseller_excluded_categories', function (Blueprint $t): void {
            $t->foreignId('category_id')->primary()->constrained('categories')->cascadeOnDelete();
        });
        foreach (AuditTables::bestsellers() as $table => [$entity, $fields]) {
            $this->trigger($table, $entity, $fields);
        }
    }

    public function down(): void
    {
        $this->trigger('general_settings', 'general_settings', AuditTables::reels()['general_settings'][1]);
        Schema::dropIfExists('bestseller_excluded_categories');
        DB::statement('ALTER TABLE general_settings DROP CONSTRAINT bestseller_settings_valid');
        Schema::table('general_settings', fn (Blueprint $t) => $t->dropColumn(['bestsellers_days', 'bestsellers_min_units', 'bestsellers_min_orders']));
    }

    private function trigger(string $table, string $entity, string $fields): void
    {
        DB::statement("DROP TRIGGER IF EXISTS cms_audit_mutation ON $table");
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
    }
};
