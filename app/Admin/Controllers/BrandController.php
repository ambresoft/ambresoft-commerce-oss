<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Catalog\Application\Command\SaveBrandData;
use App\Catalog\Infrastructure\BrandQueries;
use App\Catalog\Infrastructure\Brands;
use App\Catalog\Infrastructure\ProductFiles;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Media\Infrastructure\Persistence\MediaFileRecord;
use App\Media\Infrastructure\PrivateDocumentResponse;
use App\Media\Infrastructure\PrivateImageStorage;
use App\Shared\Application\Command\ImageUpload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class BrandController
{
    public function index(Request $request, BrandQueries $queries): Response
    {
        Gate::authorize('brands.view');
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(['active', 'disabled'])], 'sort' => ['nullable', Rule::in(['name', 'code', 'position', 'products_count', 'status', 'updated_at'])], 'direction' => ['nullable', Rule::in(['asc', 'desc'])], 'page' => ['nullable', 'integer', 'between:1,1000000'], 'per_page' => ['nullable', Rule::in([25, 50, 100])]]);

        return Inertia::render('Admin/Brands/Index', [...$this->shell($request), 'brands' => $queries->listing($filters), 'filters' => (object) $filters]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('brands.manage');

        return Inertia::render('Admin/Brands/Form', [...$this->shell($request), 'brand' => null]);
    }

    public function edit(Request $request, BrandQueries $queries, string $brand): Response
    {
        Gate::authorize('brands.view');

        return Inertia::render('Admin/Brands/Form', [...$this->shell($request), 'brand' => $queries->editor($brand)]);
    }

    public function save(Request $request, Brands $brands, ?string $brand = null): RedirectResponse
    {
        Gate::authorize('brands.manage');
        $input = $request->validate(['version' => [$brand === null ? 'nullable' : 'required', 'integer', 'min:1'], 'code' => ['required', 'string'], 'status' => ['required', 'string'], 'position' => ['required', 'integer'], 'translations' => ['required', 'array'], 'remove_image' => ['sometimes', 'boolean'], 'image' => ['nullable', 'file', 'max:5120'], 'banner_image' => ['nullable', 'file', 'max:5120'], 'remove_banner_image' => ['sometimes', 'boolean'], 'banner_link' => ['sometimes', 'nullable', 'string', 'max:2048']]);
        $attachments = $request->validate(['files_submitted' => ['sometimes', 'boolean'], 'files' => ['sometimes', 'array', 'max:50'], 'files.*' => ['array:id,display_name,replacement'], 'files.*.id' => ['required', 'integer', 'min:1', 'distinct'], 'files.*.display_name' => ['required', 'string', 'max:255'], 'files.*.replacement' => ['nullable', 'integer', 'between:0,9'], 'file_uploads' => ['sometimes', 'array', 'max:10'], 'file_uploads.*' => ['file', 'max:'.config('products.private_files.max_kilobytes')]]);
        if ($brand !== null && (int) DB::table('brands')->where('id', $brand)->value('lock_version') !== (int) $input['version']) {
            throw ValidationException::withMessages(['version' => __('brands.stale')]);
        }
        $staged = [];
        foreach ($request->file('file_uploads', []) as $file) {
            $staged[] = app(ProductFiles::class)->stage($file);
        }
        $input = [...$input, ...array_intersect_key($attachments, array_flip(['files', 'files_submitted']))];
        $upload = $request->file('image');
        $banner = $request->file('banner_image');
        $id = $brands->save((string) $request->user()->id, $brand, new SaveBrandData($input, $upload === null ? null : new ImageUpload($upload->getPathname()), $banner === null ? null : new ImageUpload($banner->getPathname()), $staged));

        return redirect('/admin/brands/'.$id.'/edit')->with('status', __('brands.saved'));
    }

    public function status(Request $request, Brands $brands, string $brand): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'status' => ['required', Rule::in(['active', 'disabled'])]]);
        $brands->status((string) $request->user()->id, $brand, (int) $data['version'], $data['status']);

        return back()->with('status', __('brands.saved'));
    }

    public function destroy(Request $request, Brands $brands, string $brand): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $brands->delete((string) $request->user()->id, $brand, (int) $data['version']);

        return redirect('/admin/brands')->with('status', __('brands.deleted'));
    }

    public function image(string $brand, PrivateImageStorage $storage): StreamedResponse
    {
        // Product editors and layout editors may select brands without managing the brand catalog.
        abort_unless(Gate::allows('brands.view') || Gate::allows('products.update') || Gate::allows('layouts.view'), 403);
        $id = DB::table('brands')->where('id', $brand)->value('image_media_id');

        return $storage->response(MediaFileRecord::findOrFail($id));
    }

    public function bannerImage(string $brand, PrivateImageStorage $storage): StreamedResponse
    {
        Gate::authorize('brands.view');
        $id = DB::table('brands')->where('id', $brand)->value('banner_image_media_id');

        return $storage->response(MediaFileRecord::findOrFail($id));
    }

    public function file(string $brand, string $file, PrivateDocumentResponse $response): HttpResponse
    {
        Gate::authorize('brands.view');
        $row = DB::table('brand_files as bf')->join('private_product_files as f', 'f.id', 'bf.private_file_id')->where('bf.brand_id', $brand)->where('bf.id', $file)->where('f.ready', true)->first(['bf.display_name', 'f.disk', 'f.storage_key', 'f.mime_type', 'f.original_name']);

        return $response->send($row);
    }

    /** @return array<string,mixed> */
    private function shell(Request $request): array
    {
        return ['navigation' => app(AdminNavigation::class)->forUser($request->user()), 'breadcrumbs' => [['label' => __('navigation.dashboard'), 'href' => '/admin'], ['label' => __('brands.title'), 'href' => '/admin/brands']], 'defaultLocale' => app(LocaleCatalog::class)->defaultCode()];
    }
}
