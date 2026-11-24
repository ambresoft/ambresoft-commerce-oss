<?php

namespace Ambresoft\ProductFeeds\Infrastructure\Jobs;

use Ambresoft\ProductFeeds\Infrastructure\ProductFeeds;
use App\Shared\Application\Contract\AddonStatus;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** One generation per feed at a time; a cron call while one is queued or running is a no-op. */
final class GenerateFeed implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 3600;

    public function __construct(public string $feed)
    {
        // Respect the existing queue reservation, including a larger operator-configured budget.
        $this->timeout = max(1, min(1800, (int) config('queue.connections.'.config('queue.default').'.retry_after', 90) - 10));
    }

    public function uniqueId(): string
    {
        return $this->feed;
    }

    public function handle(ProductFeeds $feeds): void
    {
        if (app(AddonStatus::class)->isEnabled('ambresoft/product-feeds')) {
            $feeds->generate($this->feed);
        }
    }

    public function failed(?Throwable $error): void
    {
        if ($error !== null && app(AddonStatus::class)->isEnabled('ambresoft/product-feeds')) {
            report($error);
            app(ProductFeeds::class)->fail($this->feed, __('cms-product-feeds::ui.error_failed'));
        }
    }
}
