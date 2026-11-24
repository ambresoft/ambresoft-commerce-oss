<?php

namespace App\Customer\Presentation\Console;

use App\Customer\Infrastructure\CustomerDemoSeeder;
use Illuminate\Console\Command;
use Throwable;

final class SeedCustomerDemo extends Command
{
    protected $signature = 'cms:customers-demo {actor : ID of an existing authorized staff user}';

    protected $description = 'Create fictitious sample customers with Greek addresses in local/testing only';

    public function handle(CustomerDemoSeeder $seeder): int
    {
        try {
            $created = $seeder->run((string) $this->argument('actor'));
            $this->info(sprintf('Created %d customer groups, %d customers and %d blacklist entries. Existing records were preserved.', $created['groups'], $created['customers'], $created['blacklist']));
            $this->warn('Fictitious sample data (example.test addresses). Delete or disable it before real use.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
