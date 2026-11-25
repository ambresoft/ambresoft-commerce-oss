<?php

namespace App\Payment\Infrastructure;

use App\Media\Infrastructure\ImageAttachment;
use App\Order\Infrastructure\OrderMoney;
use App\Order\Infrastructure\OrderQueries;
use App\Payment\Application\Command\PaymentAmount;
use App\Payment\Application\Contract\RedirectPaymentMethod;
use App\Payment\Domain\Service\PaymentRules;
use App\Pricing\Domain\Repository\CurrencyRepository;
use App\Shared\Domain\Service\Decimal;
use App\Shared\Infrastructure\Modules\AddonCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Read models for the Payment methods screens and the payment section of the order page. */
final class PaymentQueries
{
    public function __construct(private PaymentSettings $settings, private OrderMoney $money, private PaymentLinks $links) {}

    /**
     * Registered methods of core and enabled add-ons, in customer-facing order.
     *
     * @return list<array<string, mixed>>
     */
    public function methods(): array
    {
        $this->settings->ensure();
        $locale = app()->getLocale();
        $methods = [];
        foreach (DB::table('payment_methods')->orderBy('position')->orderBy('id')->get() as $row) {
            $method = $this->settings->method($row);
            if ($method === null || ! $this->settings->ownerEnabled($row)) {
                continue;
            }
            $issues = $this->settings->issues($method, $row);
            $methods[] = [
                'code' => $row->code, 'version' => (int) $row->lock_version, 'name' => $this->settings->name((string) $row->id, $locale),
                'description' => (string) DB::table('payment_method_translations as t')->join('locales as l', 'l.id', '=', 't.locale_id')->where('t.method_id', $row->id)->where('l.code', $locale)->value('t.description'),
                'provider' => $method->provider(), 'owner' => $row->owner, 'owner_title' => $this->ownerTitle($row->owner),
                'kind' => $method instanceof RedirectPaymentMethod ? 'redirect' : 'offline', 'active' => (bool) $row->is_active,
                'issues' => $issues, 'available' => $row->is_active && $issues === [],
                'image_url' => $row->image_media_id === null ? null : '/admin/payment-methods/'.$row->code.'/image?v='.$row->image_media_id,
            ];
        }

        return $methods;
    }

    /** @return array<string, mixed>|null */
    public function editor(string $code): ?array
    {
        $this->settings->ensure();
        $row = $this->settings->row($code);
        $method = $row === null ? null : $this->settings->method($row);
        if ($method === null || ! $this->settings->ownerEnabled($row)) {
            return null;
        }
        $values = $this->settings->values($method, $row);
        $translations = DB::table('payment_method_translations as t')->join('locales as l', 'l.id', '=', 't.locale_id')->where('t.method_id', $row->id)->get(['l.code', 't.name', 't.description'])
            ->mapWithKeys(fn (object $t): array => [$t->code => ['name' => $t->name, 'description' => $t->description]]);
        $fields = array_map(fn ($field): array => [
            'key' => $field->key, 'type' => $field->type, 'label' => __($field->label), 'hint' => $field->hint === '' ? '' : __($field->hint), 'required' => $field->required,
            'options' => array_map(fn (string $value, string $label): array => ['value' => $value, 'label' => __($label)], array_keys($field->options), $field->options),
            'max_length' => $field->maxLength,
            // Secrets are write-only: the browser only learns whether one is stored.
            'value' => $field->type === 'secret' ? '' : $values[$field->key], 'set' => $field->type === 'secret' && $values[$field->key] !== '',
        ], $method->settings());

        return [
            'code' => $row->code, 'version' => (int) $row->lock_version, 'provider' => $method->provider(), 'owner' => $row->owner, 'owner_title' => $this->ownerTitle($row->owner),
            'kind' => $method instanceof RedirectPaymentMethod ? 'redirect' : 'offline', 'active' => (bool) $row->is_active,
            'paid_status_id' => $row->paid_status_id === null ? '' : (string) $row->paid_status_id, 'translations' => (object) $translations->all(),
            'fields' => $fields, 'issues' => $this->settings->issues($method, $row),
            'verified_at' => $row->verified_at === null ? null : CarbonImmutable::parse($row->verified_at)->toIso8601String(),
            'name' => $this->settings->name((string) $row->id, app()->getLocale()),
            'image' => ImageAttachment::view($row->image_media_id, '/admin/payment-methods/'.$row->code.'/image'),
            'exclude_physical' => (bool) $row->exclude_physical, 'exclude_digital' => (bool) $row->exclude_digital,
            'fee' => $row->fee === null ? '' : Decimal::trim((string) $row->fee), 'max_subtotal' => $row->max_subtotal === null ? '' : Decimal::trim((string) $row->max_subtotal), 'fee_currency' => app(CurrencyRepository::class)->currentPrimary()->code,
            'excluded_category_ids' => app(PaymentMethodRestrictions::class)->excludedCategories((string) $row->id),
        ];
    }

    /**
     * The payment section of the order page.
     *
     * @return array<string, mixed>
     */
    public function forOrder(string $orderId, bool $canManage): array
    {
        $order = DB::table('orders')->where('id', $orderId)->first(['id', 'total', 'currency_code', 'currency_decimals', 'payment_method_id', 'payment_method_name']);
        $format = fn (string $value): string => $this->money->format($value, $order->currency_code, (int) $order->currency_decimals);
        $rows = DB::table('order_payments')->where('order_id', $orderId)->orderBy('id')->get();
        $payments = $rows->map(fn (object $p): array => ['state' => $p->state, 'amount' => Decimal::trim($p->amount)])->all();
        $outstanding = PaymentRules::outstanding($payments, $order->total);
        $method = $order->payment_method_id === null ? null : DB::table('payment_methods')->where('id', $order->payment_method_id)->first();
        $implementation = $method === null ? null : $this->settings->method($method);
        $online = $implementation instanceof RedirectPaymentMethod && $this->settings->ownerEnabled($method)
            && $this->settings->available($method, new PaymentAmount($outstanding, $order->currency_code, (int) $order->currency_decimals));
        $users = DB::table('users')->whereIn('id', $rows->pluck('recorded_by')->filter()->all())->pluck('name', 'id');
        $names = DB::table('payment_methods')->whereIn('id', $rows->pluck('payment_method_id')->filter()->unique()->all())->pluck('id')
            ->mapWithKeys(fn ($id): array => [(string) $id => $this->settings->name((string) $id, app()->getLocale())]);
        $pending = Decimal::compare($outstanding, '0') > 0;
        // Only the last group of a gift card's code appears on the order: the full code is a spending credential.
        $cards = DB::table('gift_cards')->whereIn('id', $rows->pluck('gift_card_id')->filter()->unique()->all())->pluck('number', 'id');

        return [
            'method' => $order->payment_method_id === null ? null : ['name' => $order->payment_method_name, 'kind' => $implementation instanceof RedirectPaymentMethod ? 'redirect' : 'offline', 'online' => $online],
            'status' => PaymentRules::orderStatus($payments, $order->total),
            'paid' => $format(PaymentRules::captured($payments)), 'outstanding' => $format($outstanding),
            'outstanding_raw' => Decimal::atLeastScale($outstanding, (int) $order->currency_decimals), 'decimals' => (int) $order->currency_decimals,
            'records' => $rows->map(fn (object $p): array => [
                'id' => (string) $p->id, 'kind' => $p->kind, 'state' => $p->state, 'amount' => $format($p->amount),
                'method' => $p->kind === 'gift_card' ? __('giftcards.card_ending', ['last' => substr((string) ($cards[$p->gift_card_id] ?? ''), -4)]) : ($p->payment_method_id === null ? null : ($names[(string) $p->payment_method_id] ?? null)),
                'provider_reference' => $p->provider_reference, 'capture_reference' => $p->capture_reference, 'detail' => $p->detail, 'note' => $p->note,
                'recorded_by' => $p->recorded_by === null ? null : ($users[$p->recorded_by] ?? null),
                'created_at' => CarbonImmutable::parse($p->created_at)->toIso8601String(), 'completed_at' => $p->completed_at === null ? null : CarbonImmutable::parse($p->completed_at)->toIso8601String(),
                'can_refresh' => $canManage && $p->kind === 'online' && $p->provider_reference !== null && in_array($p->state, ['created', 'processing', 'pending'], true),
            ])->all(),
            'link' => $canManage && $online && $pending ? $this->links->payUrl($orderId) : null,
            'can_record' => $canManage && $pending,
            'can_gift_card' => $canManage && $pending && Gate::allows('giftcards.view'),
        ];
    }

    /**
     * Active order statuses for the "status after payment" choice.
     *
     * @return list<array{id: string, name: string, color: string}>
     */
    public function statuses(): array
    {
        return array_values(array_map(fn (array $s): array => ['id' => $s['id'], 'name' => $s['name'], 'color' => $s['color']], array_filter(app(OrderQueries::class)->statuses(), fn (array $s): bool => $s['active'])));
    }

    private function ownerTitle(string $owner): ?string
    {
        return $owner === 'core' ? null : (app(AddonCatalog::class)->manifest($owner)->title ?? $owner);
    }
}
