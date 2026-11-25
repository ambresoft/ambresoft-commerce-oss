<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('variation_groups', function (Blueprint $t): void {
            $t->boolean('view_product')->default(false);
        });
        Schema::table('variation_group_features', function (Blueprint $t): void {
            $t->boolean('show_on_card')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('variation_group_features', fn (Blueprint $t) => $t->dropColumn('show_on_card'));
        Schema::table('variation_groups', fn (Blueprint $t) => $t->dropColumn('view_product'));
    }
};
