<?php

namespace App\Marketing\Infrastructure;

use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Marketing\Domain\Service\GiftCardRules;
use App\Order\Infrastructure\OrderMoney;
use App\Payment\Infrastructure\OrderPayments;
use App\Shared\Domain\Service\Decimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Staff (or the checkout, with the cart's card) pay part or all of an order with a gift card. The card is a payment, not a discount: prices and taxes of the
 * order do not change, a captured payment of kind "gift card" is recorded on it, and the card's balance goes down by the
 * same amount in the same transaction. The order is locked before the card everywhere, so two staff members using one
 * card at once cannot spend it twice, and the database refuses a negative balance outright.
 */
final class GiftCardRedemptions
{
    public function __construct(private OrderPayments $payments, private OrderMoney $money) {}

    /** @return string The amount taken from the card, formatted in the order's currency. */
    public function redeem(string $actor, string $orderId, string $code): string
    {
        $staff = UserRecord::findOrFail($actor);
        Gate::forUser($staff)->authorize('orders.payments');
        Gate::forUser($staff)->authorize('giftcards.view');
        $code = GiftCardRules::normalize($code);
        if (! GiftCardRules::isCode($code)) {
            throw ValidationException::withMessages(['code' => __('giftcards.not_found')]);
        }

        return app(AuditLogger::class)->operation('gift_card', $actor, fn (): string => $this->take($actor, $orderId, DB::table('gift_cards')->where('number', $code)));
    }

    /**
     * Checkout pays with the gift card left in the cart, for as much as its balance covers. A trusted code path: the
     * shopper never names the card here, the cart does. Runs inside the checkout's transaction, so a card that can no
     * longer pay rolls the new order back with the reason.
     */
    public function redeemAtCheckout(string $orderId, string $cardId): void
    {
        $this->take(null, $orderId, DB::table('gift_cards')->where('id', $cardId));
    }

    private function take(?string $actor, string $orderId, Builder $card): string
    {
        return DB::transaction(function () use ($actor, $orderId, $card): string {
            app(AuditContext::class)->actor($actor);
            $due = $this->payments->dueForGiftCard($orderId);
            $card = $card->whereNull('deleted_at')->lockForUpdate()->first()
                ?? throw ValidationException::withMessages(['code' => __('giftcards.not_found')]);
            if (($reason = GiftCardRules::refusal($card->status)) !== null) {
                throw ValidationException::withMessages(['code' => __('giftcards.'.$reason)]);
            }
            if ($card->currency_code !== $due['currency']) {
                throw ValidationException::withMessages(['code' => __('giftcards.currency_mismatch', ['card' => $card->currency_code, 'order' => $due['currency']])]);
            }
            $amount = GiftCardRules::applicable($card->balance, $due['outstanding']);
            if (Decimal::compare($amount, '0') <= 0) {
                throw ValidationException::withMessages(['code' => __('giftcards.nothing_due')]);
            }
            $balance = Decimal::subtract($card->balance, $amount);
            DB::table('gift_cards')->where('id', $card->id)->update([
                'balance' => $balance, 'status' => GiftCardRules::afterRedemption($card->status, $balance), 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now(),
            ]);
            $this->payments->recordGiftCard($actor, $orderId, (string) $card->id, $amount);
            DB::table('gift_card_transactions')->insert([
                'gift_card_id' => $card->id, 'type' => 'redeemed', 'amount' => $amount, 'balance_after' => $balance, 'order_id' => $orderId, 'actor_id' => $actor, 'created_at' => now(),
            ]);

            return $this->money->format($amount, $card->currency_code, (int) $card->currency_decimals);
        });
    }
}
