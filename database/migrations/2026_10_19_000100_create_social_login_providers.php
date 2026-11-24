<?php

use App\Audit\Infrastructure\AuditTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Configured sign-in providers for the future storefront. Plain fields (e.g. the client ID) are a JSON object;
        // credentials are one JSON object encrypted with the application key and never leave the server.
        Schema::create('social_login_providers', function (Blueprint $t): void {
            $t->id();
            $t->string('provider', 30);
            $t->string('name', 100);
            $t->jsonb('settings')->default('{}');
            $t->text('secrets')->nullable();
            $t->boolean('is_active')->default(true);
            $t->bigInteger('lock_version')->default(1);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE social_login_providers ADD CONSTRAINT social_login_provider_values CHECK (provider ~ '^[a-z][a-z0-9_]{1,29}$' AND btrim(name) <> '' AND jsonb_typeof(settings) = 'object' AND lock_version > 0)");
        DB::statement('CREATE UNIQUE INDEX social_login_provider_name ON social_login_providers (lower(name))');
        // The storefront needs one unambiguous client per provider.
        DB::statement('CREATE UNIQUE INDEX social_login_provider_active ON social_login_providers (provider) WHERE is_active');

        foreach (AuditTables::socialLogin() as $table => [$entity, $fields]) {
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS cms_audit_mutation ON social_login_providers');
        Schema::dropIfExists('social_login_providers');
    }
};
