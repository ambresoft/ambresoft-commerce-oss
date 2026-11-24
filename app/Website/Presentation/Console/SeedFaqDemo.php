<?php

namespace App\Website\Presentation\Console;

use App\Website\Infrastructure\FaqDemoSeeder;
use Illuminate\Console\Command;
use Throwable;

final class SeedFaqDemo extends Command
{
    protected $signature = 'cms:faqs-demo {actor : ID of an existing authorized staff user}';

    protected $description = 'Create four sample FAQs in active languages, in local/testing only';

    public function handle(FaqDemoSeeder $seeder): int
    {
        try {
            $count = $seeder->run((string) $this->argument('actor'));
            $this->info(sprintf('Created %d sample FAQs. Existing records were preserved.', $count));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
