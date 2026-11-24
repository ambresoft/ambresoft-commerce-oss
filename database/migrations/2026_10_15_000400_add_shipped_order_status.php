<?php

use App\Order\Infrastructure\OrderEmailDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Adds the Shipped reference status (with its email in every language) after Processing; existing statuses are kept. */
    public function up(): void
    {
        if (DB::table('order_statuses')->where('code', 'shipped')->exists()) {
            return;
        }
        OrderEmailDefaults::seedStatuses();
        $after = DB::table('order_statuses')->where('code', 'processing')->value('position');
        if ($after !== null) {
            DB::table('order_statuses')->where('code', 'shipped')->update(['position' => (int) $after + 5]);
        }
    }

    public function down(): void
    {
        $id = DB::table('order_statuses')->where('code', 'shipped')->value('id');
        // Kept once orders or their history use it.
        if ($id !== null && ! DB::table('orders')->where('status_id', $id)->exists() && ! DB::table('order_status_history')->where('to_status_id', $id)->orWhere('from_status_id', $id)->exists()) {
            DB::table('order_statuses')->where('id', $id)->delete();
        }
    }
};
