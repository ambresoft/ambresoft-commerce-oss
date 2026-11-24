<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variation_card_feature_translations', function (Blueprint $t): void {
            $t->unsignedBigInteger('group_id');
            $t->unsignedBigInteger('feature_id');
            $t->foreignId('locale_id')->constrained('locales')->cascadeOnDelete();
            $t->string('name', 100);
            $t->primary(['group_id', 'feature_id', 'locale_id'], 'variation_card_feature_translations_pk');
            $t->foreign(['group_id', 'feature_id'])->references(['group_id', 'feature_id'])->on('variation_group_features')->cascadeOnDelete();
            $t->index('locale_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variation_card_feature_translations');
    }
};
