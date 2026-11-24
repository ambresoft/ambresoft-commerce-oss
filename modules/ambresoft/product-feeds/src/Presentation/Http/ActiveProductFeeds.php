<?php

namespace Ambresoft\ProductFeeds\Presentation\Http;

use App\Shared\Application\Contract\AddonStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ActiveProductFeeds
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(app(AddonStatus::class)->isEnabled('ambresoft/product-feeds'), 404);

        return $next($request);
    }
}
