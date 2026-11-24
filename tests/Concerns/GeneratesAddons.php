<?php

namespace Tests\Concerns;

use App\Shared\Infrastructure\Modules\AddonManager;
use App\Shared\Infrastructure\Modules\AddonPackage;
use Illuminate\Support\Str;
use ZipArchive;

/** Installs and enables a throwaway add-on through the real upload path, in a temporary add-ons folder. */
trait GeneratesAddons
{
    private ?string $addonFolder = null;

    /**
     * @param  array<string, string>  $files  Paths inside the package => contents; `{ns}` is the add-on's namespace
     * @param  array<string, mixed>  $module  Extra `extra.cms-module` keys; `{ns}` works in contribution classes
     * @return string The add-on's namespace
     */
    private function installAddon(string $name, array $files, array $module = []): string
    {
        $this->addonFolder ??= storage_path('framework/testing/generated-addons-'.Str::random(12));
        @mkdir($this->addonFolder.'/installed', 0750, true);
        config(['modules.addons_path' => $this->addonFolder.'/installed', 'modules.uploads' => true]);
        $namespace = 'GeneratedAddon'.Str::random(12);
        $archive = new ZipArchive;
        $archive->open($file = $this->addonFolder.'/'.Str::random(8).'.zip', ZipArchive::CREATE);
        $manifest = ['name' => $name, 'version' => '1.0.0', 'autoload' => ['psr-4' => [$namespace.'\\' => 'src/']],
            'extra' => ['cms-module' => ['contract' => 3, 'title' => 'Generated', 'cms' => '^1.0', 'provider' => $namespace.'\\Provider', 'requires' => [], ...$module]]];
        $archive->addFromString('composer.json', str_replace('{ns}', str_replace('\\', '\\\\', $namespace), (string) json_encode($manifest)));
        foreach ($files as $path => $contents) {
            $archive->addFromString($path, str_replace('{ns}', $namespace, $contents));
        }
        $archive->close();
        $manager = app(AddonManager::class);
        $manager->upload(null, $file, false);
        $manager->enable(null, $name);

        return $namespace;
    }

    private function removeGeneratedAddons(): void
    {
        if ($this->addonFolder !== null) {
            AddonPackage::remove($this->addonFolder);
        }
    }
}
