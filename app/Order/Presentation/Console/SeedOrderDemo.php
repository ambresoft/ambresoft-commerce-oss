<?php

namespace App\Order\Presentation\Console;

use App\Order\Infrastructure\OrderDemoSeeder;
use Illuminate\Console\Command;
use Throwable;

final class SeedOrderDemo extends Command
{
    protected $signature = 'cms:orders-demo {actor : ID of an existing authorized staff user}';

    protected $description = 'Create one fictitious sample order per order status in local/testing only';

    public function handle(OrderDemoSeeder $seeder): int
    {
        try {
            $created = $seeder->run((string) $this->argument('actor'));
            $this->info(sprintf('Created %d sample orders. Existing orders were preserved.', $created));
            $this->warn('Sample orders use real products and move their stock like any order. Cancel or delete them before real use.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
