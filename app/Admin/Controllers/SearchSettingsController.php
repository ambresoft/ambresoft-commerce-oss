<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Search\Infrastructure\Jobs\ProcessSearchOutbox;
use App\Search\Infrastructure\Jobs\ReconcileSearchIndex;
use App\Search\Infrastructure\SearchDocuments;
use App\Search\Infrastructure\SearchIndex;
use App\Search\Infrastructure\SearchRecovery;
use App\Search\Infrastructure\SearchSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Search (`search.manage`): the active search add-on's health and settings, the index's sync state, full
 * rebuilds and reconciliation. Long work is queued; the page shows the recorded state of that work, never an estimate.
 */
final class SearchSettingsController
{
    public function show(Request $request, SearchSettings $settings, SearchDocuments $documents): Response
    {
        Gate::authorize('search.manage');
        $provider = $settings->provider();
        $state = DB::table('search_index_state')->where('id', 1)->first();
        $time = fn ($value): ?string => $value === null ? null : CarbonImmutable::parse($value)->utc()->toIso8601String();
        $engine = null;
        if ($provider !== null) {
            $values = $settings->values($provider);
            $health = $provider->health($values, $settings->languages());
            $engine = [
                'id' => $provider->id(), 'name' => $provider->name(), 'version' => (int) $settings->row($provider)->lock_version,
                'fields' => $settings->fieldValues()->editor($provider->settings(), $values),
                'health' => ['reachable' => $health->reachable, 'ready' => $health->ready, 'current' => $health->current, 'documents' => $health->documents,
                    'index' => $health->index, 'version' => $health->version, 'message' => $health->message],
                'built' => $state->built_provider === $provider->id(),
            ];
        }
        $outbox = DB::table('search_outbox')->selectRaw('count(*) FILTER (WHERE failed_at IS NULL) AS pending, count(*) FILTER (WHERE critical) AS critical, count(*) FILTER (WHERE failed_at IS NOT NULL) AS failed, min(queued_at) FILTER (WHERE failed_at IS NULL) AS oldest')->first();

        return Inertia::render('Admin/Settings/Search', [
            'navigation' => app(AdminNavigation::class)->forUser($request->user()),
            'breadcrumbs' => [['label' => __('navigation.dashboard'), 'href' => '/admin'], ['label' => __('languages.settings'), 'href' => null], ['label' => __('search.title'), 'href' => null]],
            'recovery' => app(SearchRecovery::class)->status(),
            'engine' => $engine,
            'catalog' => ['eligible' => $documents->eligible()->count()],
            'outbox' => ['pending' => (int) $outbox->pending, 'critical' => (int) $outbox->critical, 'failed' => (int) $outbox->failed, 'oldest' => $time($outbox->oldest),
                'errors' => DB::table('search_outbox')->whereNotNull('last_error')->orderByDesc('queued_at')->limit(5)->get(['product_id', 'attempts', 'last_error', 'failed_at'])
                    ->map(fn (object $r): array => ['product' => (string) $r->product_id, 'attempts' => (int) $r->attempts, 'message' => (string) $r->last_error, 'failed' => $r->failed_at !== null])->all()],
            'state' => ['built_at' => $time($state->built_at), 'last_sync_at' => $time($state->last_sync_at), 'last_reconcile_at' => $time($state->last_reconcile_at),
                'last_reconcile' => json_decode((string) ($state->last_reconcile ?? 'null'), true), 'last_error_at' => $time($state->last_error_at), 'last_error' => $state->last_error],
            'runs' => DB::table('search_index_runs')->orderByDesc('id')->limit(5)->get()->map(fn (object $r): array => [
                'id' => (string) $r->id, 'status' => $r->status, 'target' => $r->target, 'expected' => (int) $r->expected, 'indexed' => (int) $r->indexed, 'failed' => (int) $r->failed,
                'errors' => array_slice(json_decode((string) $r->errors, true) ?: [], 0, 5), 'message' => $r->message, 'started_at' => $time($r->started_at), 'finished_at' => $time($r->finished_at),
            ])->all(),
        ]);
    }

    public function repair(Request $request, SearchRecovery $recovery): RedirectResponse
    {
        Gate::authorize('search.manage');
        $result = $recovery->repair((string) $request->user()->id);

        return back()->with('status', __($result['complete'] ? 'search.repair_complete' : ($result['busy'] ? 'search.repair_busy' : 'search.repair_progress')));
    }

    public function settings(Request $request, SearchSettings $settings): RedirectResponse
    {
        Gate::authorize('search.manage');
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $applied = $settings->save((string) $request->user()->id, (int) $data['version'], $request->only(['settings', 'clear']));

        return back()->with('status', __($applied ? 'search.saved' : 'search.saved_not_applied'));
    }

    public function rebuild(Request $request, SearchIndex $index): RedirectResponse
    {
        Gate::authorize('search.manage');
        $index->startRebuild((string) $request->user()->id);

        return back()->with('status', __('search.rebuild_started'));
    }

    public function cancel(Request $request, SearchIndex $index, string $run): RedirectResponse
    {
        Gate::authorize('search.manage');
        $index->cancelRebuild((int) $run);

        return back()->with('status', __('search.rebuild_cancelled'));
    }

    public function reconcile(): RedirectResponse
    {
        Gate::authorize('search.manage');
        ReconcileSearchIndex::dispatch();

        return back()->with('status', __('search.reconcile_queued'));
    }

    public function sync(): RedirectResponse
    {
        Gate::authorize('search.manage');
        ProcessSearchOutbox::dispatch();

        return back()->with('status', __('search.sync_queued'));
    }

    public function retry(): RedirectResponse
    {
        Gate::authorize('search.manage');
        $count = DB::table('search_outbox')->where(fn ($q) => $q->whereNotNull('failed_at')->orWhereNotNull('last_error'))->update(['failed_at' => null, 'attempts' => 0, 'available_at' => now()]);
        ProcessSearchOutbox::dispatch();

        return back()->with('status', __('search.retry_queued', ['count' => $count]));
    }
}
