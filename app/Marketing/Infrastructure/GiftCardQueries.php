<?php

namespace App\Marketing\Infrastructure;

use App\Localization\Infrastructure\LocaleCatalog;
use App\Order\Infrastructure\OrderMoney;
use App\Shared\Domain\Service\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Read models of the gift card screens. Amounts are shown in the card's own currency, never converted. */
final class GiftCardQueries
{
    public const SORTS = ['code', 'created_at', 'balance', 'status'];

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function listing(array $filters): array
    {
        $q = DB::table('gift_cards')->whereNull('deleted_at');
        if (($filters['search'] ?? '') !== '') {
            $like = '%'.addcslashes($filters['search'], '%_\\').'%';
            $q->where(fn ($q) => $q->where('number', 'ilike', $like)->orWhere('recipient_name', 'ilike', $like)->orWhere('sender_name', 'ilike', $like)->orWhere('recipient_email', 'ilike', $like));
        }
        if (($filters['status'] ?? '') !== '') {
            $q->where('status', $filters['status']);
        }
        $sort = in_array($filters['sort'] ?? '', self::SORTS, true) ? $filters['sort'] : 'created_at';
        $page = $q->orderBy($sort, ($filters['direction'] ?? '') === 'asc' ? 'asc' : 'desc')->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 25))->withQueryString();
        $money = app(OrderMoney::class);
        $rows = [];
        foreach ($page->items() as $row) {
            $rows[] = [
                'id' => (string) $row->id, 'version' => (int) $row->lock_version, 'code' => $row->number, 'sender' => $row->sender_name, 'recipient' => $row->recipient_name, 'email' => $row->recipient_email,
                'created_at' => CarbonImmutable::parse($row->created_at)->toIso8601String(), 'status' => $row->status,
                'balance' => $money->format($row->balance, $row->currency_code, (int) $row->currency_decimals),
                'amount' => $money->format($row->amount, $row->currency_code, (int) $row->currency_decimals),
                'partly_used' => Decimal::compare($row->balance, $row->amount) !== 0, 'notify' => (bool) $row->notify,
            ];
        }
        $page->setCollection(collect($rows));

        return $page->toArray();
    }

    /** @return array<string, mixed>|null */
    public function editor(string $id): ?array
    {
        $row = DB::table('gift_cards as g')->join('locales as l', 'l.id', '=', 'g.locale_id')->where('g.id', $id)->first(['g.*', 'l.code as locale_code']);
        if ($row === null) {
            return null;
        }
        $money = app(OrderMoney::class);

        return [
            'id' => (string) $row->id, 'version' => (int) $row->lock_version, 'code' => $row->number, 'recipient_name' => $row->recipient_name, 'sender_name' => $row->sender_name,
            'recipient_email' => $row->recipient_email, 'message' => (string) $row->message, 'locale' => $row->locale_code, 'status' => $row->status, 'notify' => (bool) $row->notify,
            'amount' => Decimal::atLeastScale(Decimal::trim($row->amount), (int) $row->currency_decimals), 'currency_code' => $row->currency_code,
            'balance' => $money->format($row->balance, $row->currency_code, (int) $row->currency_decimals),
            'amount_locked' => Decimal::compare($row->amount, $row->balance) !== 0, 'deleted_at' => $row->deleted_at,
            'created_at' => CarbonImmutable::parse($row->created_at)->toIso8601String(),
        ];
    }

    /** Languages an email can be written in.
     * @return list<array{code: string, name: string}>
     */
    public function locales(): array
    {
        return app(LocaleCatalog::class)->active()->map(fn ($l): array => ['code' => $l->code, 'name' => (string) config('languages.pool.'.$l->code.'.1', $l->code)])->values()->all();
    }

    /** @return array<string, mixed> Uses of the card, newest first. */
    public function history(string $id): array
    {
        $money = app(OrderMoney::class);
        $page = DB::table('gift_card_transactions as t')->join('gift_cards as g', 'g.id', '=', 't.gift_card_id')->leftJoin('orders as o', 'o.id', '=', 't.order_id')->leftJoin('users as u', 'u.id', '=', 't.actor_id')
            ->where('t.gift_card_id', $id)->orderByDesc('t.id')
            ->paginate(25, ['t.*', 'g.currency_code', 'g.currency_decimals', 'o.order_number', 'u.name as actor'])->withQueryString()->appends(['tab' => 'history']);
        $rows = [];
        foreach ($page->items() as $r) {
            $rows[] = [
                'id' => (string) $r->id, 'type' => $r->type, 'order_id' => $r->order_id === null ? null : (string) $r->order_id, 'order_number' => $r->order_number, 'actor' => $r->actor,
                'amount' => $money->format($r->amount, $r->currency_code, (int) $r->currency_decimals), 'balance_after' => $money->format($r->balance_after, $r->currency_code, (int) $r->currency_decimals),
                'created_at' => CarbonImmutable::parse($r->created_at)->toIso8601String(),
            ];
        }
        $page->setCollection(collect($rows));

        return $page->toArray();
    }

    /** @return list<array<string, mixed>> The latest emails about the card. */
    public function emails(string $id): array
    {
        return DB::table('gift_card_emails as e')->leftJoin('users as u', 'u.id', '=', 'e.requested_by')->where('e.gift_card_id', $id)->orderByDesc('e.id')->limit(20)
            ->get(['e.*', 'u.name as actor'])->map(fn (object $e): array => [
                'id' => (string) $e->id, 'kind' => $e->kind, 'recipient' => $e->recipient, 'state' => $e->state, 'attempts' => (int) $e->attempts, 'error' => $e->last_error, 'actor' => $e->actor,
                'at' => CarbonImmutable::parse($e->sent_at ?? $e->created_at)->toIso8601String(),
            ])->all();
    }
}
