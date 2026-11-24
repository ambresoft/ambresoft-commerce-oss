<?php

namespace App\Shared\Infrastructure\Modules;

use LogicException;

/** Extension points that core contexts publish to add-ons, keyed by the manifest `contributes` key. */
final class ModuleExtensionPoints
{
    /** @var array<string, ModuleExtensionPoint> */
    private array $points = [];

    public function add(string $key, ModuleExtensionPoint $point): void
    {
        if (preg_match('/^[a-z][a-z0-9-]{1,39}$/D', $key) !== 1 || isset($this->points[$key])) {
            throw new LogicException('Extension points need a unique key.');
        }
        $this->points[$key] = $point;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->points);
    }

    /**
     * @param  list<ModuleManifest>  $others
     *
     * @throws AddonException
     */
    public function validate(ModuleManifest $manifest, string $directory, array $others): void
    {
        foreach ($manifest->contributes as $key => $declared) {
            $point = $this->points[$key] ?? throw new AddonException('addons.error_unknown_contribution', ['name' => $manifest->name, 'point' => (string) $key]);
            $point->validate($manifest, $declared, $directory, $others);
        }
    }

    public function register(ModuleManifest $manifest): void
    {
        foreach ($manifest->contributes as $key => $declared) {
            ($this->points[$key] ?? throw new AddonException('addons.error_unknown_contribution', ['name' => $manifest->name, 'point' => (string) $key]))->register($manifest, $declared);
        }
    }
}
