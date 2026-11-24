<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Marketing\Infrastructure\CouponQueries;
use App\Marketing\Infrastructure\ReelAdministration;
use App\Marketing\Infrastructure\ReelQueries;
use App\Media\Infrastructure\Persistence\MediaFileRecord;
use App\Media\Infrastructure\PrivateImageStorage;
use App\Media\Infrastructure\PrivateVideoStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ReelController
{
    public function index(Request $request, ReelQueries $queries): Response
    {
        Gate::authorize('reels.view');
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(['active', 'disabled'])], 'sort' => ['nullable', Rule::in(['newest', 'views'])], 'page' => ['nullable', 'integer', 'between:1,1000000']]);

        return Inertia::render('Admin/Reels/Index', [...$this->shell($request), 'rows' => $queries->listing($filters), 'filters' => (object) $filters]);
    }

    public function form(Request $request, ReelQueries $queries, ?string $reel = null): Response
    {
        Gate::authorize($reel === null ? 'reels.manage' : 'reels.view');
        $item = $reel === null ? null : ($queries->editor($reel) ?? abort(404));
        $input = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'selected' => ['nullable', 'array', 'max:24'], 'selected.*' => ['integer', 'min:1']]);

        return Inertia::render('Admin/Reels/Form', [...$this->shell($request, __('reels.'.($reel === null ? 'create' : 'edit'))), 'item' => $item, 'defaultLocale' => app(LocaleCatalog::class)->defaultCode(), 'videoMaxBytes' => ReelAdministration::MAX_VIDEO_BYTES, 'options' => fn () => app(CouponQueries::class)->options('product', $input['search'] ?? '', array_unique([...($input['selected'] ?? []), ...($item['products'] ?? [])]))]);
    }

    public function save(Request $request, ReelAdministration $reels, ?string $reel = null): RedirectResponse
    {
        Gate::authorize('reels.manage');
        // Multipart forms omit empty arrays; no product links is a valid reel.
        $request->mergeIfMissing(['products' => []]);
        $request->validate(['version' => [$reel === null ? 'nullable' : 'required', 'integer', 'min:1']]);
        $id = $reels->save((string) $request->user()->id, $reel, $request->except(['video', 'cover']), $request->file('video'), $request->file('cover'));

        return redirect('/admin/reels/'.$id.'/edit')->with('status', __('reels.saved'));
    }

    public function status(Request $request, ReelAdministration $reels, string $reel): RedirectResponse
    {
        Gate::authorize('reels.manage');
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'status' => ['required', Rule::in(['active', 'disabled'])]]);
        $reels->status((string) $request->user()->id, $reel, (int) $data['version'], $data['status']);

        return back()->with('status', __('reels.saved'));
    }

    public function destroy(Request $request, ReelAdministration $reels, string $reel): RedirectResponse
    {
        Gate::authorize('reels.manage');
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $reels->delete((string) $request->user()->id, $reel, (int) $data['version']);

        return redirect('/admin/reels')->with('status', __('reels.deleted'));
    }

    public function cover(string $reel, PrivateImageStorage $storage): StreamedResponse
    {
        Gate::authorize('reels.view');

        return $storage->response(MediaFileRecord::findOrFail(DB::table('reels')->where('id', $reel)->value('cover_media_id') ?? abort(404)));
    }

    public function video(string $reel, PrivateVideoStorage $storage): BinaryFileResponse
    {
        Gate::authorize('reels.view');

        return $storage->response((string) (DB::table('reels')->where('id', $reel)->value('video_id') ?? abort(404)));
    }

    public function history(Request $request, ReelQueries $queries, string $reel): Response
    {
        Gate::authorize('reels.view');
        Gate::authorize('reels.analytics');
        $item = $queries->editor($reel) ?? abort(404);
        $filters = $request->validate(['user' => ['nullable', 'integer', 'min:1'], 'registered' => ['nullable', 'boolean'], 'page' => ['nullable', 'integer', 'between:1,1000000']]);
        $base = DB::table('reel_views')->where('reel_id', $reel);
        $stats = ['total' => (clone $base)->count(), 'registered' => (clone $base)->whereNotNull('user_id')->distinct()->count('user_id'), 'anonymous' => (clone $base)->whereNull('user_id')->count()];
        if (isset($filters['user'])) {
            $base->where('user_id', $filters['user']);
        }
        if ($filters['registered'] ?? false) {
            $base->whereNotNull('user_id');
        }
        $rows = $base->leftJoin('users', 'users.id', 'reel_views.user_id')->orderByDesc('viewed_at')->orderByDesc('reel_views.id')->select(['reel_views.id', 'user_id', 'users.name', 'users.email', 'viewed_at'])->paginate(25)->withQueryString();

        return Inertia::render('Admin/Reels/History', [...$this->shell($request, __('reels.history')), 'item' => ['id' => $reel, 'name' => $item['name']], 'rows' => $rows, 'stats' => $stats, 'filters' => (object) $filters]);
    }

    /** @return array<string,mixed> */
    private function shell(Request $request, ?string $current = null): array
    {
        $breadcrumbs = [['label' => __('navigation.dashboard'), 'href' => '/admin'], ['label' => __('reels.title'), 'href' => $current === null ? null : '/admin/reels']];
        if ($current !== null) {
            $breadcrumbs[] = ['label' => $current, 'href' => null];
        }

        return ['navigation' => app(AdminNavigation::class)->forUser($request->user()), 'breadcrumbs' => $breadcrumbs];
    }
}
