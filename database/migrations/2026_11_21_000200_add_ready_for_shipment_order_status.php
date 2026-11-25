<?php

use App\Order\Infrastructure\OrderEmailDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('order_statuses')->where('code', 'ready_for_shipment')->exists()) {
            return;
        }
        OrderEmailDefaults::seedStatuses();
        $ready = DB::table('order_statuses')->where('code', 'ready_for_shipment')->value('id');
        $ordered = DB::table('order_statuses')->where('code', '<>', 'ready_for_shipment')->orderBy('position')->orderBy('id')->get(['id', 'code']);
        $ids = [];
        foreach ($ordered as $status) {
            if ($status->code === 'shipped') {
                $ids[] = $ready;
            }
            $ids[] = $status->id;
        }
        foreach ($ids as $index => $id) {
            DB::table('order_statuses')->where('id', $id)->where('position', '<>', ($index + 1) * 10)
                ->update(['position' => ($index + 1) * 10, 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        $id = DB::table('order_statuses')->where('code', 'ready_for_shipment')->value('id');
        // Retain statuses referenced by orders or history, just like the Shipped upgrade.
        if ($id !== null && ! DB::table('orders')->where('status_id', $id)->exists() && ! DB::table('order_status_history')->where('to_status_id', $id)->orWhere('from_status_id', $id)->exists()) {
            DB::table('order_statuses')->where('id', $id)->delete();
        }
    }
};
