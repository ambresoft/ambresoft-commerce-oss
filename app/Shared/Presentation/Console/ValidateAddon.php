<?php

namespace App\Shared\Presentation\Console;

use App\Shared\Infrastructure\Modules\AddonException;
use App\Shared\Infrastructure\Modules\AddonManager;
use App\Shared\Infrastructure\Modules\AddonPackage;
use App\Shared\Infrastructure\Modules\CmsVersion;
use App\Shared\Infrastructure\Modules\ModuleManifest;
use Illuminate\Console\Command;

/** Runs the installer's checks on an add-on folder or ZIP without installing or executing it. */
final class ValidateAddon extends Command
{
    protected $signature = 'cms:addon-validate {path : An add-on folder (containing composer.json) or a ZIP package}';

    protected $description = 'Validate an add-on package against this CMS the way the administration installer does';

    public function handle(AddonManager $addons, AddonPackage $packages): int
    {
        $path = (string) $this->argument('path');
        $staged = null;
        try {
            if (is_dir($path)) {
                $directory = rtrim($path, '/');
                $manifest = ModuleManifest::fromDirectory($directory);
            } else {
                $staged = $packages->stage($path);
                [$directory, $manifest] = [$staged['directory'], $staged['manifest']];
            }
            $addons->checkPackage($manifest, $directory, $manifest->name);
        } catch (AddonException $e) {
            $this->error($e->translated());

            return self::FAILURE;
        } finally {
            if ($staged !== null) {
                $packages->discard($staged['directory']);
            }
        }
        $this->info($manifest->title.' ('.$manifest->name.' '.$manifest->version.') is a valid add-on for CMS '.CmsVersion::CURRENT.'.');
        foreach ($manifest->contributes as $point => $declared) {
            $this->line('Contributes '.$point.': '.implode(', ', array_keys((array) $declared)));
        }

        return self::SUCCESS;
    }
}
