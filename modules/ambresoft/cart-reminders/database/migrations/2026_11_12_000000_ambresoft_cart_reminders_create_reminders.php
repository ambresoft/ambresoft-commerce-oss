<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Reminders sent for a cart since it last changed; a cart changed after them starts a new series.
        Schema::create('ambresoft_cart_reminders', function (Blueprint $t): void {
            $t->foreignId('cart_id')->primary()->constrained('carts')->cascadeOnDelete();
            $t->integer('sent');
            $t->timestampTz('last_sent_at', 6);
            $t->timestampTz('cart_updated_at', 6);
        });
        DB::statement('ALTER TABLE ambresoft_cart_reminders ADD CONSTRAINT ambresoft_cart_reminders_sent CHECK (sent > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ambresoft_cart_reminders');
    }
};
