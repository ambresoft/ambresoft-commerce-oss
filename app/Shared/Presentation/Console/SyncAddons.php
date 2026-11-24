<?php

namespace App\Shared\Presentation\Console;

use App\Shared\Infrastructure\Modules\AddonManager;
use Illuminate\Console\Command;

final class SyncAddons extends Command
{
    protected $signature = 'cms:addons-sync';

    protected $description = 'Install new bundled add-ons (disabled) and apply upgrades of bundled add-ons shipped with this CMS version';

    public function handle(AddonManager $addons): int
    {
        $result = $addons->sync();
        foreach ($result['installed'] as $name) {
            $this->line('Installed (disabled): '.$name);
        }
        foreach ($result['upgraded'] as $name) {
            $this->line('Upgraded: '.$name);
        }
        foreach ($result['skipped'] as $name => $reason) {
            $this->warn('Skipped '.$name.': '.$reason);
        }
        $this->info('Bundled add-ons synchronized. Enabled states were not changed.');

        return $result['skipped'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
