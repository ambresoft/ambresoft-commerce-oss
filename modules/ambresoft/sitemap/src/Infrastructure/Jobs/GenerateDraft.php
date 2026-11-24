<?php

namespace Ambresoft\Sitemap\Infrastructure\Jobs;

use Ambresoft\Sitemap\Infrastructure\Sitemap;
use App\Shared\Application\Contract\AddonStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class GenerateDraft implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    /** @param list<string> $sections */
    public function __construct(public string $actor, public int $version, public array $sections, public string $token)
    {
        // Respect the existing queue reservation, including a larger operator-configured budget.
        $this->timeout = max(1, min(1800, (int) config('queue.connections.'.config('queue.default').'.retry_after', 90) - 10));
    }

    public function handle(Sitemap $sitemap, AddonStatus $addons): void
    {
        if (! $addons->isEnabled('ambresoft/sitemap')) {
            return;
        }
        try {
            $sitemap->refreshDraft($this->actor, $this->version, $this->sections);
        } catch (Throwable $e) {
            $sitemap->failed($this->sections, $this->token, $e);
            throw $e;
        }
    }

    public function failed(?Throwable $error): void
    {
        if ($error !== null && app(AddonStatus::class)->isEnabled('ambresoft/sitemap')) {
            app(Sitemap::class)->failed($this->sections, $this->token, $error);
        }
    }
}
