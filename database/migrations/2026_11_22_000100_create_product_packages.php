<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_packages', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('lock_version')->default(1);
            $t->timestampsTz();
        });
        Schema::create('product_package_translations', function (Blueprint $t): void {
            $t->foreignId('package_id')->constrained('product_packages')->cascadeOnDelete();
            $t->foreignId('locale_id')->constrained('locales')->restrictOnDelete();
            $t->string('name', 200);
            $t->primary(['package_id', 'locale_id']);
            $t->index('locale_id');
        });
        Schema::create('product_package_items', function (Blueprint $t): void {
            $t->foreignId('package_id')->constrained('product_packages')->cascadeOnDelete();
            $t->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $t->unsignedInteger('position');
            $t->primary(['package_id', 'product_id']);
            $t->index('product_id');
        });
        Schema::create('product_package_assignments', function (Blueprint $t): void {
            $t->foreignId('product_id')->primary()->constrained('products')->cascadeOnDelete();
            $t->foreignId('package_id')->constrained('product_packages')->cascadeOnDelete();
            $t->index('package_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_package_assignments');
        Schema::dropIfExists('product_package_items');
        Schema::dropIfExists('product_package_translations');
        Schema::dropIfExists('product_packages');
    }
};
