<?php

namespace App\Shared\Infrastructure\Modules;

use InvalidArgumentException;

/** Orders enabled add-ons so dependencies register first (lexical tie-break); refuses missing, disabled and cyclic graphs. */
final class ModuleGraph
{
    /**
     * @param  array<string, ModuleManifest>  $available
     * @param  list<string>  $enabled
     * @return list<ModuleManifest>
     */
    public function resolve(array $available, array $enabled): array
    {
        if (count($enabled) !== count(array_unique($enabled))) {
            throw new InvalidArgumentException('Duplicate enabled module.');
        }

        sort($enabled, SORT_STRING);
        $visiting = [];
        $visited = [];
        $ordered = [];
        $providers = [];

        $visit = function (string $name) use (&$visit, &$visiting, &$visited, &$ordered, &$providers, $available, $enabled): void {
            if (isset($visited[$name])) {
                return;
            }

            if (isset($visiting[$name])) {
                throw new InvalidArgumentException("Cyclic module dependency: {$name}.");
            }

            if (! isset($available[$name]) || ! in_array($name, $enabled, true)) {
                throw new InvalidArgumentException("Module is unavailable or disabled: {$name}.");
            }

            $visiting[$name] = true;
            $module = $available[$name];
            $dependencies = array_keys($module->requires);
            sort($dependencies, SORT_STRING);

            foreach ($dependencies as $dependency) {
                $visit($dependency);
            }

            if (isset($providers[$module->provider])) {
                throw new InvalidArgumentException("Duplicate module provider: {$module->provider}.");
            }

            $providers[$module->provider] = true;
            unset($visiting[$name]);
            $visited[$name] = true;
            $ordered[] = $module;
        };

        foreach ($enabled as $name) {
            $visit($name);
        }

        return $ordered;
    }
}
