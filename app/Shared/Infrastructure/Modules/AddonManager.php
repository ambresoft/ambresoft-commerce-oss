<?php

namespace App\Shared\Infrastructure\Modules;

use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Identity\Infrastructure\Persistence\UserRecord;
use Composer\Autoload\ClassLoader;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The add-on lifecycle: install, enable, disable, upgrade and uninstall, for bundled and uploaded add-ons alike.
 * Operations serialize on one advisory lock. Database work (owned migrations and the state row) is one transaction;
 * file moves are compensated when it fails. Enabling registers the add-on in the running application immediately;
 * other processes pick changes up on their next start. Uninstalling keeps the add-on's data and migration history,
 * except tables the add-on declares in `uninstall-clears`.
 */
final class AddonManager
{
    private const LOCK = 2142801;

    public function __construct(private AddonCatalog $catalog, private AddonPackage $packages, private AddonRuntime $runtime, private ModuleExtensionPoints $points) {}

    /**
     * The administration list: every discovered package and every installed add-on, with its state and allowed actions.
     *
     * @return list<array<string, mixed>>
     */
    public function overview(): array
    {
        $installed = $this->catalog->installed();
        $packages = $this->catalog->discover(true);
        $rows = [];
        foreach (array_unique([...array_keys($packages), ...array_keys($installed)]) as $name) {
            $package = $packages[$name] ?? null;
            $row = $installed[$name] ?? null;
            $manifest = $package['manifest'] ?? null;
            if ($row !== null && $package !== null && $package['source'] !== $row->source) {
                $manifest = null;
            }
            $compatible = $manifest?->compatible() ?? false;
            $newer = $row !== null && $manifest !== null && version_compare($manifest->version, $row->version, '>');
            $rows[] = [
                'name' => $name,
                'title' => $manifest !== null ? $manifest->title : $name,
                'description' => $manifest->description ?? '',
                'author' => $manifest->author ?? '',
                'icon_url' => $manifest?->icon === null ? null : route('admin.addons.icon', explode('/', $name), false).'?v='.$manifest->version,
                'version' => $manifest->version ?? null,
                'installed_version' => $row?->version,
                'source' => $row->source ?? $package['source'] ?? 'uploaded',
                'status' => $row === null ? 'not_installed' : $row->status,
                'compatible' => $compatible,
                'cms' => $manifest->cms ?? null,
                'requires' => array_keys($manifest->requires ?? []),
                'contributes' => array_map(fn (mixed $v): array => is_array($v) ? array_map(strval(...), array_keys($v)) : [], $manifest->contributes ?? []),
                'error' => $package === null ? __('addons.error_files_missing') : ($package['error']?->translated() ?? ($manifest === null ? __('addons.error_files_missing') : null)),
                'upgrade_available' => $newer,
                'actions' => [
                    'install' => $row === null && $manifest !== null && $compatible && ($package['source'] ?? null) === 'bundled',
                    'enable' => $row?->status === 'disabled' && $manifest !== null && $compatible && $manifest->version === $row->version,
                    'disable' => $row?->status === 'enabled',
                    'upgrade' => $newer && $compatible && $row->source === 'bundled',
                    'uninstall' => $row?->status === 'disabled',
                ],
            ];
        }
        usort($rows, fn (array $a, array $b): int => [$a['source'] !== 'bundled', strtolower($a['title'])] <=> [$b['source'] !== 'bundled', strtolower($b['title'])]);

        return $rows;
    }

    /** Installs a bundled add-on (disabled). Its files ship with the CMS, so no upload is involved. */
    public function installBundled(?string $actor, string $name): void
    {
        $this->operation($actor, 'addons.manage', function () use ($actor, $name): void {
            $package = $this->catalog->discover(true)[$name] ?? null;
            if ($package === null || $package['source'] !== 'bundled') {
                throw new AddonException('addons.error_not_found');
            }
            $manifest = $package['manifest'] ?? throw $package['error'] ?? new AddonException('addons.error_files_missing');
            DB::transaction(function () use ($actor, $manifest, $package): void {
                $this->lock();
                $this->install($actor, $manifest, $package['path'], 'bundled');
            });
        });
    }

    /**
     * Installs or upgrades an add-on from a ZIP archive. An add-on that is already installed is replaced only when the
     * administrator explicitly asked for an upgrade and the archive carries a newer version.
     */
    public function upload(?string $actor, string $zipPath, bool $upgrade): string
    {
        if (! config('modules.uploads')) {
            throw new AddonException('addons.error_uploads_disabled');
        }

        return $this->operation($actor, 'addons.upload', function () use ($actor, $zipPath, $upgrade): string {
            $staged = $this->packages->stage($zipPath);
            $manifest = $staged['manifest'];
            try {
                $bundled = $this->catalog->discover(true)[$manifest->name] ?? null;
                if ($bundled !== null && $bundled['source'] === 'bundled') {
                    throw new AddonException('addons.error_bundled_name', ['name' => $manifest->name]);
                }
                $this->checkPackage($manifest, $staged['directory']);
                $row = $this->catalog->installed()[$manifest->name] ?? null;
                if ($row !== null) {
                    if (! $upgrade) {
                        throw new AddonException('addons.error_installed', ['name' => $manifest->name]);
                    }
                    if (version_compare($manifest->version, $row->version, '<=')) {
                        throw new AddonException('addons.error_not_newer', ['name' => $manifest->name, 'version' => $row->version]);
                    }
                    $this->replace($actor, $manifest, $staged['directory'], $row->version);
                } else {
                    $this->installUploaded($actor, $manifest, $staged['directory'], $upgrade);
                }
            } finally {
                $this->packages->discard($staged['directory']);
            }
            $this->runtime->forget();

            return $manifest->name;
        });
    }

    public function enable(?string $actor, string $name): void
    {
        $this->operation($actor, 'addons.manage', function () use ($actor, $name): void {
            DB::transaction(function () use ($actor, $name): void {
                $this->lock();
                $row = $this->row($name);
                if ($row->status === 'enabled') {
                    return;
                }
                $manifest = $this->manifestOf($row);
                if ($manifest->version !== $row->version) {
                    throw new AddonException('addons.error_upgrade_pending', ['name' => $name]);
                }
                $installed = $this->catalog->installed();
                foreach ($manifest->requires as $dependency => $constraint) {
                    $other = $installed[$dependency] ?? null;
                    if ($other === null || $other->status !== 'enabled' || ! VersionConstraint::satisfies($other->version, $constraint)) {
                        throw new AddonException('addons.error_dependency_disabled', ['name' => $name, 'dependency' => $dependency]);
                    }
                }
                $directory = $this->catalog->directory($name, $row->source);
                $this->checkPackage($manifest, $directory, $name);
                DB::table('addons')->where('name', $name)->update(['status' => 'enabled', 'lock_version' => DB::raw('lock_version + 1'), 'updated_by' => $actor, 'updated_at' => now()]);
                // Registers the provider and contributions now, so a failure leaves the add-on disabled.
                try {
                    $this->runtime->load($manifest, $directory);
                } catch (AddonException $e) {
                    throw $e;
                } catch (Throwable $e) {
                    report($e);
                    throw new AddonException('addons.error_load_failed', ['name' => $name]);
                }
            });
            $this->runtime->forget();
            // Capabilities the add-on declares become assignable to roles (nobody is granted them).
            Artisan::call('cms:permissions-sync');
        });
    }

    public function disable(?string $actor, string $name): void
    {
        $this->operation($actor, 'addons.manage', function () use ($actor, $name): void {
            DB::transaction(function () use ($actor, $name): void {
                $this->lock();
                $row = $this->row($name);
                if ($row->status === 'disabled') {
                    return;
                }
                foreach ($this->dependents($name, true) as $dependent) {
                    throw new AddonException('addons.error_required_by', ['name' => $name, 'dependent' => $dependent]);
                }
                DB::table('addons')->where('name', $name)->update(['status' => 'disabled', 'lock_version' => DB::raw('lock_version + 1'), 'updated_by' => $actor, 'updated_at' => now()]);
            });
            $this->runtime->forget();
        });
    }

    /** Applies a newer bundled version shipped with a CMS update: runs its pending migrations and records the version. */
    public function upgrade(?string $actor, string $name): void
    {
        $this->operation($actor, 'addons.manage', function () use ($actor, $name): void {
            DB::transaction(function () use ($actor, $name): void {
                $this->lock();
                $row = $this->row($name);
                $manifest = $this->manifestOf($row);
                if ($row->source !== 'bundled' || version_compare($manifest->version, $row->version, '<=')) {
                    throw new AddonException('addons.error_not_newer', ['name' => $name, 'version' => $row->version]);
                }
                $this->upgradeInPlace($actor, $manifest, $this->catalog->directory($name, 'bundled'));
            });
            $this->runtime->forget();
        });
    }

    /** Removes the add-on's registration (and the files of an uploaded one). Its tables, settings and history stay. */
    public function uninstall(?string $actor, string $name): void
    {
        $this->operation($actor, 'addons.manage', function () use ($name): void {
            $row = DB::transaction(function () use ($name): object {
                $this->lock();
                $row = $this->row($name);
                if ($row->status !== 'disabled') {
                    throw new AddonException('addons.error_disable_first', ['name' => $name]);
                }
                foreach ($this->dependents($name, false) as $dependent) {
                    throw new AddonException('addons.error_required_by', ['name' => $name, 'dependent' => $dependent]);
                }
                foreach ($this->catalog->manifest($name)->uninstallClears ?? [] as $table) {
                    if (Schema::hasTable($table)) {
                        DB::table($table)->delete();
                    }
                }
                DB::table('addons')->where('name', $name)->delete();

                return $row;
            });
            if ($row->source === 'uploaded') {
                $this->removeUploaded($name);
            }
            $this->runtime->forget();
        });
    }

    /**
     * Deployment step after a CMS install or update: installs bundled add-ons that are not installed yet (disabled) and
     * upgrades installed bundled add-ons whose shipped version is newer. Incompatible packages are reported, not touched.
     *
     * @return array{installed: list<string>, upgraded: list<string>, skipped: array<string, string>}
     */
    public function sync(): array
    {
        $result = ['installed' => [], 'upgraded' => [], 'skipped' => []];
        foreach ($this->catalog->discover(true) as $name => $package) {
            if ($package['source'] !== 'bundled') {
                continue;
            }
            try {
                $row = $this->catalog->installed()[$name] ?? null;
                if ($row === null) {
                    $this->installBundled(null, $name);
                    $result['installed'][] = $name;
                } elseif ($row->source === 'bundled' && $package['manifest'] !== null && version_compare($package['manifest']->version, $row->version, '>')) {
                    $this->upgrade(null, $name);
                    $result['upgraded'][] = $name;
                }
            } catch (AddonException $e) {
                $result['skipped'][$name] = $e->translated();
            }
        }

        return $result;
    }

    /**
     * Validates a package without executing it: CMS compatibility, files referenced by the manifest, owned migration
     * names, unique PSR-4 namespaces and the declared contributions.
     */
    public function checkPackage(ModuleManifest $manifest, string $directory, ?string $self = null): void
    {
        if (! $manifest->compatible()) {
            throw new AddonException('addons.error_incompatible', ['name' => $manifest->name, 'constraint' => $manifest->cms, 'version' => CmsVersion::CURRENT]);
        }
        $file = $manifest->classFile($manifest->provider);
        if ($file === null || ! is_file($directory.'/'.$file)) {
            throw new AddonException('addons.error_provider_class', ['name' => $manifest->name]);
        }
        foreach ($manifest->autoload as $path) {
            if (! is_dir($directory.($path === '' ? '' : '/'.$path))) {
                throw new AddonException('addons.error_manifest_autoload', ['name' => $manifest->name]);
            }
        }
        if ($manifest->migrations !== null) {
            $path = $directory.'/'.$manifest->migrations;
            if (! is_dir($path)) {
                throw new AddonException('addons.error_manifest_migrations', ['name' => $manifest->name]);
            }
            $pattern = '/^\d{4}_\d{2}_\d{2}_\d{6}_'.preg_quote($manifest->migrationPrefix(), '/').'[a-z0-9_]+\.php$/D';
            foreach (glob($path.'/*.php') ?: [] as $migration) {
                if (preg_match($pattern, basename($migration)) !== 1) {
                    throw new AddonException('addons.error_migration_name', ['name' => $manifest->name, 'file' => basename($migration), 'prefix' => $manifest->migrationPrefix()]);
                }
            }
        }
        $others = [];
        foreach (array_keys($this->catalog->installed()) as $name) {
            if ($name !== ($self ?? $manifest->name) && ($other = $this->catalog->manifest($name)) !== null) {
                $others[] = $other;
            }
        }
        $this->checkNamespaces($manifest, $others);
        $this->points->validate($manifest, $directory, $others);
    }

    /** @param list<ModuleManifest> $others */
    private function checkNamespaces(ModuleManifest $manifest, array $others): void
    {
        $taken = [];
        foreach ($others as $other) {
            $taken = [...$taken, ...array_keys($other->autoload)];
        }
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $taken = [...$taken, ...array_keys($loader->getPrefixesPsr4())];
        }
        foreach (array_keys($manifest->autoload) as $prefix) {
            foreach ($taken as $existing) {
                if ($existing !== '' && (str_starts_with($prefix, $existing) || str_starts_with($existing, $prefix))) {
                    throw new AddonException('addons.error_manifest_namespace', ['name' => $manifest->name, 'namespace' => rtrim($prefix, '\\')]);
                }
            }
        }
    }

    private function install(?string $actor, ModuleManifest $manifest, string $directory, string $source): void
    {
        if (isset($this->catalog->installed()[$manifest->name])) {
            throw new AddonException('addons.error_installed', ['name' => $manifest->name]);
        }
        $this->checkPackage($manifest, $directory);
        $this->checkDependenciesInstalled($manifest);
        $this->migrate($manifest, $directory);
        DB::table('addons')->insert(['name' => $manifest->name, 'source' => $source, 'version' => $manifest->version, 'status' => 'disabled', 'installed_by' => $actor, 'updated_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function installUploaded(?string $actor, ModuleManifest $manifest, string $staged, bool $replaceOrphan): void
    {
        $target = $this->catalog->directory($manifest->name, 'uploaded');
        if (file_exists($target)) {
            // Files without an installation record (an interrupted earlier attempt) are only replaced on request.
            if (! $replaceOrphan) {
                throw new AddonException('addons.error_files_exist', ['name' => $manifest->name]);
            }
            $this->removeUploaded($manifest->name);
        }
        $this->move($staged, $target);
        try {
            DB::transaction(function () use ($actor, $manifest, $target): void {
                $this->lock();
                $this->install($actor, $manifest, $target, 'uploaded');
            });
        } catch (Throwable $e) {
            $this->removeUploaded($manifest->name);
            throw $this->failure($e);
        }
    }

    /** Swaps in a newer uploaded version, keeping the previous files until the database work has committed. */
    private function replace(?string $actor, ModuleManifest $manifest, string $staged, string $installedVersion): void
    {
        $target = $this->catalog->directory($manifest->name, 'uploaded');
        $backup = $this->packages->stagingRoot().'/backup-'.bin2hex(random_bytes(8));
        if (! is_dir($target)) {
            throw new AddonException('addons.error_files_missing');
        }
        $this->move($target, $backup);
        try {
            $this->move($staged, $target);
            DB::transaction(function () use ($actor, $manifest, $target, $installedVersion): void {
                $this->lock();
                $row = $this->row($manifest->name);
                if ($row->version !== $installedVersion) {
                    throw new AddonException('addons.error_concurrent');
                }
                $this->upgradeInPlace($actor, $manifest, $target);
            });
        } catch (Throwable $e) {
            AddonPackage::remove($target);
            $this->move($backup, $target);
            throw $this->failure($e);
        }
        AddonPackage::remove($backup);
    }

    private function upgradeInPlace(?string $actor, ModuleManifest $manifest, string $directory): void
    {
        $this->checkPackage($manifest, $directory, $manifest->name);
        $this->checkDependenciesInstalled($manifest);
        $this->migrate($manifest, $directory);
        DB::table('addons')->where('name', $manifest->name)->update(['version' => $manifest->version, 'lock_version' => DB::raw('lock_version + 1'), 'updated_by' => $actor, 'updated_at' => now()]);
    }

    private function checkDependenciesInstalled(ModuleManifest $manifest): void
    {
        $installed = $this->catalog->installed();
        foreach ($manifest->requires as $dependency => $constraint) {
            if (! isset($installed[$dependency]) || ! VersionConstraint::satisfies($installed[$dependency]->version, $constraint)) {
                throw new AddonException('addons.error_dependency_missing', ['name' => $manifest->name, 'dependency' => $dependency, 'constraint' => $constraint]);
            }
        }
    }

    /** Runs the add-on's pending owned migrations inside the surrounding transaction (PostgreSQL DDL is transactional). */
    private function migrate(ModuleManifest $manifest, string $directory): void
    {
        if ($manifest->migrations === null) {
            return;
        }
        $migrator = app('migrator');
        if (! $migrator->repositoryExists()) {
            $migrator->getRepository()->createRepository();
        }
        try {
            $migrator->run([$directory.'/'.$manifest->migrations]);
        } catch (AddonException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            throw new AddonException('addons.error_migration_failed', ['name' => $manifest->name]);
        }
    }

    /** @return list<string> Installed (or only enabled) add-ons that declare a dependency on $name. */
    private function dependents(string $name, bool $enabledOnly): array
    {
        $dependents = [];
        foreach ($this->catalog->installed() as $other => $row) {
            if ($other === $name || ($enabledOnly && $row->status !== 'enabled')) {
                continue;
            }
            $manifest = $this->catalog->manifest($other);
            if ($manifest !== null && isset($manifest->requires[$name])) {
                $dependents[] = $other;
            }
        }

        return $dependents;
    }

    private function row(string $name): object
    {
        return DB::table('addons')->where('name', $name)->lockForUpdate()->first() ?? throw new AddonException('addons.error_not_found');
    }

    private function manifestOf(object $row): ModuleManifest
    {
        $package = $this->catalog->discover(true)[$row->name] ?? null;
        if ($package === null || $package['source'] !== $row->source) {
            throw new AddonException('addons.error_files_missing');
        }

        return $package['manifest'] ?? throw $package['error'] ?? new AddonException('addons.error_files_missing');
    }

    private function removeUploaded(string $name): void
    {
        $root = realpath($this->catalog->root('uploaded'));
        $target = $this->catalog->directory($name, 'uploaded');
        if ($root !== false && ! is_link($target) && str_starts_with((string) realpath($target), $root.'/')) {
            AddonPackage::remove($target);
            $vendor = dirname($target);
            if (is_dir($vendor) && count(scandir($vendor) ?: []) === 2) {
                @rmdir($vendor);
            }
        }
        $this->catalog->forget();
    }

    private function move(string $from, string $to): void
    {
        if (! is_dir(dirname($to)) && ! @mkdir(dirname($to), 0750, true) && ! is_dir(dirname($to))) {
            throw new AddonException('addons.error_staging');
        }
        if (! @rename($from, $to)) {
            throw new AddonException('addons.error_staging');
        }
        $this->catalog->forget();
    }

    private function failure(Throwable $e): Throwable
    {
        return $e instanceof AddonException ? $e : new AddonException('addons.error_install_failed');
    }

    private function lock(): void
    {
        DB::select('SELECT pg_advisory_xact_lock(?)', [self::LOCK]);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function operation(?string $actor, string $ability, callable $operation): mixed
    {
        if ($actor === null) {
            return $operation(); // Console deployment steps are trusted.
        }
        app(AuditContext::class)->actor($actor);
        Gate::forUser(UserRecord::findOrFail($actor))->authorize($ability);

        return app(AuditLogger::class)->operation('addon', $actor, $operation);
    }
}
