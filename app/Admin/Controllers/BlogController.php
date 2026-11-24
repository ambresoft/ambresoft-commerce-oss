<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Media\Infrastructure\Persistence\MediaFileRecord;
use App\Media\Infrastructure\PrivateImageStorage;
use App\Settings\Infrastructure\AiSettings;
use App\Shared\Application\Command\ImageUpload;
use App\Website\Domain\Service\PageRules;
use App\Website\Infrastructure\BlogAdministration;
use App\Website\Infrastructure\BlogAiGenerator;
use App\Website\Infrastructure\BlogQueries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Website → Blog: blogs (landing pages) at /admin/blogs and their posts at /admin/blog-posts; the route sets the kind. */
final class BlogController
{
    private const PATHS = ['blog' => '/admin/blogs', 'post' => '/admin/blog-posts'];

    public function index(Request $request, BlogQueries $queries): Response
    {
        Gate::authorize('blog.view');
        $kind = $this->kind($request);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(PageRules::STATUSES)], 'blog' => ['nullable', 'integer', 'min:1'], 'page' => ['nullable', 'integer', 'between:1,1000000'], 'per_page' => ['nullable', Rule::in([25, 50, 100])]]);

        return Inertia::render('Admin/Blogs/Index', [...$this->shell($request, $kind), 'kind' => $kind, 'rows' => $queries->listing($kind, $filters), 'filters' => (object) $filters, 'blogs' => $kind === 'post' ? $queries->blogOptions() : []]);
    }

    public function create(Request $request, BlogQueries $queries): Response
    {
        Gate::authorize('blog.manage');
        $kind = $this->kind($request);
        $blog = $request->validate(['blog' => ['nullable', 'integer', 'min:1']])['blog'] ?? null;

        return Inertia::render('Admin/Blogs/Form', [...$this->shell($request, $kind, __($kind === 'blog' ? 'blogs.create' : 'blogs.create_post')), ...$this->editorProps($queries, $kind), 'item' => null, 'initialBlog' => $blog === null ? null : (string) $blog]);
    }

    public function edit(Request $request, BlogQueries $queries): Response
    {
        Gate::authorize('blog.view');
        $kind = $this->kind($request);
        $item = $queries->editor($kind, (string) $request->route('id')) ?? abort(404);

        return Inertia::render('Admin/Blogs/Form', [...$this->shell($request, $kind, __($kind === 'blog' ? 'blogs.edit' : 'blogs.edit_post')), ...$this->editorProps($queries, $kind), 'item' => $item, 'initialBlog' => null]);
    }

    public function save(Request $request, BlogAdministration $blogs): RedirectResponse
    {
        Gate::authorize('blog.manage');
        $kind = $this->kind($request);
        // Route defaults (the kind) would otherwise fill positional parameters.
        $id = $request->route('id');
        $version = $request->validate(['version' => [$id === null ? 'nullable' : 'required', 'integer', 'min:1'], 'cover' => ['nullable', 'file', 'max:5120'], 'cover_mobile' => ['nullable', 'file', 'max:5120']])['version'] ?? null;
        $upload = fn (string $field): ?ImageUpload => $kind === 'post' && $request->file($field) !== null ? new ImageUpload($request->file($field)->getPathname()) : null;
        $id = $blogs->save($kind, (string) $request->user()->id, $id, $version === null ? null : (int) $version, $request->except(['cover', 'cover_mobile']), $upload('cover'), $upload('cover_mobile'));

        return redirect(self::PATHS[$kind].'/'.$id.'/edit')->with('status', __('blogs.saved'));
    }

    public function destroy(Request $request, BlogAdministration $blogs): RedirectResponse
    {
        Gate::authorize('blog.manage');
        $kind = $this->kind($request);
        $version = (int) $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];
        $blogs->delete($kind, (string) $request->user()->id, (string) $request->route('id'), $version);

        return redirect(self::PATHS[$kind])->with('status', __('blogs.deleted'));
    }

    public function bulk(Request $request, BlogAdministration $blogs): RedirectResponse
    {
        Gate::authorize('blog.manage');
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['integer', 'min:1'], 'action' => ['required', Rule::in([...PageRules::STATUSES, 'delete'])]]);
        $count = $blogs->bulk($this->kind($request), (string) $request->user()->id, array_map('strval', $data['ids']), $data['action']);

        return back()->with('status', trans_choice('blogs.bulk_done', $count, ['count' => $count]));
    }

    public function cover(PrivateImageStorage $storage, string $id): StreamedResponse
    {
        Gate::authorize('blog.view');

        return $storage->response(MediaFileRecord::findOrFail(DB::table('blog_posts')->where('id', $id)->value('cover_media_id') ?? abort(404)));
    }

    public function coverMobile(PrivateImageStorage $storage, string $id): StreamedResponse
    {
        Gate::authorize('blog.view');

        return $storage->response(MediaFileRecord::findOrFail(DB::table('blog_posts')->where('id', $id)->value('cover_mobile_media_id') ?? abort(404)));
    }

    public function assistant(Request $request, BlogAiGenerator $generator): JsonResponse
    {
        return response()->json($generator->generate((string) $request->user()->id, $request->only(['locale', 'post_id', 'messages', 'current'])));
    }

    private function kind(Request $request): string
    {
        return $request->route('kind') === 'post' ? 'post' : 'blog';
    }

    /** @return array<string, mixed> */
    private function editorProps(BlogQueries $queries, string $kind): array
    {
        return ['kind' => $kind, 'blogs' => $kind === 'post' ? $queries->blogOptions() : [], 'facebookTypes' => PageRules::FACEBOOK_TYPES, 'defaultLocale' => app(LocaleCatalog::class)->defaultCode(),
            'aiReady' => $kind === 'post' && Gate::allows('blog.generate') && (fn (array $ai): bool => $ai['enabled'] && $ai['has_key'])(app(AiSettings::class)->all())];
    }

    /** @return array<string, mixed> */
    private function shell(Request $request, string $kind, ?string $current = null): array
    {
        $list = __($kind === 'blog' ? 'blogs.title' : 'blogs.posts_title');
        $breadcrumbs = [['label' => __('navigation.dashboard'), 'href' => '/admin'], ['label' => $list, 'href' => $current === null ? null : self::PATHS[$kind]]];
        if ($current !== null) {
            $breadcrumbs[] = ['label' => $current, 'href' => null];
        }

        return ['navigation' => app(AdminNavigation::class)->forUser($request->user()), 'breadcrumbs' => $breadcrumbs];
    }
}
