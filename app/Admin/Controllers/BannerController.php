<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Marketing\Domain\Service\BannerRules;
use App\Marketing\Infrastructure\BannerAdministration;
use App\Marketing\Infrastructure\BannerAiGenerator;
use App\Marketing\Infrastructure\BannerQueries;
use App\Media\Infrastructure\Persistence\MediaFileRecord;
use App\Media\Infrastructure\PrivateImageStorage;
use App\Media\Infrastructure\PrivateVideoStorage;
use App\Settings\Infrastructure\AiSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class BannerController
{
    public function index(Request $request, BannerQueries $queries): Response
    {
        Gate::authorize('banners.view');
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'], 'group' => ['nullable', 'regex:/^(none|[0-9]{1,18})$/'],
            'state' => ['nullable', Rule::in(['live', 'scheduled', 'expired', 'disabled'])], 'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);

        return Inertia::render('Admin/Banners/Index', [...$this->shell($request), 'banners' => $queries->listing($filters), 'groups' => $queries->groups(), 'filters' => (object) $filters]);
    }

    public function create(Request $request, BannerQueries $queries): Response
    {
        Gate::authorize('banners.manage');

        return Inertia::render('Admin/Banners/Form', [...$this->shell($request, __('banners.create')), ...$this->editorProps($request, $queries), 'banner' => null]);
    }

    public function store(Request $request, BannerAdministration $banners): RedirectResponse
    {
        Gate::authorize('banners.manage');
        $id = $banners->save((string) $request->user()->id, null, null, $request->all(), (array) $request->file('contents', []));

        return redirect('/admin/banners/'.$id.'/edit')->with('status', __('banners.saved'));
    }

    public function edit(Request $request, BannerQueries $queries, string $banner): Response
    {
        Gate::authorize('banners.view');
        $editor = $queries->editor($banner) ?? abort(404);

        return Inertia::render('Admin/Banners/Form', [...$this->shell($request, __('banners.edit')), ...$this->editorProps($request, $queries), 'banner' => $editor]);
    }

    public function update(Request $request, BannerAdministration $banners, string $banner): RedirectResponse
    {
        Gate::authorize('banners.manage');
        $version = (int) $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];
        $banners->save((string) $request->user()->id, $banner, $version, $request->all(), (array) $request->file('contents', []));

        return redirect('/admin/banners/'.$banner.'/edit')->with('status', __('banners.saved'));
    }

    public function destroy(Request $request, BannerAdministration $banners, string $banner): RedirectResponse
    {
        Gate::authorize('banners.manage');
        $version = (int) $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];
        $banners->delete((string) $request->user()->id, $banner, $version);

        return redirect('/admin/banners')->with('status', __('banners.deleted'));
    }

    public function clone(Request $request, BannerAdministration $banners, string $banner): RedirectResponse
    {
        Gate::authorize('banners.manage');
        $id = $banners->clone((string) $request->user()->id, $banner);

        return redirect('/admin/banners/'.$id.'/edit')->with('status', __('banners.cloned'));
    }

    public function generate(Request $request, BannerAiGenerator $generator): JsonResponse
    {
        return response()->json($generator->generate((string) $request->user()->id, $request->only(['banner_id', 'messages', 'current'])));
    }

    public function bulk(Request $request, BannerAdministration $banners): RedirectResponse
    {
        Gate::authorize('banners.manage');
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'], 'ids.*' => ['integer', 'min:1'],
            'action' => ['required', Rule::in(['activate', 'disable', 'group', 'ungroup', 'delete'])], 'group' => ['nullable', 'integer', 'min:1'],
        ]);
        $count = $banners->bulk((string) $request->user()->id, array_map('strval', $data['ids']), $data['action'], isset($data['group']) ? (string) $data['group'] : null);

        return back()->with('status', trans_choice('banners.bulk_done', $count, ['count' => $count]));
    }

    public function storeGroup(Request $request, BannerAdministration $banners): RedirectResponse
    {
        Gate::authorize('banners.manage');
        $banners->createGroup((string) $request->user()->id, (string) $request->validate(['name' => ['required', 'string', 'max:120']])['name']);

        return back()->with('status', __('banners.group_saved'));
    }

    public function updateGroup(Request $request, BannerAdministration $banners, string $group): RedirectResponse
    {
        Gate::authorize('banners.manage');
        $banners->renameGroup((string) $request->user()->id, $group, (string) $request->validate(['name' => ['required', 'string', 'max:120']])['name']);

        return back()->with('status', __('banners.group_saved'));
    }

    public function destroyGroup(Request $request, BannerAdministration $banners, string $group): RedirectResponse
    {
        Gate::authorize('banners.manage');
        $banners->disbandGroup((string) $request->user()->id, $group);

        return back()->with('status', __('banners.group_disbanded'));
    }

    public function media(BannerQueries $queries, string $banner, string $media): StreamedResponse
    {
        Gate::authorize('banners.view');
        abort_unless($queries->ownsImage($banner, $media), 404);

        return app(PrivateImageStorage::class)->response(MediaFileRecord::findOrFail($media));
    }

    public function video(BannerQueries $queries, string $banner, string $video): BinaryFileResponse
    {
        Gate::authorize('banners.view');
        abort_unless($queries->ownsVideo($banner, $video), 404);

        return app(PrivateVideoStorage::class)->response($video);
    }

    /** @return array<string, mixed> */
    private function editorProps(Request $request, BannerQueries $queries): array
    {
        $search = $request->validate(['product_search' => ['nullable', 'string', 'max:100']])['product_search'] ?? '';

        return [
            'groups' => $queries->groups(), 'customerGroups' => $queries->customerGroups(),
            'candidates' => fn () => $queries->productCandidates((string) $search),
            'defaultLocale' => app(LocaleCatalog::class)->defaultCode(),
            'layoutDefaults' => BannerRules::LAYOUT_DEFAULTS, 'cssPresets' => BannerRules::CSS_PRESETS,
            'videoMaxBytes' => PrivateVideoStorage::MAX_BYTES,
            'aiReady' => Gate::allows('banners.generate') && (fn (array $ai): bool => $ai['enabled'] && $ai['has_key'])(app(AiSettings::class)->all()),
        ];
    }

    /** @return array<string, mixed> */
    private function shell(Request $request, ?string $current = null): array
    {
        $breadcrumbs = [['label' => __('navigation.dashboard'), 'href' => '/admin'], ['label' => __('banners.title'), 'href' => $current === null ? null : '/admin/banners']];
        if ($current !== null) {
            $breadcrumbs[] = ['label' => $current, 'href' => null];
        }

        return ['navigation' => app(AdminNavigation::class)->forUser($request->user()), 'breadcrumbs' => $breadcrumbs];
    }
}
