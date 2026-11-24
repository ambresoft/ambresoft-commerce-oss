<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Identity\Infrastructure\Security\AccountSecurity;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class AccountSecurityController
{
    public function show(Request $request, string $user, AdminNavigation $navigation, AccountSecurity $security): Response
    {
        Gate::authorize('account-security.view');
        $identity = UserRecord::findOrFail($user);
        $request->validate(['page' => ['nullable', 'integer', 'min:1', 'max:1000000'], 'ips_page' => ['nullable', 'integer', 'min:1', 'max:1000000'], 'devices_page' => ['nullable', 'integer', 'min:1', 'max:1000000']]);
        $events = DB::table('identity_security_events')->where('user_id', $user);
        $success = (clone $events)->whereIn('kind', ['registration', 'login']);

        return Inertia::render('Admin/Security/Show', [
            'navigation' => $navigation->forUser($request->user()),
            'breadcrumbs' => [['label' => __('account_security.title'), 'href' => null]],
            'identity' => ['id' => (string) $identity->id, 'name' => $identity->name, 'email' => $identity->email],
            'security' => $security->summary($user),
            'events' => (clone $events)->orderByDesc('id')->paginate(25)->withQueryString()->through(AccountSecurity::eventData(...)),
            'ips' => (clone $success)->whereNotNull('ip')->selectRaw('ip, MIN(occurred_at) AS first_seen, MAX(occurred_at) AS last_seen, COUNT(*) FILTER (WHERE kind = \'login\') AS count')->groupBy('ip')->orderByDesc('last_seen')->orderBy('ip')->paginate(15, ['*'], 'ips_page')->withQueryString()->through($this->seen(...)),
            'devices' => (clone $success)->selectRaw('device_key, browser, platform, MIN(occurred_at) AS first_seen, MAX(occurred_at) AS last_seen, COUNT(*) FILTER (WHERE kind = \'login\') AS count')->groupBy('device_key', 'browser', 'platform')->orderByDesc('last_seen')->orderBy('device_key')->paginate(15, ['*'], 'devices_page')->withQueryString()->through($this->seen(...)),
        ]);
    }

    /** @return array<string, mixed> */
    private function seen(object $row): array
    {
        return [...(array) $row, 'first_seen' => CarbonImmutable::parse($row->first_seen)->toIso8601String(), 'last_seen' => CarbonImmutable::parse($row->last_seen)->toIso8601String(), 'count' => (int) $row->count];
    }
}
