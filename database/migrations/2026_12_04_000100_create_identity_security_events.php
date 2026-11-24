<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_security_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 24);
            $table->string('area', 16);
            $table->ipAddress('ip')->nullable();
            $table->string('device_key', 64);
            $table->string('browser', 100);
            $table->string('platform', 100);
            $table->string('user_agent', 512);
            $table->string('country', 2)->nullable();
            $table->string('city', 150)->nullable();
            $table->string('timezone', 100)->nullable();
            $table->boolean('new_device')->default(false);
            $table->boolean('new_ip')->default(false);
            $table->timestampTz('occurred_at');
            $table->index(['user_id', 'occurred_at', 'id']);
            $table->index(['user_id', 'device_key', 'kind']);
            $table->index(['user_id', 'ip', 'kind']);
            $table->index(['kind', 'occurred_at']);
        });
        DB::statement("ALTER TABLE identity_security_events ADD CONSTRAINT security_event_kind CHECK (kind IN ('registration', 'login', 'password_failed'))");
        DB::statement("ALTER TABLE identity_security_events ADD CONSTRAINT security_event_area CHECK (area IN ('admin', 'customer'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_security_events');
    }
};
