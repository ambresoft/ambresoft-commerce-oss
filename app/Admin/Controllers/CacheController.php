<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Audit\Domain\Model\AuditEvent;
use App\Audit\Infrastructure\AuditLogger;
use App\Shared\Infrastructure\Cache\StorefrontCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class CacheController
{
    public function show(Request $request, StorefrontCache $cache): Response
    {
        Gate::authorize('cache.manage');

        return Inertia::render('Admin/Settings/Cache', [
            'navigation' => app(AdminNavigation::class)->forUser($request->user()),
            'breadcrumbs' => [['label' => __('navigation.dashboard'), 'href' => '/admin'], ['label' => __('cache.title'), 'href' => null]],
            'settings' => ['enabled' => (bool) $cache->state()->enabled, 'version' => (int) $cache->state()->lock_version],
            'reachable' => $cache->reachable(),
            'groups' => StorefrontCache::GROUPS,
        ]);
    }

    public function update(Request $request, StorefrontCache $cache): RedirectResponse
    {
        Gate::authorize('cache.manage');
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'version' => ['required', 'integer', 'min:1']]);
        if ($data['enabled'] && ! $cache->reachable()) {
            throw ValidationException::withMessages(['enabled' => __('cache.unavailable')]);
        }
        app(AuditLogger::class)->operation('storefront_cache', (string) $request->user()->id, fn () => DB::transaction(function () use ($data): void {
            $row = DB::table('storefront_cache_state')->where('id', 1)->lockForUpdate()->firstOrFail();
            if ((int) $row->lock_version !== (int) $data['version']) {
                throw ValidationException::withMessages(['version' => __('cache.stale')]);
            }
            $values = ['enabled' => $data['enabled'], 'lock_version' => $row->lock_version + 1];
            foreach (StorefrontCache::GROUPS as $group) {
                $values[$group] = DB::raw('gen_random_uuid()');
            }
            DB::table('storefront_cache_state')->where('id', 1)->update($values);
            app(AuditLogger::class)->record(new AuditEvent('cache.settings_changed', 'storefront_cache', entityId: $data['enabled'] ? 'enabled' : 'disabled'));
        }, 3));
        $cache->reset();

        return redirect()->route('admin.cache.index')->with('status', __('cache.saved'));
    }

    public function clear(Request $request, StorefrontCache $cache): RedirectResponse
    {
        Gate::authorize('cache.manage');
        $data = $request->validate(['group' => ['required', Rule::in(['all', ...StorefrontCache::GROUPS])]]);
        app(AuditLogger::class)->operation('storefront_cache', (string) $request->user()->id, fn () => DB::transaction(function () use ($data): void {
            $groups = $data['group'] === 'all' ? StorefrontCache::GROUPS : [$data['group']];
            DB::table('storefront_cache_state')->where('id', 1)->update(array_fill_keys($groups, DB::raw('gen_random_uuid()')));
            app(AuditLogger::class)->record(new AuditEvent('cache.cleared', 'storefront_cache', entityId: $data['group']));
        }, 3));
        $cache->reset();

        return redirect()->route('admin.cache.index')->with('status', __($data['group'] === 'all' ? 'cache.cleared' : 'cache.cleared_'.$data['group']));
    }
}
