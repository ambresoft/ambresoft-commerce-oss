<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['es' => ['Spanish', 'Español'], 'fr' => ['French', 'Français'], 'it' => ['Italian', 'Italiano'], 'nl' => ['Dutch', 'Nederlands']] as $code => $names) {
            DB::table('locales')->insertOrIgnore(['code' => $code, 'name' => $names[0], 'native_name' => $names[1], 'is_active' => false, 'is_default' => false, 'direction' => 'ltr', 'sort_order' => 20 + array_search($code, ['es', 'fr', 'it', 'nl']) * 10, 'created_at' => now(), 'updated_at' => now()]);
        }
        Schema::create('ui_translation_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('locale_id')->constrained('locales')->restrictOnDelete();
            $table->string('namespace', 100)->default('*');
            $table->string('group', 100);
            $table->string('key', 200);
            $table->text('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['locale_id', 'namespace', 'group', 'key'], 'ui_translation_override_identity');
            $table->index('updated_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ui_translation_overrides');
        // Retain required locale rows: existing preferences/content may reference them.
    }
};
