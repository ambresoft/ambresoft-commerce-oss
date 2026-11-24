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
        // Whether the customer is emailed when the card becomes active or is cancelled. Saved with the card so the
        // editor and the list always show the same choice.
        Schema::table('gift_cards', fn (Blueprint $t) => $t->boolean('notify')->default(true));
        $this->audit();
    }

    public function down(): void
    {
        Schema::table('gift_cards', fn (Blueprint $t) => $t->dropColumn('notify'));
    }

    private function audit(): void
    {
        [$entity, $fields] = AuditTables::giftCards()['gift_cards'];
        DB::statement('DROP TRIGGER IF EXISTS cms_audit_mutation ON gift_cards');
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON gift_cards FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
    }
};
