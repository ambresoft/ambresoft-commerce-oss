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
        // One optional image per payment and shipping method (e.g. a carrier or provider logo for the storefront).
        foreach (['payment_methods', 'shipping_methods'] as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->foreignId('image_media_id')->nullable()->unique()->constrained('media_files')->restrictOnDelete();
            });
        }
        $this->trigger(AuditTables::methodImages());
    }

    public function down(): void
    {
        $this->trigger(['payment_methods' => AuditTables::payments()['payment_methods'], 'shipping_methods' => AuditTables::shipping()['shipping_methods']]);
        foreach (['payment_methods', 'shipping_methods'] as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->dropConstrainedForeignId('image_media_id');
            });
        }
    }

    /** @param array<string, array{string, string}> $tables */
    private function trigger(array $tables): void
    {
        foreach ($tables as $table => [$entity, $fields]) {
            DB::statement("DROP TRIGGER IF EXISTS cms_audit_mutation ON $table");
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
    }
};
