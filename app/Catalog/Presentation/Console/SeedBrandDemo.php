<?php

namespace App\Catalog\Presentation\Console;

use App\Catalog\Infrastructure\BrandDemoSeeder;
use Illuminate\Console\Command;

final class SeedBrandDemo extends Command
{
    protected $signature = 'cms:brands-demo {actor : ID of an existing authorized staff user}';

    protected $description = 'Assign recognizable local catalog products to sample brands without creating products or orders';

    public function handle(BrandDemoSeeder $seeder): int
    {
        $this->info('Assigned '.$seeder->run((string) $this->argument('actor')).' products. Existing brands and assignments were preserved.');

        return self::SUCCESS;
    }
}
