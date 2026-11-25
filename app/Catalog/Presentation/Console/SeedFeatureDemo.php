<?php

namespace App\Catalog\Presentation\Console;

use App\Catalog\Infrastructure\FeatureDemoSeeder;
use Illuminate\Console\Command;
use Throwable;

final class SeedFeatureDemo extends Command
{
    protected $signature = 'cms:features-demo {actor : ID of an existing authorized staff user}';

    protected $description = 'Create the non-destructive Phase 5 ten-product demo in local/testing only';

    public function handle(FeatureDemoSeeder $seeder): int
    {
        try {
            $created = $seeder->run((string) $this->argument('actor'));
            $this->info('Created '.$created.' demo products. Existing mapped records were preserved.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
