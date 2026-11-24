<?php

namespace App\Shared\Infrastructure\Modules;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;

/**
 * Discovers add-on packages in the two trusted roots and reads their lifecycle state. Bundled first-party add-ons ship
 * with the CMS in `modules/{vendor}/{name}`; uploaded add-ons are extracted into `storage/addons/{vendor}/{name}`.
 * Discovery only reads manifests; it never executes add-on code.
 */
final class AddonCatalog
{
    public const SOURCES = ['bundled', 'uploaded'];

    /** @var array<string, array{name: string, source: string, path: string, manifest: ?ModuleManifest, error: ?AddonException}>|null */
    private ?array $discovered = null;

    public function root(string $source): string
    {
        return rtrim((string) config($source === 'bundled' ? 'modules.bundled_path' : 'modules.addons_path'), '/');
    }

    public function directory(string $name, string $source): string
    {
        return $this->root($source).'/'.$name;
    }

    /**
     * Every package found on disk, keyed by add-on name. A bundled package wins over an uploaded one of the same name.
     *
     * @return array<string, array{name: string, source: string, path: string, manifest: ?ModuleManifest, error: ?AddonException}>
     */
    public function discover(bool $fresh = false): array
    {
        if ($this->discovered !== null && ! $fresh) {
            return $this->discovered;
        }
        $found = [];
        foreach (['uploaded', 'bundled'] as $source) {
            foreach (glob($this->root($source).'/*/*', GLOB_ONLYDIR) ?: [] as $path) {
                $name = basename(dirname($path)).'/'.basename($path);
                if (is_link($path) || preg_match('~^[a-z0-9][a-z0-9_.-]*/[a-z0-9][a-z0-9_.-]*$~D', $name) !== 1) {
                    continue;
                }
                $manifest = null;
                $error = null;
                try {
                    $manifest = ModuleManifest::fromDirectory($path);
                    if ($manifest->name !== $name) {
                        throw new AddonException('addons.error_manifest_location', ['name' => $manifest->name]);
                    }
                } catch (AddonException $e) {
                    [$manifest, $error] = [null, $e];
                }
                $found[$name] = ['name' => $name, 'source' => $source, 'path' => $path, 'manifest' => $manifest, 'error' => $error];
            }
        }
        ksort($found, SORT_STRING);

        return $this->discovered = $found;
    }

    /**
     * Installed add-ons. Before the schema exists (first migration, `composer install` without a database) none are.
     *
     * @return array<string, object{name: string, source: string, version: string, status: string, lock_version: int}>
     */
    public function installed(): array
    {
        try {
            return DB::table('addons')->orderBy('name')->get(['name', 'source', 'version', 'status', 'lock_version'])->keyBy('name')->all();
        } catch (QueryException|PDOException) {
            return [];
        }
    }

    /**
     * Names of enabled add-ons according to the database (the source of truth, also for long-running workers).
     *
     * @return list<string>
     */
    public function enabledNames(): array
    {
        return array_map(strval(...), array_keys(array_filter($this->installed(), fn (object $row): bool => $row->status === 'enabled')));
    }

    /** The discovered manifest of an installed add-on, when its files are present and match the installed source. */
    public function manifest(string $name, ?string $source = null): ?ModuleManifest
    {
        $package = $this->discover()[$name] ?? null;

        return $package === null || ($source !== null && $package['source'] !== $source) ? null : $package['manifest'];
    }

    public function forget(): void
    {
        $this->discovered = null;
    }
}
