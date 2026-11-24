<?php

namespace App\Media\Infrastructure;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Inert document responses; callers must first enforce association ownership and visitor access. */
final class PrivateDocumentResponse
{
    public function send(?object $file): Response
    {
        abort_if($file === null || $file->disk !== 'local' || ! Storage::disk('local')->exists($file->storage_key), 404);
        $extension = strtolower(pathinfo($file->original_name, PATHINFO_EXTENSION));
        $name = preg_replace('/[^A-Za-z0-9._ -]/', '', Str::ascii(trim($file->display_name))) ?: 'document';
        if ($extension !== '' && ! str_ends_with(strtolower($name), '.'.$extension)) {
            $name .= '.'.$extension;
        }

        return Storage::disk('local')->response($file->storage_key, $name, [
            'Content-Type' => $file->mime_type, 'X-Content-Type-Options' => 'nosniff',
            // CSP sandbox blocks Chromium's native PDF viewer. PDFs instead deny active web content and foreign framing.
            'Content-Security-Policy' => $file->mime_type === 'application/pdf' ? "default-src 'none'; frame-ancestors 'self'; base-uri 'none'; form-action 'none'" : 'sandbox',
            'Cache-Control' => 'private, no-store',
        ], in_array($file->mime_type, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true) ? 'inline' : 'attachment');
    }
}
