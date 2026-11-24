<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['categories', 'products'] as $table) {
            Schema::table($table, fn (Blueprint $table) => $table->boolean('exclude_from_sitemap')->default(false));
        }
    }

    public function down(): void
    {
        foreach (['categories', 'products'] as $table) {
            Schema::table($table, fn (Blueprint $table) => $table->dropColumn('exclude_from_sitemap'));
        }
    }
};
