<?php

namespace App\Shipping\Infrastructure;

use App\Shared\Infrastructure\Modules\AddonException;
use App\Shared\Infrastructure\Modules\ModuleExtensionPoint;
use App\Shared\Infrastructure\Modules\ModuleManifest;
use App\Shipping\Application\Contract\ShippingProvider;
use App\Shipping\Application\Service\ShippingProviderRegistry;
use Illuminate\Contracts\Container\Container;

/**
 * The `shipping-providers` contribution: `{"shipping-providers": {"provider-id": "Vendor\\Addon\\SomeProvider"}}`. Each
 * class lives in the add-on's own namespace and implements ShippingProvider plus the capabilities it supports.
 * Identifiers are unique across core and add-ons; stored accounts and methods keep pointing at the identifier.
 */
final class ShippingProviderExtensionPoint implements ModuleExtensionPoint
{
    public function __construct(private Container $container, private ShippingProviderRegistry $registry) {}

    public function validate(ModuleManifest $manifest, mixed $declared, string $directory, array $others): void
    {
        if (! is_array($declared) || $declared === [] || array_is_list($declared)) {
            throw new AddonException('addons.error_shipping_providers', ['name' => $manifest->name]);
        }
        foreach ($declared as $id => $class) {
            $id = (string) $id;
            $file = is_string($class) ? $manifest->classFile($class) : null;
            if (preg_match('/^[a-z][a-z0-9_.-]{1,59}$/D', $id) !== 1 || $file === null || ! is_file($directory.'/'.$file)) {
                throw new AddonException('addons.error_shipping_providers', ['name' => $manifest->name]);
            }
            $owner = $this->registry->owner($id);
            $declaredElsewhere = array_filter($others, fn (ModuleManifest $other): bool => isset($other->contributes['shipping-providers'][$id]));
            if (($owner !== null && $owner !== $manifest->name) || $declaredElsewhere !== []) {
                throw new AddonException('addons.error_shipping_provider_taken', ['name' => $manifest->name, 'provider' => $id]);
            }
        }
    }

    public function register(ModuleManifest $manifest, mixed $declared): void
    {
        foreach ((array) $declared as $id => $class) {
            $class = (string) $class;
            $this->registry->add((string) $id, $manifest->name, function () use ($class): ShippingProvider {
                $provider = $this->container->make($class);
                if (! $provider instanceof ShippingProvider) {
                    throw new AddonException('addons.error_shipping_providers', ['name' => $class]);
                }

                return $provider;
            });
        }
    }
}
