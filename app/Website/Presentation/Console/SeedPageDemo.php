<?php

namespace App\Website\Presentation\Console;

use App\Website\Infrastructure\PageDemoSeeder;
use Illuminate\Console\Command;
use Throwable;

final class SeedPageDemo extends Command
{
    protected $signature = 'cms:pages-demo {actor : ID of an existing authorized staff user}';

    protected $description = 'Create three sample pages (privacy policy, returns and exchanges, contact us with a form) in Greek/English in local/testing only';

    public function handle(PageDemoSeeder $seeder): int
    {
        try {
            $created = $seeder->run((string) $this->argument('actor'));
            $this->info(sprintf('Created %d sample pages. Existing records were preserved.', $created['pages']));
            $this->warn('Sample texts, not legal advice. Review them before real use.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
