<?php

namespace App\Marketing\Presentation\Console;

use App\Marketing\Infrastructure\BannerDemoSeeder;
use Illuminate\Console\Command;
use Throwable;

final class SeedBannerDemo extends Command
{
    protected $signature = 'cms:banners-demo {actor : ID of an existing authorized staff user}';

    protected $description = 'Create two sample hero banners (Greek/English, generated backgrounds) in local/testing only';

    public function handle(BannerDemoSeeder $seeder): int
    {
        try {
            $created = $seeder->run((string) $this->argument('actor'));
            $this->info(sprintf('Created %d banner group and %d hero banners. Existing records were preserved.', $created['groups'], $created['banners']));
            $this->warn('Sample content. Edit, disable or delete it before real use.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
