<?php

namespace Ambresoft\GdprCookies\Presentation\Http;

use Ambresoft\GdprCookies\Infrastructure\GdprCookies;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class ConsentController
{
    /** Proof of a visitor's decision; the choice itself already lives in their consent cookie. */
    public function store(Request $request, GdprCookies $cookies): Response
    {
        $cookies->record($request->only(['consent_id', 'version', 'preferences', 'statistics', 'marketing', 'action']));

        return response()->noContent();
    }
}
