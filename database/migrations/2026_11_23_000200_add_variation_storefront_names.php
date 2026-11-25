<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variation_group_translations', function (Blueprint $t): void {
            $t->foreignId('group_id')->constrained('variation_groups')->cascadeOnDelete();
            $t->foreignId('locale_id')->constrained('locales')->restrictOnDelete();
            $t->string('name', 200)->default('');
            $t->primary(['group_id', 'locale_id']);
            $t->index('locale_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variation_group_translations');
    }
};
