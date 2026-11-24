<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->boolean('age_verification')->default(false);
            $table->integer('age_limit')->nullable();
        });
        DB::statement('ALTER TABLE categories ADD CONSTRAINT categories_age_limit_check CHECK (age_limit IS NULL OR age_limit >= 1), ADD CONSTRAINT categories_age_verification_check CHECK (NOT age_verification OR age_limit IS NOT NULL), ADD CONSTRAINT categories_system_age_check CHECK (system_key IS NULL OR (NOT age_verification AND age_limit IS NULL))');
        Schema::table('category_translations', function (Blueprint $table): void {
            $table->string('meta_keywords', 1000)->default('');
            $table->text('age_warning_message')->default('');
        });
        DB::statement('ALTER TABLE category_translations ADD CONSTRAINT category_age_warning_length_check CHECK (length(age_warning_message) <= 2000)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE category_translations DROP CONSTRAINT category_age_warning_length_check');
        Schema::table('category_translations', fn (Blueprint $table) => $table->dropColumn(['meta_keywords', 'age_warning_message']));
        DB::statement('ALTER TABLE categories DROP CONSTRAINT categories_age_limit_check, DROP CONSTRAINT categories_age_verification_check, DROP CONSTRAINT categories_system_age_check');
        Schema::table('categories', fn (Blueprint $table) => $table->dropColumn(['age_verification', 'age_limit']));
    }
};
