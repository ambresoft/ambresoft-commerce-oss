<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class AuditLogController
{
    public function index(Request $request, AdminNavigation $navigation): Response
    {
        Gate::authorize('audit.view');
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:200'], 'actor' => ['nullable', 'string', 'max:40'],
            'request_id' => ['nullable', 'uuid'],
            'source' => ['nullable', Rule::in(['admin_ui', 'web', 'background_job', 'system'])],
            'action' => ['nullable', 'string', 'max:100'], 'entity' => ['nullable', 'string', 'max:60'],
            'status' => ['nullable', Rule::in(['success', 'failure', 'denied'])],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
            'sort' => ['nullable', Rule::in(['asc', 'desc'])], 'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);
        $query = DB::table('audit_logs');
        foreach (['actor' => 'actor_id', 'action' => 'action', 'entity' => 'entity_type', 'status' => 'status', 'request_id' => 'request_id', 'source' => 'source'] as $filter => $column) {
            if (! empty($filters[$filter])) {
                $query->where($column, $filters[$filter]);
            }
        }
        if (! empty($filters['search'])) {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['search']).'%';
            $query->where(fn ($q) => $q->where('actor_email', 'ilike', $term)->orWhere('actor_id', 'ilike', $term)->orWhere('entity_id', 'ilike', $term)->orWhere('entity_label', 'ilike', $term)->orWhere('description', 'ilike', $term));
        }
        if (! empty($filters['from'])) {
            $query->where('occurred_at', '>=', $filters['from'].'T00:00:00Z');
        }
        if (! empty($filters['to'])) {
            $query->where('occurred_at', '<', CarbonImmutable::parse($filters['to'], 'UTC')->addDay());
        }
        $query->orderBy('occurred_at', $filters['sort'] ?? 'desc')->orderBy('id', $filters['sort'] ?? 'desc');
        $logs = $query->select(['id', 'occurred_at', 'actor_id', 'actor_email', 'action', 'entity_type', 'entity_id', 'entity_label', 'description', 'status', 'request_id', 'source', 'ip_address'])->selectRaw("metadata->>'command' AS operation")->paginate(30)->withQueryString();
        $logs->through(fn ($row) => [...(array) $row, 'id' => (string) $row->id, 'occurred_at' => CarbonImmutable::parse($row->occurred_at)->utc()->toIso8601String()]);

        return Inertia::render('Admin/Audit/Index', [...$this->shell($request, $navigation), 'logs' => $logs, 'filters' => (object) $filters, 'actions' => DB::table('audit_logs')->distinct()->orderBy('action')->limit(100)->pluck('action')->all(), 'entities' => DB::table('audit_logs')->distinct()->orderBy('entity_type')->limit(60)->pluck('entity_type')->all()]);
    }

    public function show(Request $request, AdminNavigation $navigation, string $log): Response
    {
        Gate::authorize('audit.view');
        $row = DB::table('audit_logs')->where('id', $log)->first();
        abort_if($row === null, 404);
        $data = (array) $row;
        $data['id'] = (string) $row->id;
        $data['transaction_id'] = isset($row->transaction_id) ? (string) $row->transaction_id : null;
        $data['occurred_at'] = CarbonImmutable::parse($row->occurred_at)->utc()->toIso8601String();
        foreach (['changes', 'metadata', 'actor_roles'] as $key) {
            $data[$key] = json_decode($data[$key], true, 512, JSON_THROW_ON_ERROR);
        }

        return Inertia::render('Admin/Audit/Show', [...$this->shell($request, $navigation), 'log' => $data]);
    }

    /** @return array<string,mixed> */
    private function shell(Request $request, AdminNavigation $navigation): array
    {
        return ['navigation' => $navigation->forUser($request->user()), 'breadcrumbs' => [['label' => __('audit.title'), 'href' => route('admin.audit.index', [], false)]]];
    }
}
