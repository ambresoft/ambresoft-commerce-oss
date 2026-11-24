<?php

namespace Ambresoft\ProductFeeds\Presentation\Http;

use Ambresoft\ProductFeeds\Infrastructure\Jobs\GenerateFeed;
use Ambresoft\ProductFeeds\Infrastructure\ProductFeeds;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PublicFeedController
{
    /** The last generated file, for Google Merchant Center and Meta to fetch on their schedule. */
    public function show(Request $request, ProductFeeds $feeds, string $feed): Response
    {
        $file = $feeds->file($feed);
        abort_if($file === null, 404);
        $response = response($file['xml'], 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'no-cache', 'X-Content-Type-Options' => 'nosniff']);
        $response->setEtag($file['etag']);
        $response->isNotModified($request);

        return $response;
    }

    /** The cron entry point: queues a regeneration when the feed's cron password matches. */
    public function generate(Request $request, ProductFeeds $feeds, string $feed): JsonResponse
    {
        $password = $request->query('password');
        abort_unless(is_string($password) && $feeds->cronAllowed($feed, $password), 404);
        GenerateFeed::dispatch($feed);

        return response()->json(['queued' => true], 202);
    }
}
