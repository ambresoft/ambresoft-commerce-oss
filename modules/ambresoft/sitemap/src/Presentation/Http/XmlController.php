<?php

namespace Ambresoft\Sitemap\Presentation\Http;

use Ambresoft\Sitemap\Infrastructure\Sitemap;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class XmlController
{
    public function index(Request $request, Sitemap $sitemap): Response
    {
        return $this->response($request, $sitemap->file(null, 'sitemap.xml'), false);
    }

    public function part(Request $request, Sitemap $sitemap, string $version, string $part): Response
    {
        return $this->response($request, $sitemap->file($version, 'sitemap-'.$version.'-'.$part.'.xml'), true);
    }

    /** @param array{xml:string,etag:string}|null $file */
    private function response(Request $request, ?array $file, bool $immutable): Response
    {
        abort_if($file === null, 404);
        $response = response($file['xml'], 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => $immutable ? 'public, max-age=31536000, immutable' : 'no-cache', 'X-Content-Type-Options' => 'nosniff']);
        $response->setEtag($file['etag']);
        $response->isNotModified($request);

        return $response;
    }
}
