<?php

namespace App\Payment\Infrastructure;

use App\Order\Application\Contract\OrderPaymentMethods;
use App\Payment\Application\Command\PaymentAmount;
use Illuminate\Support\Facades\DB;

/** Answers the Order context: which methods an order may use, by stored method ID. */
final class PaymentMethodChoices implements OrderPaymentMethods
{
    public function __construct(private PaymentSettings $settings) {}

    public function choices(string $currency, int $decimals, string $locale): array
    {
        $this->settings->ensure();
        $amount = new PaymentAmount('0', $currency, $decimals);
        $choices = [];
        foreach (DB::table('payment_methods')->where('is_active', true)->orderBy('position')->orderBy('id')->get() as $row) {
            if ($this->settings->ownerEnabled($row) && $this->settings->available($row, $amount)) {
                $choices[] = ['id' => (string) $row->id, 'name' => $this->settings->name((string) $row->id, $locale)];
            }
        }

        return $choices;
    }

    public function assignable(string $methodId, string $total, string $currency, int $decimals, string $locale): ?string
    {
        $row = preg_match('/^[1-9]\d{0,18}$/D', $methodId) === 1 ? DB::table('payment_methods')->where('id', $methodId)->first() : null;
        if ($row === null || ! $this->settings->ownerEnabled($row) || ! $this->settings->available($row, new PaymentAmount($total, $currency, $decimals))) {
            return null;
        }

        return mb_substr($this->settings->name($methodId, $locale), 0, 100);
    }

    public function locked(string $orderId): bool
    {
        return DB::table('order_payments')->where('order_id', $orderId)->whereIn('state', ['created', 'processing', 'pending', 'captured'])->exists();
    }
}
