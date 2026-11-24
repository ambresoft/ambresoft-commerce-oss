<?php

use App\Audit\Infrastructure\AuditTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LINKS = ['social_facebook', 'social_instagram', 'social_tiktok', 'social_linkedin', 'social_x', 'social_youtube', 'social_pinterest', 'app_store_url', 'google_play_url'];

    public function up(): void
    {
        // Social network pages and the store's mobile apps: public https links shown in the footer.
        Schema::table('company_settings', function (Blueprint $t): void {
            foreach (self::LINKS as $column) {
                $t->string($column, 2048)->nullable();
            }
        });
        $checks = implode(' AND ', array_map(fn (string $c): string => "($c IS NULL OR $c ~ '^https?://[^ ]+$')", self::LINKS));
        DB::statement("ALTER TABLE company_settings ADD CONSTRAINT company_settings_links CHECK ($checks)");
        $this->audit(fn (string $fields): string => $fields.','.implode(',', self::LINKS));

        // Payment method pictures (Visa, Mastercard, ...) shown in the footer, in upload order.
        Schema::create('company_payment_icons', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('media_file_id')->unique()->constrained('media_files')->restrictOnDelete();
            $t->string('name', 80)->default('');
            $t->integer('position');
            $t->timestampsTz();
        });
        DB::statement('ALTER TABLE company_payment_icons ADD CONSTRAINT company_payment_icons_position CHECK (position > 0)');
        foreach (AuditTables::companyPaymentIcons() as $table => [$entity, $fields]) {
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('company_payment_icons');
        $this->audit(fn (string $fields): string => implode(',', array_diff(explode(',', $fields), self::LINKS)));
        DB::statement('ALTER TABLE company_settings DROP CONSTRAINT company_settings_links');
        Schema::table('company_settings', fn (Blueprint $t) => $t->dropColumn(self::LINKS));
    }

    /** Extends (or shrinks) the field list that earlier additive migrations left on the audit trigger. */
    private function audit(callable $fields): void
    {
        $definition = DB::selectOne("SELECT pg_get_triggerdef(oid) AS def FROM pg_trigger WHERE tgname = 'cms_audit_mutation' AND tgrelid = 'company_settings'::regclass")->def;
        if (preg_match("/cms_capture_audit\('([^']+)', '([^']+)'\)/", $definition, $m)) {
            DB::statement('DROP TRIGGER cms_audit_mutation ON company_settings');
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON company_settings FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('{$m[1]}', '{$fields($m[2])}')");
        }
    }
};
