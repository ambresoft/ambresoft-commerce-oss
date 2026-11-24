<?php

namespace App\Shared\Infrastructure\Modules;

use App\Shared\Application\Contract\AddonSettings;
use Illuminate\Contracts\Container\Container;

/** The `settings` contribution: `{"settings": "Vendor\\Addon\\Settings"}`, a class in the add-on's namespace implementing AddonSettings. */
final class AddonSettingsExtensionPoint implements ModuleExtensionPoint
{
    public function __construct(private Container $container) {}

    public function validate(ModuleManifest $manifest, mixed $declared, string $directory, array $others): void
    {
        $file = is_string($declared) ? $manifest->classFile($declared) : null;
        if ($file === null || ! is_file($directory.'/'.$file)) {
            throw new AddonException('addons.error_settings', ['name' => $manifest->name]);
        }
    }

    public function register(ModuleManifest $manifest, mixed $declared): void
    {
        $class = (string) $declared;
        $this->container->make(AddonSettingsStore::class)->add($manifest->name, function () use ($class): AddonSettings {
            $settings = $this->container->make($class);
            if (! $settings instanceof AddonSettings) {
                throw new AddonException('addons.error_settings', ['name' => $class]);
            }

            return $settings;
        });
    }
}
