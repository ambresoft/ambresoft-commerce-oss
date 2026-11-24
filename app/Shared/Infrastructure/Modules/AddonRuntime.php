<?php

namespace App\Shared\Infrastructure\Modules;

use App\Shared\Application\Contract\AddonStatus;
use Composer\Autoload\ClassLoader;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Loads enabled add-ons on every application start: registers their PSR-4 autoloading, their Laravel provider (in
 * dependency order) and their declared contributions. Only add-ons recorded as enabled, whose files match the installed
 * version and whose CMS and dependency constraints hold are loaded. A broken add-on is skipped and logged, so the
 * administration stays reachable to repair it. Loading never installs, migrates or writes data.
 */
final class AddonRuntime implements AddonStatus
{
    /** @var array<string, string> PSR-4 prefixes registered in this PHP process (autoloaders outlive one application). */
    private static array $autoloaded = [];

    /** @var array<string, ModuleManifest> */
    private array $loaded = [];

    /** @var list<string>|null */
    private ?array $enabled = null;

    public function __construct(private Application $app, private AddonCatalog $catalog, private ModuleExtensionPoints $points) {}

    public function boot(): void
    {
        $installed = $this->catalog->installed();
        $enabled = array_filter($installed, fn (object $row): bool => $row->status === 'enabled');
        if ($enabled === []) {
            return;
        }
        $candidates = [];
        foreach ($enabled as $name => $row) {
            $manifest = $this->catalog->manifest($name, $row->source);
            $reason = match (true) {
                $manifest === null => 'files_missing_or_invalid',
                $manifest->version !== $row->version => 'upgrade_pending',
                ! $manifest->compatible() => 'incompatible',
                default => null,
            };
            if ($reason !== null) {
                Log::warning('addon_not_loaded', ['addon' => $name, 'reason' => $reason]);

                continue;
            }
            $candidates[$name] = $manifest;
        }
        // Drop add-ons whose dependencies are not loadable at a satisfying version, until stable.
        do {
            $removed = false;
            foreach ($candidates as $name => $manifest) {
                foreach ($manifest->requires as $dependency => $constraint) {
                    if (! isset($candidates[$dependency]) || ! VersionConstraint::satisfies($candidates[$dependency]->version, $constraint)) {
                        Log::warning('addon_not_loaded', ['addon' => $name, 'reason' => 'dependency_unavailable', 'dependency' => $dependency]);
                        unset($candidates[$name]);
                        $removed = true;

                        continue 2;
                    }
                }
            }
        } while ($removed);

        try {
            $ordered = (new ModuleGraph)->resolve($candidates, array_keys($candidates));
        } catch (\InvalidArgumentException) {
            Log::warning('addon_not_loaded', ['reason' => 'dependency_cycle']);

            return;
        }
        foreach ($ordered as $manifest) {
            try {
                $this->load($manifest, $this->catalog->directory($manifest->name, $installed[$manifest->name]->source));
            } catch (Throwable $e) {
                Log::warning('addon_not_loaded', ['addon' => $manifest->name, 'reason' => 'load_failed', 'type' => $e::class]);
            }
        }
    }

    /** Registers one add-on in the running application (also used right after an administrator enables it). */
    public function load(ModuleManifest $manifest, string $directory): void
    {
        if (isset($this->loaded[$manifest->name])) {
            return;
        }
        $this->autoload($manifest, $directory);
        $provider = $manifest->provider;
        if (! class_exists($provider) || ! is_subclass_of($provider, ServiceProvider::class) || ! (new \ReflectionClass($provider))->isInstantiable()) {
            throw new AddonException('addons.error_provider_class', ['name' => $manifest->name]);
        }
        $this->app->register($provider);
        $this->points->register($manifest);
        $this->loaded[$manifest->name] = $manifest;
    }

    public function isLoaded(string $name): bool
    {
        return isset($this->loaded[$name]);
    }

    /** Whether an add-on is enabled right now; contributions of add-ons disabled since start-up are no longer offered. */
    public function isEnabled(string $name): bool
    {
        return in_array($name, $this->enabled ??= $this->catalog->enabledNames(), true);
    }

    /** Forgets cached state after a lifecycle change. */
    public function forget(): void
    {
        $this->enabled = null;
        $this->catalog->forget();
    }

    private function autoload(ModuleManifest $manifest, string $directory): void
    {
        foreach ($manifest->autoload as $prefix => $path) {
            $target = rtrim($directory.'/'.$path, '/');
            if ((self::$autoloaded[$prefix] ?? null) === $target) {
                continue;
            }
            $loader = new ClassLoader;
            $loader->addPsr4($prefix, $target);
            $loader->register();
            self::$autoloaded[$prefix] = $target;
        }
    }
}
