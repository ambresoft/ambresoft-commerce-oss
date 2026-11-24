<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_notification_inboxes', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->bigInteger('revision')->default(0);
            $table->bigInteger('created_revision')->default(0);
        });
        Schema::create('admin_notifications', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 80);
            // Historical identity survives removal of the related business record.
            $table->string('resource_id', 40);
            $table->string('resource_number', 80);
            $table->timestampTz('created_at');
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('deleted_at')->nullable();
            $table->unique(['user_id', 'type', 'resource_id']);
            $table->index(['user_id', 'deleted_at', 'id']);
            $table->index(['user_id', 'deleted_at', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_notifications');
        Schema::dropIfExists('admin_notification_inboxes');
    }
};
