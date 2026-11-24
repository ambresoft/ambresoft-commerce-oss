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
        // One row of typed settings. New options are added as columns by later additive migrations.
        Schema::create('general_settings', function (Blueprint $t): void {
            $t->integer('id')->primary();
            // N = do not allow purchase at zero stock, B = allow backorders. Applies to every product without an override.
            $t->string('out_of_stock_action', 1)->default('N');
            $t->bigInteger('lock_version')->default(1);
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('updated_at')->useCurrent();
        });
        DB::statement("ALTER TABLE general_settings ADD CONSTRAINT general_settings_valid CHECK (id = 1 AND lock_version > 0 AND out_of_stock_action IN ('N', 'B'))");
        DB::table('general_settings')->insert(['id' => 1]);

        // A product's own action becomes an optional override: NULL follows the general setting.
        // Rows still holding the Phase 7 default 'N' become NULL: the general setting starts at 'N', so behavior
        // is unchanged, and they now follow the general setting. An explicit 'B' stays an override.
        DB::statement('ALTER TABLE products ALTER COLUMN out_of_stock_actions DROP NOT NULL');
        DB::statement('ALTER TABLE products ALTER COLUMN out_of_stock_actions DROP DEFAULT');
        DB::table('products')->where('out_of_stock_actions', 'N')->update(['out_of_stock_actions' => null]);

        foreach (AuditTables::settings() as $table => [$entity, $fields]) {
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS cms_audit_mutation ON general_settings');
        // Products following the general setting become explicit again, with the value in force now.
        $general = DB::table('general_settings')->where('id', 1)->value('out_of_stock_action') ?? 'N';
        DB::table('products')->whereNull('out_of_stock_actions')->update(['out_of_stock_actions' => $general]);
        DB::statement("ALTER TABLE products ALTER COLUMN out_of_stock_actions SET DEFAULT 'N'");
        DB::statement('ALTER TABLE products ALTER COLUMN out_of_stock_actions SET NOT NULL');
        Schema::dropIfExists('general_settings');
    }
};
