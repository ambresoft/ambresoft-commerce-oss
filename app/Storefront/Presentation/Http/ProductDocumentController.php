<?php

namespace App\Storefront\Presentation\Http;

use App\Catalog\Infrastructure\PublicCatalog;
use App\Media\Infrastructure\PrivateDocumentResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * A product's documents (attachments: size charts, manuals, information sheets) for storefront visitors: only while the
 * product is visible to them, never buyer downloads, never storage keys. PDFs and pictures open in the browser;
 * other types are downloaded.
 */
final class ProductDocumentController
{
    public function __invoke(Request $request, string $product, string $file): Response
    {
        $customer = $request->user() === null ? null : DB::table('customers')->where('user_id', $request->user()->getAuthIdentifier())->where('status', 'active')->value('id');
        abort_unless(app(PublicCatalog::class)->products($customer === null ? null : (string) $customer)->where('products.id', $product)->exists(), 404);
        $row = DB::table('product_files as pf')->join('private_product_files as f', 'f.id', '=', 'pf.private_file_id')
            ->where('pf.product_id', $product)->where('pf.id', $file)->where('pf.purpose', 'attachment')->where('f.ready', true)
            ->first(['pf.display_name', 'f.disk', 'f.storage_key', 'f.mime_type', 'f.original_name']);

        return app(PrivateDocumentResponse::class)->send($row);
    }

    public function brand(Request $request, string $product, string $file): Response
    {
        $customer = $request->user() === null ? null : DB::table('customers')->where('user_id', $request->user()->getAuthIdentifier())->where('status', 'active')->value('id');
        $visible = app(PublicCatalog::class)->products($customer === null ? null : (string) $customer)->where('products.id', $product)->first(['products.brand_id']);
        abort_if($visible === null || $visible->brand_id === null, 404);
        $row = DB::table('brand_files as bf')->join('brands as b', 'b.id', 'bf.brand_id')->join('private_product_files as f', 'f.id', 'bf.private_file_id')
            ->where('bf.brand_id', $visible->brand_id)->where('b.status', 'active')->where('bf.id', $file)->where('f.ready', true)
            ->first(['bf.display_name', 'f.disk', 'f.storage_key', 'f.mime_type', 'f.original_name']);

        return app(PrivateDocumentResponse::class)->send($row);
    }
}
