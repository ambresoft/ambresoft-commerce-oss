<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per verified provider event, so redelivery and replay never apply a payment change twice. Only
        // verified events are stored (a forged request leaves no row), with identifiers and an outcome, never the payload.
        Schema::create('payment_webhook_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('payment_method_id')->constrained('payment_methods')->restrictOnDelete();
            $t->string('event_id', 255);
            $t->string('event_type', 100);
            $t->foreignId('payment_id')->nullable()->constrained('order_payments')->nullOnDelete();
            $t->string('outcome', 20);
            $t->timestampTz('received_at');
            $t->unique(['payment_method_id', 'event_id']);
            $t->index('payment_id');
        });
        DB::statement("ALTER TABLE payment_webhook_events ADD CONSTRAINT payment_webhook_event_values CHECK (btrim(event_id) <> '' AND outcome IN ('applied','ignored','unmatched','mismatch','unchanged'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
    }
};
