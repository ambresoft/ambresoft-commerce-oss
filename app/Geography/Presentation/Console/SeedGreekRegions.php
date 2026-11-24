<?php

namespace App\Geography\Presentation\Console;

use App\Geography\Infrastructure\GreekRegions;
use Illuminate\Console\Command;
use Throwable;

final class SeedGreekRegions extends Command
{
    protected $signature = 'cms:geography-greece {actor : ID of an existing authorized staff user}';

    protected $description = 'Enable Greece for shipping and add its 51 nomoi (Greek and English names); safe to repeat';

    public function handle(GreekRegions $greece): int
    {
        try {
            $result = $greece->run((string) $this->argument('actor'));
            $this->info(sprintf('Greece ships; %d nomoi added, %d already existed and were left unchanged.', $result['created'], $result['existing']));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
