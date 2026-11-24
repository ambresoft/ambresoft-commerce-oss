<?php

namespace App\Search\Infrastructure;

use App\Search\Application\Contract\SearchProvider;
use App\Search\Application\Service\SearchProviderRegistry;
use App\Shared\Infrastructure\Modules\AddonException;
use App\Shared\Infrastructure\Modules\ModuleExtensionPoint;
use App\Shared\Infrastructure\Modules\ModuleManifest;
use Illuminate\Contracts\Container\Container;

/**
 * The `search-providers` contribution: `{"search-providers": {"provider-id": "Vendor\\Addon\\SomeProvider"}}`. Each class
 * lives in the add-on's own namespace and implements SearchProvider. Identifiers are unique across add-ons.
 */
final class SearchProviderExtensionPoint implements ModuleExtensionPoint
{
    public function __construct(private Container $container, private SearchProviderRegistry $registry) {}

    public function validate(ModuleManifest $manifest, mixed $declared, string $directory, array $others): void
    {
        if (! is_array($declared) || $declared === [] || array_is_list($declared)) {
            throw new AddonException('addons.error_search_providers', ['name' => $manifest->name]);
        }
        foreach ($declared as $id => $class) {
            $id = (string) $id;
            $file = is_string($class) ? $manifest->classFile($class) : null;
            if (preg_match('/^[a-z][a-z0-9_.-]{1,59}$/D', $id) !== 1 || $file === null || ! is_file($directory.'/'.$file)) {
                throw new AddonException('addons.error_search_providers', ['name' => $manifest->name]);
            }
            $owner = $this->registry->owner($id);
            $declaredElsewhere = array_filter($others, fn (ModuleManifest $other): bool => isset($other->contributes['search-providers'][$id]));
            if (($owner !== null && $owner !== $manifest->name) || $declaredElsewhere !== []) {
                throw new AddonException('addons.error_search_provider_taken', ['name' => $manifest->name, 'provider' => $id]);
            }
        }
    }

    public function register(ModuleManifest $manifest, mixed $declared): void
    {
        foreach ((array) $declared as $id => $class) {
            $class = (string) $class;
            $this->registry->add((string) $id, $manifest->name, function () use ($class): SearchProvider {
                $provider = $this->container->make($class);
                if (! $provider instanceof SearchProvider) {
                    throw new AddonException('addons.error_search_providers', ['name' => $class]);
                }

                return $provider;
            });
        }
    }
}
