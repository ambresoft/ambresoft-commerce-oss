<?php

namespace Ambresoft\Sitemap\Infrastructure\Jobs;

use Ambresoft\Sitemap\Infrastructure\Sitemap;
use App\Shared\Application\Contract\AddonStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class RefreshSection implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public string $section, public string $token)
    {
        // Respect the existing queue reservation, including a larger operator-configured budget.
        $this->timeout = max(1, min(1800, (int) config('queue.connections.'.config('queue.default').'.retry_after', 90) - 10));
    }

    public function handle(Sitemap $sitemap): void
    {
        try {
            $sitemap->refreshPublished($this->section, $this->token);
        } catch (Throwable $e) {
            $sitemap->failed([$this->section], $this->token, $e);
            throw $e;
        }
    }

    public function failed(?Throwable $error): void
    {
        if ($error !== null && app(AddonStatus::class)->isEnabled('ambresoft/sitemap')) {
            app(Sitemap::class)->failed([$this->section], $this->token, $error);
        }
    }
}
