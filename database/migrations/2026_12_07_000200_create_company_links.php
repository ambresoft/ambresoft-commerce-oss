<?php

use App\Audit\Infrastructure\AuditTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const NETWORKS = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok', 'linkedin' => 'LinkedIn', 'x' => 'X', 'youtube' => 'YouTube', 'pinterest' => 'Pinterest'];

    private const APPS = ['app_store' => ['app_store_url', 'App Store'], 'google_play' => ['google_play_url', 'Google Play']];

    public function up(): void
    {
        // Social pages and mobile apps become ordered lists: merchants add, remove and reorder them, with their own icons.
        Schema::create('company_links', function (Blueprint $t): void {
            $t->id();
            $t->string('kind', 10);
            $t->string('preset', 20)->nullable();
            $t->string('name', 80)->default('');
            $t->string('url', 2048);
            $t->foreignId('media_file_id')->nullable()->unique()->constrained('media_files')->restrictOnDelete();
            $t->integer('position');
            $t->timestampsTz();
            $t->index(['kind', 'position']);
        });
        $networks = "'".implode("','", array_keys(self::NETWORKS))."'";
        // A custom (non-preset) link has no built-in mark, so it needs its own name and picture.
        DB::statement("ALTER TABLE company_links ADD CONSTRAINT company_link_values CHECK (kind IN ('social','app') AND url ~ '^https?://[^ ]+$' AND position > 0
            AND (preset IS NULL OR (kind = 'social' AND preset IN ($networks)) OR (kind = 'app' AND preset IN ('app_store','google_play')))
            AND (preset IS NOT NULL OR (media_file_id IS NOT NULL AND btrim(name) <> '')))");
        foreach (AuditTables::companyLinks() as $table => [$entity, $fields]) {
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }

        $company = DB::table('company_settings')->where('id', 1)->first();
        $icons = DB::table('company_social_icons')->pluck('media_file_id', 'network');
        $position = ['social' => 0, 'app' => 0];
        $insert = function (string $kind, string $preset, string $name, ?string $url, mixed $media = null) use (&$position): void {
            if ($url !== null && $url !== '') {
                DB::table('company_links')->insert(['kind' => $kind, 'preset' => $preset, 'name' => $name, 'url' => $url, 'media_file_id' => $media, 'position' => ++$position[$kind], 'created_at' => now(), 'updated_at' => now()]);
            }
        };
        foreach (self::NETWORKS as $network => $label) {
            $insert('social', $network, $label, $company?->{'social_'.$network}, $icons[$network] ?? null);
        }
        foreach (self::APPS as $preset => [$column, $label]) {
            $insert('app', $preset, $label, $company?->$column);
        }

        $columns = $this->columns();
        $this->audit(fn (string $fields): string => implode(',', array_diff(explode(',', $fields), $columns)));
        DB::statement('ALTER TABLE company_settings DROP CONSTRAINT IF EXISTS company_settings_links');
        Schema::table('company_settings', fn (Blueprint $t) => $t->dropColumn($columns));
        Schema::dropIfExists('company_social_icons');
    }

    public function down(): void
    {
        $columns = $this->columns();
        Schema::table('company_settings', function (Blueprint $t) use ($columns): void {
            foreach ($columns as $column) {
                $t->string($column, 2048)->nullable();
            }
        });
        Schema::create('company_social_icons', function (Blueprint $t): void {
            $t->id();
            $t->string('network', 20)->unique();
            $t->foreignId('media_file_id')->unique()->constrained('media_files')->restrictOnDelete();
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE company_social_icons ADD CONSTRAINT company_social_icons_network CHECK (network IN ('facebook','instagram','tiktok','linkedin','x','youtube','pinterest'))");
        foreach (AuditTables::companySocialIcons() as $table => [$entity, $fields]) {
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
        // Best effort: the first link of each preset survives; custom links have no old column.
        $values = [];
        foreach (DB::table('company_links')->whereNotNull('preset')->orderBy('position')->get() as $link) {
            $column = $link->kind === 'app' ? self::APPS[$link->preset][0] : 'social_'.$link->preset;
            if (! array_key_exists($column, $values)) {
                $values[$column] = $link->url;
                if ($link->kind === 'social' && $link->media_file_id !== null) {
                    DB::table('company_social_icons')->insert(['network' => $link->preset, 'media_file_id' => $link->media_file_id, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }
        if ($values !== []) {
            DB::table('company_settings')->where('id', 1)->update($values);
        }
        $checks = implode(' AND ', array_map(fn (string $c): string => "($c IS NULL OR $c ~ '^https?://[^ ]+$')", $columns));
        DB::statement("ALTER TABLE company_settings ADD CONSTRAINT company_settings_links CHECK ($checks)");
        $this->audit(fn (string $fields): string => $fields.','.implode(',', $columns));
        Schema::dropIfExists('company_links');
    }

    /** @return list<string> */
    private function columns(): array
    {
        return [...array_map(fn (string $n): string => 'social_'.$n, array_keys(self::NETWORKS)), ...array_column(self::APPS, 0)];
    }

    /** Shrinks (or extends) the field list that earlier additive migrations left on the audit trigger. */
    private function audit(callable $fields): void
    {
        $definition = DB::selectOne("SELECT pg_get_triggerdef(oid) AS def FROM pg_trigger WHERE tgname = 'cms_audit_mutation' AND tgrelid = 'company_settings'::regclass")?->def;
        if ($definition !== null && preg_match("/cms_capture_audit\('([^']+)', '([^']+)'\)/", $definition, $m)) {
            DB::statement('DROP TRIGGER cms_audit_mutation ON company_settings');
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON company_settings FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('{$m[1]}', '{$fields($m[2])}')");
        }
    }
};
