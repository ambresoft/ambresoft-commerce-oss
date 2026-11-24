<?php

namespace App\Admin\Controllers;

use App\Admin\Dashboard\DashboardOverview;
use App\Admin\Navigation\AdminNavigation;
use App\Identity\Infrastructure\Persistence\UserRecord as User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController
{
    public function __invoke(Request $request, AdminNavigation $navigation, DashboardOverview $overview): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Admin/Dashboard', [
            'navigation' => $navigation->forUser($user),
            'securityEvents' => Gate::allows('account-security.view') ? DB::table('identity_security_events as e')->join('users as u', 'u.id', '=', 'e.user_id')->orderByDesc('e.id')->limit(10)->get(['e.id', 'e.user_id', 'e.kind', 'e.ip', 'e.timezone', 'e.new_device', 'e.new_ip', 'e.occurred_at', 'u.name'])->map(fn ($row): array => [...(array) $row, 'id' => (string) $row->id, 'user_id' => (string) $row->user_id, 'occurred_at' => CarbonImmutable::parse($row->occurred_at)->toIso8601String()])->all() : null,
            'overview' => $overview->for($user),
            'breadcrumbs' => [['label' => __('navigation.dashboard'), 'href' => null]],
        ]);
    }
}
