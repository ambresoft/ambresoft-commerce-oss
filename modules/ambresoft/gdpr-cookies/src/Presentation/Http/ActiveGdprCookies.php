<?php

namespace Ambresoft\GdprCookies\Presentation\Http;

use App\Shared\Application\Contract\AddonStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ActiveGdprCookies
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(app(AddonStatus::class)->isEnabled('ambresoft/gdpr-cookies'), 404);

        return $next($request);
    }
}
