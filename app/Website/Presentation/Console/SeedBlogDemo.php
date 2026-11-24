<?php

namespace App\Website\Presentation\Console;

use App\Website\Infrastructure\BlogDemoSeeder;
use Illuminate\Console\Command;
use Throwable;

final class SeedBlogDemo extends Command
{
    protected $signature = 'cms:blog-demo {actor : ID of an existing authorized staff user}';

    protected $description = 'Create two sample blogs with eight posts (Greek/English, generated covers) in local/testing only';

    public function handle(BlogDemoSeeder $seeder): int
    {
        try {
            $created = $seeder->run((string) $this->argument('actor'));
            $this->info(sprintf('Created %d sample blogs and %d posts. Existing records were preserved.', $created['blogs'], $created['posts']));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
