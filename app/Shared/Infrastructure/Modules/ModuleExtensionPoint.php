<?php

namespace App\Shared\Infrastructure\Modules;

/**
 * A published place where add-ons contribute through their manifest (`extra.cms-module.contributes.{key}`). Core
 * contexts own their extension points; the host only routes declarations to them.
 */
interface ModuleExtensionPoint
{
    /**
     * Checks an add-on's declarations before it is installed or enabled, without executing add-on code.
     *
     * @param  list<ModuleManifest>  $others  Manifests of the other installed add-ons
     *
     * @throws AddonException
     */
    public function validate(ModuleManifest $manifest, mixed $declared, string $directory, array $others): void;

    /** Registers the declarations of an enabled add-on whose provider has been registered. */
    public function register(ModuleManifest $manifest, mixed $declared): void;
}
