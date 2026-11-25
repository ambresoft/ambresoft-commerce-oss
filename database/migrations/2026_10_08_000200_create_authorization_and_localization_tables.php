<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locales', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 35)->unique();
            $table->string('name', 80);
            $table->string('native_name', 80);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->string('direction', 3)->default('ltr');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE locales ADD CONSTRAINT locales_direction_check CHECK (direction IN ('ltr', 'rtl')), ADD CONSTRAINT locales_default_active_check CHECK (NOT is_default OR is_active), ADD CONSTRAINT locales_position_check CHECK (sort_order >= 0)");
        DB::statement('CREATE UNIQUE INDEX locales_one_default ON locales (is_default) WHERE is_default');
        // Required reference data, not merchant/demo records. Never reseed over preferences.
        DB::table('locales')->insert([
            ['code' => 'en', 'name' => 'English', 'native_name' => 'English', 'is_active' => true, 'is_default' => true, 'direction' => 'ltr', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'el', 'name' => 'Greek', 'native_name' => 'Ελληνικά', 'is_active' => true, 'is_default' => false, 'direction' => 'ltr', 'sort_order' => 10, 'created_at' => now(), 'updated_at' => now()],
        ]);
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true);
            $table->foreignId('preferred_locale_id')->nullable()->constrained('locales')->restrictOnDelete();
            $table->index('preferred_locale_id');
        });
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 80)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_super_admin')->default(false);
            $table->timestampsTz();
        });
        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120)->unique();
            $table->timestampsTz();
        });
        foreach (['role_user' => ['role', 'user'], 'permission_role' => ['permission', 'role']] as $name => $members) {
            Schema::create($name, function (Blueprint $table) use ($members): void {
                foreach ($members as $member) {
                    $table->foreignId($member.'_id')->constrained($member.'s')->cascadeOnDelete();
                }
                $table->primary([$members[0].'_id', $members[1].'_id']);
                $table->index($members[1].'_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('preferred_locale_id');
            $table->dropColumn('is_active');
        });
        Schema::dropIfExists('locales');
    }
};
