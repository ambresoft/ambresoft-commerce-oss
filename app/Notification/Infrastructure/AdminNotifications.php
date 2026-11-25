<?php

namespace App\Notification\Infrastructure;

use App\Identity\Infrastructure\Persistence\UserRecord;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class AdminNotifications
{
    /** Each notice type with the capabilities its recipients (and later readers) must hold. */
    public const TYPES = [
        'order.placed' => ['orders.view', 'orders.update'],
        'security.login_failed' => ['account-security.view'],
        'review.pending' => ['reviews.moderate'],
        'call.requested' => ['call-requests.view'],
    ];

    /** @return list<string> The notice types the user may receive and read. */
    public function types(UserRecord $user): array
    {
        $gate = Gate::forUser($user);
        if (! $gate->allows('access-admin')) {
            return [];
        }

        return array_keys(array_filter(self::TYPES, fn (array $abilities): bool => collect($abilities)->every(fn (string $ability): bool => $gate->allows($ability))));
    }

    public function eligible(UserRecord $user): bool
    {
        return $this->types($user) !== [];
    }

    /** Persist inside the order transaction. Only committed rows can be delivered by SSE. */
    public function orderPlaced(string $id, string $number): void
    {
        $this->notify('order.placed', $id, $number);
    }

    /** A staff account crossed the failed admin sign-in threshold; one notice per burst (keyed by the crossing event). */
    public function loginFailed(string $user, string $event, int $count, ?string $ip): void
    {
        $this->notify('security.login_failed', $user.':'.$event, $count.'|'.($ip ?? ''));
    }

    public function reviewSubmitted(string $id, int $rating): void
    {
        $this->notify('review.pending', $id, (string) $rating);
    }

    public function callRequested(string $id, string $name): void
    {
        $this->notify('call.requested', $id, mb_substr($name, 0, 80));
    }

    private function notify(string $type, string $resource, string $label): void
    {
        DB::transaction(function () use ($type, $resource, $label): void {
            foreach (UserRecord::query()->whereHas('roles')->where('is_active', true)->whereNotNull('email_verified_at')->orderBy('id')->lazyById(100) as $user) {
                if (! in_array($type, $this->types($user), true)) {
                    continue;
                }
                $uid = (string) $user->getKey();
                $this->lock($uid);
                $inserted = DB::table('admin_notifications')->insertOrIgnore([
                    'user_id' => $uid, 'type' => $type, 'resource_id' => $resource,
                    'resource_number' => $label, 'created_at' => now(),
                ]);
                if ($inserted) {
                    DB::table('admin_notification_inboxes')->where('user_id', $uid)->update([
                        'revision' => DB::raw('revision + 1'), 'created_revision' => DB::raw('revision + 1'),
                    ]);
                }
            }
        });
    }

    private function lock(string $user): void
    {
        DB::table('admin_notification_inboxes')->insertOrIgnore(['user_id' => $user]);
        DB::table('admin_notification_inboxes')->where('user_id', $user)->lockForUpdate()->first();
    }

    /** @param list<string> $types */
    private function visible(string $user, array $types): Builder
    {
        return DB::table('admin_notifications')->where('user_id', $user)->whereIn('type', $types)->whereNull('deleted_at');
    }

    /** @return list<string> */
    private function typesOf(string $user): array
    {
        $record = UserRecord::find($user);

        return $record === null ? [] : $this->types($record);
    }

    /** @return array<string,mixed> */
    public function listing(string $user, ?string $before = null): array
    {
        $types = $this->typesOf($user);

        return DB::transaction(function () use ($user, $before, $types): array {
            $this->lock($user);
            $rows = $this->visible($user, $types)->when($before !== null, fn (Builder $q) => $q->where('id', '<', $before))->orderByDesc('id')->limit(21)->get();
            $more = $rows->count() > 20;
            $rows = $rows->take(20);
            $resources = fn (string $type, string $table) => DB::table($table)->whereIn('id', $rows->where('type', $type)->pluck('resource_id')->map(fn (string $id): string => explode(':', $id)[0])->filter(fn (string $id): bool => ctype_digit($id))->values())->pluck('id')->map(strval(...))->all();
            $existing = [
                'order.placed' => $resources('order.placed', 'orders'),
                'security.login_failed' => $resources('security.login_failed', 'users'),
                'review.pending' => $resources('review.pending', 'product_reviews'),
                'call.requested' => $resources('call.requested', 'call_requests'),
            ];
            $names = DB::table('users')->whereIn('id', $existing['security.login_failed'])->pluck('name', 'id');

            return [
                'revision' => (string) $this->state($user)->revision,
                'unread' => $this->visible($user, $types)->whereNull('read_at')->count(),
                'next' => $more ? (string) $rows->last()->id : null,
                'items' => $rows->map(fn (object $row): array => [
                    'id' => (string) $row->id, 'type' => $row->type, ...$this->present($row, $existing, $names->all()),
                    'created_at' => CarbonImmutable::parse($row->created_at)->toIso8601String(), 'read_at' => $row->read_at === null ? null : CarbonImmutable::parse($row->read_at)->toIso8601String(),
                ])->values()->all(),
            ];
        });
    }

    /**
     * @param  array<string,list<string>>  $existing
     * @param  array<int|string,string>  $names
     * @return array{title:string,message:string,url:?string}
     */
    private function present(object $row, array $existing, array $names): array
    {
        $id = explode(':', (string) $row->resource_id)[0];
        $exists = in_array($id, $existing[$row->type] ?? [], true);
        [$count, $ip] = array_pad(explode('|', (string) $row->resource_number, 2), 2, '');

        return match ($row->type) {
            'security.login_failed' => ['title' => __('notifications.login_failed_title'),
                'message' => __($ip === '' ? 'notifications.login_failed_message' : 'notifications.login_failed_message_ip', ['name' => $names[$id] ?? '—', 'count' => (int) $count, 'ip' => $ip]),
                'url' => $exists ? route('admin.account-security.show', ['user' => $id], false) : null],
            'review.pending' => ['title' => __('notifications.review_title'), 'message' => __('notifications.review_message', ['rating' => $row->resource_number]),
                'url' => $exists ? route('admin.reviews.edit', ['review' => $id], false) : null],
            'call.requested' => ['title' => __('notifications.call_title'), 'message' => __('notifications.call_message', ['name' => $row->resource_number]),
                'url' => $exists ? route('admin.call-requests.edit', ['callRequest' => $id], false) : null],
            default => ['title' => __('notifications.order_title'), 'message' => __('notifications.order_message', ['number' => $row->resource_number]),
                'url' => $exists ? route('admin.orders.show', ['order' => $id], false) : null],
        };
    }

    public function change(string $user, string $action, ?string $id = null): void
    {
        $types = $this->typesOf($user);
        DB::transaction(function () use ($user, $action, $id, $types): void {
            $this->lock($user);
            $query = $this->visible($user, $types);
            if ($id !== null) {
                $query->where('id', $id);
                abort_unless($query->exists(), 404);
            }
            $changed = $action === 'delete'
                ? $query->update(['deleted_at' => now()])
                : $query->whereNull('read_at')->update(['read_at' => now()]);
            if ($changed) {
                DB::table('admin_notification_inboxes')->where('user_id', $user)->increment('revision');
            }
        });
    }

    public function state(string $user): object
    {
        return DB::table('admin_notification_inboxes')->where('user_id', $user)->first() ?? (object) ['revision' => 0, 'created_revision' => 0];
    }
}
