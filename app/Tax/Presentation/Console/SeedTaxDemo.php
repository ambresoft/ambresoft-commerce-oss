<?php

namespace App\Tax\Presentation\Console;

use App\Tax\Infrastructure\TaxDemoSeeder;
use Illuminate\Console\Command;
use Throwable;

final class SeedTaxDemo extends Command
{
    protected $signature = 'cms:taxes-demo {actor : ID of an existing authorized staff user}';

    protected $description = 'Create sample Greek rate areas and taxes (illustrative figures) in local/testing only';

    public function handle(TaxDemoSeeder $seeder): int
    {
        try {
            $created = $seeder->run((string) $this->argument('actor'));
            $this->info(sprintf('Created %d rate areas and %d taxes; assigned the standard sample VAT to %d demo products. Existing records were preserved.', $created['areas'], $created['taxes'], $created['products']));
            $this->warn('Sample figures only: they are not legal tax rates. Review or delete them before real use.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
