<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class AiController
{
    public function logs(Request $request): Response
    {
        Gate::authorize('ai.logs.view');
        $request->validate(['page' => ['nullable', 'integer', 'between:1,1000000']]);
        $logs = DB::table('ai_calls as l')->leftJoin('users as u', 'u.id', '=', 'l.user_id')->select('l.*', 'u.name as user_name')->orderByDesc('l.id')->paginate(50)->withQueryString();
        $logs->setCollection($logs->getCollection()->map(fn ($row): array => ['id' => (string) $row->id, 'feature' => $row->feature, 'user' => $row->user_name, 'object_type' => $row->object_type, 'object_id' => $row->object_id === null ? null : (string) $row->object_id, 'model' => $row->model, 'status' => $row->status, 'input_tokens' => $row->input_tokens, 'output_tokens' => $row->output_tokens, 'http_status' => $row->http_status, 'error_code' => $row->error_code, 'duration_ms' => $row->duration_ms, 'created_at' => now()->parse($row->created_at)->utc()->toIso8601String()]));

        return Inertia::render('Admin/Settings/AiLog', [...$this->shell($request), 'logs' => $logs->toArray()]);
    }

    /** @return array<string, mixed> */
    private function shell(Request $request): array
    {
        return ['navigation' => app(AdminNavigation::class)->forUser($request->user()), 'breadcrumbs' => [['label' => __('navigation.dashboard'), 'href' => '/admin'], ['label' => __('navigation.logs'), 'href' => null], ['label' => __('ai.log'), 'href' => null]]];
    }
}
