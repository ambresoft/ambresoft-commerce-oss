<?php

namespace App\Payment\Infrastructure;

use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Order\Infrastructure\OrderAdministration;
use App\Payment\Application\Command\PaymentAmount;
use App\Payment\Application\Command\PaymentCheck;
use App\Payment\Application\Command\PaymentResult;
use App\Payment\Application\Command\PaymentStart;
use App\Payment\Application\Contract\ExpiresProviderPayments;
use App\Payment\Application\Contract\PaymentProviderUnavailable;
use App\Payment\Application\Contract\RedirectPaymentMethod;
use App\Payment\Domain\Service\PaymentRules;
use App\Settings\Infrastructure\CompanySettings;
use App\Shared\Domain\Service\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Money recorded against orders. Staff record offline payments (cash collected on delivery); online attempts open a
 * provider payment for the outstanding server-calculated amount and are confirmed server-side when the customer
 * returns. Safeguards against duplicates: one open attempt per order (unique index), an atomic claim before every
 * capture, provider idempotency keys derived from the attempt reference, unique provider and capture references, and
 * settlement only from a non-final state under row locks. The optional "status after payment" runs once, when the order
 * first becomes fully paid, through OrderAdministration (stock and emails behave exactly as for staff changes).
 */
final class OrderPayments
{
    private const CLAIM_SECONDS = 120;

    private const ATTEMPT_MINUTES = 60;

    public function __construct(private PaymentSettings $settings, private PaymentLinks $links) {}

    /** Staff record money received outside an online provider, e.g. cash collected on delivery. */
    public function record(string $actor, string $orderId, string $amount, ?string $note): void
    {
        app(AuditLogger::class)->operation('order_payment', $actor, fn () => DB::transaction(function () use ($actor, $orderId, $amount, $note): void {
            app(AuditContext::class)->actor($actor);
            Gate::forUser(UserRecord::findOrFail($actor))->authorize('orders.payments');
            $order = $this->order($orderId);
            $payments = $this->payments($orderId);
            $before = PaymentRules::orderStatus($payments, $order->total);
            $fraction = (int) $order->currency_decimals === 0 ? '' : '(?:\.\d{1,'.(int) $order->currency_decimals.'})?';
            if (preg_match('/^\d{1,14}'.$fraction.'$/D', $amount) !== 1 || Decimal::compare($amount, '0') <= 0) {
                throw ValidationException::withMessages(['amount' => __('payments.amount_invalid')]);
            }
            if (Decimal::compare($amount, PaymentRules::outstanding($payments, $order->total)) > 0) {
                throw ValidationException::withMessages(['amount' => __('payments.amount_exceeds')]);
            }
            $note = $note === null ? null : (trim($note) === '' ? null : mb_substr(trim($note), 0, 1000));
            DB::table('order_payments')->insert([
                'reference' => (string) Str::uuid(), 'order_id' => $orderId, 'payment_method_id' => $order->payment_method_id, 'kind' => 'manual', 'state' => 'captured',
                'amount' => $amount, 'currency_code' => $order->currency_code, 'note' => $note, 'recorded_by' => $actor, 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->afterChange($order, $before, $order->payment_method_id === null ? null : (string) $order->payment_method_id);
        }));
    }

    /**
     * Locks the order and says what is still due on it, for a gift card about to pay part of it. Must run inside the
     * caller's transaction. An order that was cancelled or voided cannot receive money.
     *
     * @return array{currency: string, outstanding: string}
     */
    public function dueForGiftCard(string $orderId): array
    {
        $order = $this->order($orderId);
        $void = DB::table('order_statuses')->where('id', $order->status_id)->where('is_final', true)->where('stock_action', 'restore')->exists();
        if ($void) {
            throw ValidationException::withMessages(['code' => __('giftcards.order_void')]);
        }

        return ['currency' => $order->currency_code, 'outstanding' => PaymentRules::outstanding($this->payments($orderId), $order->total)];
    }

    /**
     * Records money received from a gift card as a captured payment of its own kind. The card's balance is reduced by
     * the same transaction (see GiftCardRedemptions); this only writes the order's side and then lets the method's
     * "status after payment" run if the order just became fully paid.
     */
    public function recordGiftCard(?string $actor, string $orderId, string $giftCardId, string $amount): string
    {
        app(AuditContext::class)->actor($actor);
        $order = $this->order($orderId);
        $before = PaymentRules::orderStatus($this->payments($orderId), $order->total);
        $id = (string) DB::table('order_payments')->insertGetId([
            'reference' => (string) Str::uuid(), 'order_id' => $orderId, 'payment_method_id' => null, 'kind' => 'gift_card', 'state' => 'captured', 'gift_card_id' => $giftCardId,
            'amount' => $amount, 'currency_code' => $order->currency_code, 'recorded_by' => $actor, 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->afterChange($order, $before, $order->payment_method_id === null ? null : (string) $order->payment_method_id);

        return $id;
    }

    /**
     * Opens (or reuses) an online payment for the outstanding amount and returns where to send the customer.
     *
     * @return array{outcome: string, url?: string}
     */
    public function start(string $orderId): array
    {
        $decision = DB::transaction(function () use ($orderId): array {
            $order = $this->order($orderId);
            $payments = $this->payments($orderId);
            if (in_array('pending', array_column($payments, 'state'), true)) {
                return ['outcome' => 'pending'];
            }
            $outstanding = PaymentRules::outstanding($payments, $order->total);
            if (Decimal::compare($outstanding, '0') <= 0) {
                return ['outcome' => 'paid'];
            }
            $row = $order->payment_method_id === null ? null : DB::table('payment_methods')->where('id', $order->payment_method_id)->first();
            $amount = new PaymentAmount($outstanding, $order->currency_code, (int) $order->currency_decimals);
            if ($row === null || ! $this->settings->method($row) instanceof RedirectPaymentMethod || ! $this->settings->ownerEnabled($row) || ! $this->settings->available($row, $amount)) {
                return ['outcome' => 'unavailable'];
            }
            $open = DB::table('order_payments')->where('order_id', $orderId)->where('kind', 'online')->whereIn('state', PaymentRules::OPEN)->lockForUpdate()->first();
            $stale = null;
            if ($open !== null) {
                if ($open->state === 'processing') {
                    return ['outcome' => 'pending'];
                }
                // Another request is still opening this attempt at the provider (a double click): never open a second one.
                if ($open->redirect_url === null && CarbonImmutable::parse($open->created_at)->greaterThan(now()->subSeconds(self::CLAIM_SECONDS))) {
                    return ['outcome' => 'pending'];
                }
                $fresh = $open->redirect_url !== null && CarbonImmutable::parse($open->created_at)->greaterThan(now()->subMinutes(self::ATTEMPT_MINUTES))
                    && (string) $open->payment_method_id === (string) $row->id && Decimal::compare($open->amount, $outstanding) === 0;
                if ($fresh) {
                    return ['outcome' => 'redirect', 'url' => $open->redirect_url];
                }
                DB::table('order_payments')->where('id', $open->id)->update(['state' => 'cancelled', 'detail' => 'expired', 'completed_at' => now(), 'updated_at' => now()]);
                $stale = $open;
            }
            $reference = (string) Str::uuid();
            $id = (string) DB::table('order_payments')->insertGetId([
                'reference' => $reference, 'order_id' => $orderId, 'payment_method_id' => $row->id, 'kind' => 'online', 'state' => 'created',
                'amount' => $outstanding, 'currency_code' => $order->currency_code, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return ['outcome' => 'new', 'id' => $id, 'reference' => $reference, 'row' => $row, 'amount' => $amount, 'order' => $order, 'stale' => $stale];
        });
        if ($decision['outcome'] !== 'new') {
            return $decision;
        }
        if ($decision['stale'] !== null) {
            // The replaced attempt must not stay payable at the provider.
            $this->expireAtProvider($decision['stale']);
        }
        /** @var RedirectPaymentMethod $method */
        $method = $this->settings->method($decision['row']);
        $locale = (string) DB::table('locales')->where('id', $decision['order']->locale_id)->value('code');
        try {
            $redirect = $method->start(new PaymentStart(
                $decision['reference'], $decision['amount'], $decision['order']->order_number, $locale,
                $this->links->returnUrl($decision['id']), $this->links->cancelUrl($decision['id']), $this->storeName(),
                is_string($decision['order']->email) && $decision['order']->email !== '' ? $decision['order']->email : null,
            ), $this->settings->values($method, $decision['row']));
        } catch (PaymentProviderUnavailable $e) {
            DB::table('order_payments')->where('id', $decision['id'])->where('state', 'created')->update(['state' => 'failed', 'detail' => $e->reason, 'completed_at' => now(), 'updated_at' => now()]);

            return ['outcome' => 'error'];
        }
        DB::table('order_payments')->where('id', $decision['id'])->where('state', 'created')
            ->update(['provider_reference' => mb_substr($redirect->providerReference, 0, 100), 'redirect_url' => $redirect->url, 'updated_at' => now()]);

        return ['outcome' => 'redirect', 'url' => $redirect->url];
    }

    /**
     * The customer is back from the provider: capture and verify server-side. Repeated or concurrent returns never
     * capture twice; the second caller sees the recorded outcome.
     *
     * @return string The attempt's state afterwards (or `invalid`, `unavailable`, `error`)
     */
    public function complete(string $paymentId, string $providerReference): string
    {
        $attempt = $this->attempt($paymentId, $providerReference);
        if ($attempt === null) {
            return 'invalid';
        }
        if (! in_array($attempt->state, PaymentRules::OPEN, true)) {
            return $attempt->state;
        }

        return $this->confirm($attempt, fn (RedirectPaymentMethod $method, PaymentCheck $check, array $values): PaymentResult => $method->complete($check, $values));
    }

    /** The customer cancelled at the provider: the open attempt ends; nothing was captured. */
    public function cancel(string $paymentId, string $providerReference): string
    {
        // Some providers cannot append their reference to the cancel URL; the caller has already verified our signature.
        $attempt = $providerReference === '' ? DB::table('order_payments')->where('id', $paymentId)->where('kind', 'online')->first() : $this->attempt($paymentId, $providerReference);
        if ($attempt === null) {
            return 'invalid';
        }
        $cancelled = DB::table('order_payments')->where('id', $attempt->id)->where('state', 'created')->update(['state' => 'cancelled', 'detail' => 'buyer_cancelled', 'completed_at' => now(), 'updated_at' => now()]);
        if ($cancelled > 0) {
            $this->expireAtProvider($attempt);
        }

        return (string) DB::table('order_payments')->where('id', $attempt->id)->value('state');
    }

    /**
     * A verified provider notification for an attempt. Open and pending attempts settle exactly like a confirmation on
     * return (same verification, same once-only transitions). Money the provider reports for an attempt the CMS had
     * already ended (cancelled or failed, e.g. paid just before a session expired) is still recorded as captured,
     * flagged `late_capture` or `duplicate_payment`, so received funds never go missing from the order. Final captured
     * attempts never change: stale or out-of-order failures cannot undo a payment.
     *
     * @return string The attempt's state afterwards
     */
    public function applyProviderResult(string $paymentId, PaymentResult $result): string
    {
        return DB::transaction(function () use ($paymentId, $result): string {
            $orderId = (string) DB::table('order_payments')->where('id', $paymentId)->value('order_id');
            $order = $this->order($orderId);
            $attempt = DB::table('order_payments')->where('id', $paymentId)->lockForUpdate()->first();
            if (in_array($attempt->state, [...PaymentRules::OPEN, 'pending'], true)) {
                return $this->settle($paymentId, $result);
            }
            if ($result->state === 'captured' && in_array($attempt->state, ['failed', 'cancelled'], true) && ! in_array($attempt->detail, ['verification_failed', 'duplicate_capture'], true)) {
                return $this->lateCapture($order, $attempt, $result);
            }

            return $attempt->state;
        });
    }

    private function lateCapture(object $order, object $attempt, PaymentResult $result): string
    {
        $matches = $result->currency === $attempt->currency_code && $result->amount !== null && Decimal::isCanonical($result->amount) && Decimal::compare($result->amount, $attempt->amount) === 0;
        $duplicate = $result->captureReference !== null && DB::table('order_payments')->where('payment_method_id', $attempt->payment_method_id)
            ->where('capture_reference', $result->captureReference)->where('id', '<>', $attempt->id)->exists();
        if (! $matches || $duplicate || $result->captureReference === null) {
            Log::warning('payment_late_capture_rejected', ['payment_id' => $attempt->id, 'order_id' => $order->id]);

            return $attempt->state;
        }
        $before = PaymentRules::orderStatus($this->payments((string) $order->id), $order->total);
        DB::table('order_payments')->where('id', $attempt->id)->update([
            'state' => 'captured', 'detail' => $before === 'paid' ? 'duplicate_payment' : 'late_capture', 'capture_reference' => mb_substr($result->captureReference, 0, 100),
            'claimed_at' => null, 'completed_at' => now(), 'updated_at' => now(),
        ]);
        // Funds arrived for an attempt we had ended: staff must see it (and refund a duplicate); nothing is hidden.
        Log::warning('payment_late_capture', ['payment_id' => $attempt->id, 'order_id' => $order->id, 'duplicate' => $before === 'paid']);
        $this->afterChange($order, $before, (string) $attempt->payment_method_id);

        return 'captured';
    }

    /** Best-effort: ends the unfinished provider payment of an attempt the CMS no longer wants paid. */
    private function expireAtProvider(object $attempt): void
    {
        if ($attempt->provider_reference === null) {
            return;
        }
        $resolved = $this->resolve($attempt);
        if ($resolved === null || ! $resolved[0] instanceof ExpiresProviderPayments) {
            return;
        }
        try {
            $resolved[0]->expire($this->check($attempt), $resolved[1]);
        } catch (Throwable $e) {
            Log::info('payment_expire_failed', ['payment_id' => $attempt->id, 'type' => $e::class]);
        }
    }

    /** Re-reads an unfinished attempt from the provider (staff "Check status", pending captures). */
    public function refresh(?string $actor, string $orderId, string $paymentId): string
    {
        if ($actor !== null) {
            app(AuditContext::class)->actor($actor);
            Gate::forUser(UserRecord::findOrFail($actor))->authorize('orders.payments');
        }
        $attempt = DB::table('order_payments')->where('id', $paymentId)->where('order_id', $orderId)->where('kind', 'online')->first();
        if ($attempt === null || $attempt->provider_reference === null || ! in_array($attempt->state, [...PaymentRules::OPEN, 'pending'], true)) {
            return $attempt->state ?? 'invalid';
        }
        $operation = fn (RedirectPaymentMethod $method, PaymentCheck $check, array $values): PaymentResult => $method->refresh($check, $values);
        if ($attempt->state === 'pending') {
            $method = $this->resolve($attempt);
            if ($method === null) {
                return 'unavailable';
            }
            try {
                $result = $operation($method[0], $this->check($attempt), $method[1]);
            } catch (PaymentProviderUnavailable) {
                return 'error';
            }

            return $this->settle((string) $attempt->id, $result);
        }

        return $this->confirm($attempt, $operation);
    }

    /**
     * Claims the attempt, asks the provider, and settles. A stale claim (a crashed request) can be taken over.
     *
     * @param  callable(RedirectPaymentMethod, PaymentCheck, array<string, string>): PaymentResult  $operation
     */
    private function confirm(object $attempt, callable $operation): string
    {
        $method = $this->resolve($attempt);
        if ($method === null) {
            return 'unavailable';
        }
        $claimed = DB::update("UPDATE order_payments SET state = 'processing', claimed_at = now(), updated_at = now()
            WHERE id = ? AND (state = 'created' OR (state = 'processing' AND claimed_at < now() - make_interval(secs => ?)))", [$attempt->id, self::CLAIM_SECONDS]);
        if ($claimed === 0) {
            return (string) DB::table('order_payments')->where('id', $attempt->id)->value('state');
        }
        try {
            $result = $operation($method[0], $this->check($attempt), $method[1]);
        } catch (PaymentProviderUnavailable) {
            // Nothing was decided: release the claim so the customer or staff can try again.
            DB::table('order_payments')->where('id', $attempt->id)->where('state', 'processing')->update(['state' => 'created', 'claimed_at' => null, 'updated_at' => now()]);

            return 'error';
        } catch (Throwable $e) {
            DB::table('order_payments')->where('id', $attempt->id)->where('state', 'processing')->update(['state' => 'created', 'claimed_at' => null, 'updated_at' => now()]);
            throw $e;
        }

        return $this->settle((string) $attempt->id, $result);
    }

    /** Records a provider outcome once. Captures must match the requested amount, currency and our reference. */
    private function settle(string $paymentId, PaymentResult $result): string
    {
        return DB::transaction(function () use ($paymentId, $result): string {
            $orderId = (string) DB::table('order_payments')->where('id', $paymentId)->value('order_id');
            $order = $this->order($orderId);
            $attempt = DB::table('order_payments')->where('id', $paymentId)->lockForUpdate()->first();
            if (! in_array($attempt->state, ['created', 'processing', 'pending'], true)) {
                return $attempt->state;
            }
            $before = PaymentRules::orderStatus($this->payments($orderId), $order->total);
            $state = match ($result->state) {
                'open' => 'created',
                default => $result->state,
            };
            $detail = $result->detail;
            if ($state === 'captured' || $state === 'pending') {
                $matches = $result->currency === $attempt->currency_code && $result->amount !== null && Decimal::isCanonical($result->amount)
                    && Decimal::compare($result->amount, $attempt->amount) === 0;
                $duplicate = $result->captureReference !== null && DB::table('order_payments')->where('payment_method_id', $attempt->payment_method_id)
                    ->where('capture_reference', $result->captureReference)->where('id', '<>', $attempt->id)->exists();
                if (! $matches || $duplicate || ($state === 'captured' && $result->captureReference === null)) {
                    Log::warning('payment_verification_failed', ['payment_id' => $paymentId, 'order_id' => $orderId, 'duplicate' => $duplicate]);
                    [$state, $detail] = ['failed', $duplicate ? 'duplicate_capture' : 'verification_failed'];
                }
            }
            if ($state !== $attempt->state && ! PaymentRules::canMove($attempt->state, $state)) {
                return $attempt->state;
            }
            $final = in_array($state, ['captured', 'failed', 'cancelled'], true);
            DB::table('order_payments')->where('id', $paymentId)->update([
                'state' => $state, 'detail' => $detail, 'claimed_at' => null,
                'capture_reference' => $state === 'failed' ? $attempt->capture_reference : ($result->captureReference === null ? $attempt->capture_reference : mb_substr($result->captureReference, 0, 100)),
                'completed_at' => $final ? now() : null, 'updated_at' => now(),
            ]);
            $this->afterChange($order, $before, (string) $attempt->payment_method_id);

            return $state;
        });
    }

    /** Applies the method's "status after payment" once, when the order has just become fully paid. */
    private function afterChange(object $order, string $before, ?string $methodId): void
    {
        $after = PaymentRules::orderStatus($this->payments((string) $order->id), $order->total);
        if ($before === 'paid' || $after !== 'paid' || $methodId === null) {
            return;
        }
        $statusId = DB::table('payment_methods')->where('id', $methodId)->value('paid_status_id');
        if ($statusId === null || (string) $statusId === (string) $order->status_id) {
            return;
        }
        $locale = (string) DB::table('locales')->where('id', $order->locale_id)->value('code');
        try {
            app(OrderAdministration::class)->changeStatus(null, (string) $order->id, null, (string) $statusId, __('payments.paid_note', ['method' => $this->settings->name($methodId, $locale)], $locale), 'system');
        } catch (Throwable $e) {
            // The payment stays recorded; staff move the order manually (e.g. not enough stock for the target status).
            Log::warning('payment_paid_status_failed', ['order_id' => $order->id, 'type' => $e::class]);
        }
    }

    /** @return array{0: RedirectPaymentMethod, 1: array<string, string>}|null */
    private function resolve(object $attempt): ?array
    {
        $row = DB::table('payment_methods')->where('id', $attempt->payment_method_id)->first();
        $method = $row === null ? null : $this->settings->method($row);
        if (! $method instanceof RedirectPaymentMethod || ! $this->settings->ownerEnabled($row)) {
            return null;
        }

        return [$method, $this->settings->values($method, $row)];
    }

    private function check(object $attempt): PaymentCheck
    {
        $decimals = (int) DB::table('orders')->where('id', $attempt->order_id)->value('currency_decimals');

        return new PaymentCheck($attempt->reference, (string) $attempt->provider_reference, new PaymentAmount(Decimal::trim($attempt->amount), $attempt->currency_code, $decimals));
    }

    /** The online attempt matching both our ID and the provider reference the provider sent back. */
    private function attempt(string $paymentId, string $providerReference): ?object
    {
        $attempt = DB::table('order_payments')->where('id', $paymentId)->where('kind', 'online')->first();

        return $attempt !== null && $attempt->provider_reference !== null && $providerReference !== '' && hash_equals($attempt->provider_reference, $providerReference) ? $attempt : null;
    }

    private function order(string $orderId): object
    {
        return DB::table('orders')->where('id', $orderId)->lockForUpdate()->first() ?? throw ValidationException::withMessages(['order' => __('payments.order_missing')]);
    }

    /** @return list<array{state: string, amount: string}> */
    private function payments(string $orderId): array
    {
        return DB::table('order_payments')->where('order_id', $orderId)->get(['state', 'amount'])
            ->map(fn (object $p): array => ['state' => $p->state, 'amount' => Decimal::trim($p->amount)])->all();
    }

    private function storeName(): string
    {
        return mb_substr((string) (app(CompanySettings::class)->all()['name'] ?: config('app.name')), 0, 127);
    }
}
