<?php

namespace App\Customer\Infrastructure;

use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Order\Infrastructure\OrderQueries;
use App\Settings\Infrastructure\GeneralSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CallRequestQueries
{
    private function base(): Builder
    {
        return DB::table('call_requests as c')->leftJoin('users as u', 'u.id', '=', 'c.responsible_id')->leftJoin('orders as o', 'o.id', '=', 'c.order_id')
            ->select(['c.id', 'c.name', 'c.phone', 'c.convenient_time', 'c.status', 'c.responsible_id', 'c.order_id', 'c.order_number', 'c.lock_version', 'c.created_at', 'c.updated_at', 'u.name as responsible_name', 'u.is_active as responsible_active', 'o.status_id as order_status_id']);
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function listing(array $filters): array
    {
        $query = $this->base()->selectRaw("left(coalesce(c.notes, ''), 160) as notes_preview, length(coalesce(c.notes, '')) > 160 as notes_truncated")
            ->selectSub(DB::table('call_request_products')->whereColumn('call_request_id', 'c.id')->selectRaw('count(*)'), 'product_count');
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $digits = ContactNormalizer::phone($search);
            $query->where(function (Builder $q) use ($like, $digits, $search): void {
                $q->where('c.name', 'ilike', $like)->orWhere('c.phone', 'ilike', $like);
                if ($digits !== null) {
                    $q->orWhere('c.phone_digits', 'like', '%'.$digits.'%');
                }
                if (preg_match('/^[1-9][0-9]{0,17}$/D', $search) === 1) {
                    $q->orWhere('c.id', $search);
                }
            });
        }
        if (! empty($filters['status'])) {
            $query->where('c.status', $filters['status']);
        }
        if (($filters['responsible'] ?? '') === 'unassigned') {
            $query->whereNull('c.responsible_id');
        } elseif (! empty($filters['responsible'])) {
            $query->where('c.responsible_id', $filters['responsible']);
        }
        $timezone = app(GeneralSettings::class)->all()['timezone'];
        $from = $filters['created_from'] ?? null;
        $to = $filters['created_to'] ?? null;
        if ($from !== null && $to !== null && $from > $to) {
            throw ValidationException::withMessages(['created_to' => __('calls.date_invalid')]);
        }
        if ($from !== null) {
            $query->where('c.created_at', '>=', CarbonImmutable::parse($from, $timezone)->startOfDay()->utc());
        }
        if ($to !== null) {
            $query->where('c.created_at', '<=', CarbonImmutable::parse($to, $timezone)->endOfDay()->utc());
        }
        $sort = in_array($filters['sort'] ?? '', ['id', 'created_at', 'updated_at', 'status'], true) ? $filters['sort'] : 'created_at';
        $direction = ($filters['direction'] ?? '') === 'asc' ? 'asc' : 'desc';
        $page = $query->orderBy('c.'.$sort, $direction)->orderBy('c.id', $direction)->paginate((int) ($filters['per_page'] ?? 25))->withQueryString();
        $statuses = $this->orderStatuses();
        $rows = [];
        foreach ($page->items() as $row) {
            $rows[] = [...$this->projection($row, $statuses), 'notes_preview' => $row->notes_preview, 'notes_truncated' => (bool) $row->notes_truncated, 'product_count' => (int) $row->product_count];
        }
        $page->setCollection(collect($rows));

        return $page->toArray();
    }

    /** @return array<string, mixed>|null */
    public function editor(string $id): ?array
    {
        $row = $this->base()->addSelect('c.notes')->where('c.id', $id)->first();
        if ($row === null) {
            return null;
        }
        $products = DB::table('call_request_products as cp')->leftJoin('products as p', 'p.id', '=', 'cp.product_id')->where('cp.call_request_id', $id)->orderBy('cp.position')->get(['cp.product_id', 'cp.name', 'cp.sku', 'cp.quantity', 'p.deleted_at']);

        return [...$this->projection($row, $this->orderStatuses()), 'notes' => $row->notes ?? '', 'products' => $products->map(fn (object $p): array => ['id' => $p->product_id === null ? null : (string) $p->product_id, 'name' => $p->name, 'sku' => $p->sku, 'quantity' => (int) $p->quantity, 'available' => $p->product_id !== null && $p->deleted_at === null])->all()];
    }

    /** @return array<string, array<string, mixed>> */
    private function orderStatuses(): array
    {
        return collect(app(OrderQueries::class)->statuses())->keyBy('id')->all();
    }

    /** @param array<string, array<string, mixed>> $statuses
     * @return array<string, mixed>
     */
    private function projection(object $row, array $statuses): array
    {
        return ['id' => (string) $row->id, 'version' => (int) $row->lock_version, 'name' => $row->name ?? '', 'phone' => $row->phone, 'convenient_time' => $row->convenient_time ?? '', 'status' => $row->status,
            'responsible_id' => $row->responsible_id === null ? '' : (string) $row->responsible_id, 'responsible_name' => $row->responsible_name, 'responsible_active' => (bool) $row->responsible_active,
            'order_id' => $row->order_id === null ? null : (string) $row->order_id, 'order_number' => $row->order_number === null ? '' : (string) $row->order_number,
            'order_status' => $row->order_status_id === null ? null : ($statuses[(string) $row->order_status_id] ?? null), 'created_at' => CarbonImmutable::parse($row->created_at)->toIso8601String(), 'updated_at' => CarbonImmutable::parse($row->updated_at)->toIso8601String()];
    }

    /** @param list<string> $assigned
     * @return list<array{id: string, name: string, eligible: bool}>
     */
    public function assignees(array $assigned = []): array
    {
        $query = UserRecord::query()->where('is_active', true)->whereNotNull('email_verified_at');
        foreach (['admin.access', 'call-requests.view', 'call-requests.manage'] as $ability) {
            $query->whereExists(function (Builder $roles) use ($ability): void {
                $roles->selectRaw('1')->from('role_user')->join('roles', 'roles.id', '=', 'role_user.role_id')->whereColumn('role_user.user_id', 'users.id')
                    ->where(fn (Builder $q) => $q->where('roles.is_super_admin', true)->orWhereExists(function (Builder $grants) use ($ability): void {
                        $grants->selectRaw('1')->from('permission_role')->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')->whereColumn('permission_role.role_id', 'roles.id')->where('permissions.name', $ability);
                    }));
            });
        }
        $eligible = $query->orderBy('name')->orderBy('id')->limit(500)->get(['id', 'name'])->map(fn (UserRecord $u): array => ['id' => (string) $u->id, 'name' => $u->name, 'eligible' => true])->all();
        $missing = array_diff($assigned, array_column($eligible, 'id'));
        $legacy = UserRecord::query()->whereIn('id', $missing)->orderBy('id')->get(['id', 'name'])->map(fn (UserRecord $u): array => ['id' => (string) $u->id, 'name' => $u->name, 'eligible' => false])->all();

        return [...$eligible, ...$legacy];
    }

    private function productBase(): Builder
    {
        $catalog = app(LocaleCatalog::class);
        $default = $catalog->defaultCode();
        $current = app()->getLocale();

        return DB::table('products as p')->leftJoin('product_translations as c', fn ($q) => $q->on('c.product_id', '=', 'p.id')->where('c.locale_id', DB::table('locales')->where('code', $current)->value('id')))
            ->leftJoin('product_translations as d', fn ($q) => $q->on('d.product_id', '=', 'p.id')->where('d.locale_id', DB::table('locales')->where('code', $default)->value('id')))
            ->whereNull('p.deleted_at')->where('p.status', 'active')->select('p.id', 'p.sku')->selectRaw("coalesce(nullif(c.name, ''), nullif(d.name, ''), p.sku, '#' || p.id) as name");
    }

    /** @param list<string> $ids
     * @return array<string, array{id: string, name: string, sku: ?string}>
     */
    public function products(array $ids): array
    {
        return $this->productBase()->whereIn('p.id', $ids)->get()->mapWithKeys(fn (object $p): array => [(string) $p->id => ['id' => (string) $p->id, 'name' => $p->name, 'sku' => $p->sku]])->all();
    }

    /** @param list<string> $selected
     * @return list<array{id: string, name: string, sku: ?string}>
     */
    public function productOptions(string $search, array $selected): array
    {
        $like = '%'.addcslashes(trim($search), '%_\\').'%';
        $rows = $this->productBase()->where(fn (Builder $q) => $q->whereRaw("coalesce(c.name, d.name, '') ILIKE ?", [$like])->orWhere('p.sku', 'ilike', $like))->orderBy('p.id')->limit(50)->get()->map(fn (object $p): array => ['id' => (string) $p->id, 'name' => $p->name, 'sku' => $p->sku])->all();

        return array_values(array_replace(array_column($rows, null, 'id'), $this->products($selected)));
    }
}
