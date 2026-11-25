<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_addresses', function (Blueprint $table): void {
            $table->string('floor', 20)->nullable();
            $table->string('bell_name', 100)->nullable();
            $table->string('notes', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('order_addresses', fn (Blueprint $table) => $table->dropColumn(['floor', 'bell_name', 'notes']));
    }
};
