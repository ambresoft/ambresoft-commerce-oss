<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('variation_groups', fn (Blueprint $t) => $t->boolean('clickable_colors')->default(false));
    }

    public function down(): void
    {
        Schema::table('variation_groups', fn (Blueprint $t) => $t->dropColumn('clickable_colors'));
    }
};
