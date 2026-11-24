<?php

namespace App\Marketing\Presentation\Console;

use App\Marketing\Infrastructure\NewsletterDemoSeeder;
use Illuminate\Console\Command;
use Throwable;

final class SeedNewsletterDemo extends Command
{
    protected $signature = 'cms:newsletters-demo {actor : ID of an existing authorized staff user}';

    protected $description = 'Create sample mailing lists, templates, a campaign, newsletters and subscribers in local/testing only (nothing is sent)';

    public function handle(NewsletterDemoSeeder $seeder): int
    {
        try {
            $created = $seeder->run((string) $this->argument('actor'));
            $this->info(sprintf('Created %d lists, %d templates, %d autoresponders, %d newsletters and %d subscribers. Existing records were preserved.', $created['lists'], $created['templates'], $created['autoresponders'], $created['newsletters'], $created['subscribers']));
            $this->warn('Sample content on example.test addresses. Nothing was sent. Edit or delete it before real use.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
