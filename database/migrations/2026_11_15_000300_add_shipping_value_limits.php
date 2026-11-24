<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A courier may refuse shipments worth more than an amount (insurance, cash collected on delivery). The value is
        // the goods after discounts with taxes, without shipping (ShipmentValue), in the primary currency. Switched off
        // (the default) or empty means no limit; the amount is kept while switched off.
        Schema::table('shipping_methods', function (Blueprint $t): void {
            $t->boolean('value_limit_enabled')->default(false);
            $t->decimal('max_shipment_value', 20, 6)->nullable();
        });
        DB::statement('ALTER TABLE shipping_methods ADD CONSTRAINT shipping_method_max_shipment_value CHECK (max_shipment_value IS NULL OR max_shipment_value >= 0)');
        $this->audit(fn (string $fields): string => $fields.',value_limit_enabled,max_shipment_value');
    }

    public function down(): void
    {
        $this->audit(fn (string $fields): string => implode(',', array_diff(explode(',', $fields), ['value_limit_enabled', 'max_shipment_value'])));
        DB::statement('ALTER TABLE shipping_methods DROP CONSTRAINT shipping_method_max_shipment_value');
        Schema::table('shipping_methods', fn (Blueprint $t) => $t->dropColumn(['value_limit_enabled', 'max_shipment_value']));
    }

    /** Extends (or shrinks) the field list that earlier additive migrations left on the table's audit trigger. */
    private function audit(callable $fields): void
    {
        $definition = DB::selectOne("SELECT pg_get_triggerdef(oid) AS def FROM pg_trigger WHERE tgname = 'cms_audit_mutation' AND tgrelid = 'shipping_methods'::regclass")->def;
        if (preg_match("/cms_capture_audit\('([^']+)', '([^']+)'\)/", $definition, $m)) {
            DB::statement('DROP TRIGGER cms_audit_mutation ON shipping_methods');
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON shipping_methods FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('{$m[1]}', '{$fields($m[2])}')");
        }
    }
};
