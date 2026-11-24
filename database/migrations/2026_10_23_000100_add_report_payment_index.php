<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Reports find the orders paid within a period from the captured payments completed in it. Only captured
        // payments matter to them, so the index is partial: it stays small and every report window uses it.
        DB::statement("CREATE INDEX order_payment_captured_at ON order_payments (completed_at, order_id) WHERE state = 'captured'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS order_payment_captured_at');
    }
};
