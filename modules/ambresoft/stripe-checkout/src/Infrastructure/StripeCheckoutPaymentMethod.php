<?php

namespace Ambresoft\StripeCheckout\Infrastructure;

use Ambresoft\StripeCheckout\Domain\StripeCurrencies;
use App\Payment\Application\Command\PaymentAmount;
use App\Payment\Application\Command\PaymentCheck;
use App\Payment\Application\Command\PaymentRedirect;
use App\Payment\Application\Command\PaymentResult;
use App\Payment\Application\Command\PaymentStart;
use App\Payment\Application\Command\PaymentWebhook;
use App\Payment\Application\Command\PaymentWebhookEvent;
use App\Payment\Application\Command\SettingField;
use App\Payment\Application\Contract\ExpiresProviderPayments;
use App\Payment\Application\Contract\HandlesPaymentWebhooks;
use App\Payment\Application\Contract\PaymentProviderUnavailable;
use App\Payment\Application\Contract\PaymentWebhookRejected;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\PermissionException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\Webhook;

/**
 * Stripe Checkout in hosted, one-time payment mode. The CMS opens a Checkout Session for the server-calculated amount
 * (a single labelled order-total line; Stripe never recalculates tax, discounts or shipping), sends the customer to
 * Stripe's page and learns the outcome from signed webhooks (the return visit also reads the session server-side, so
 * the URL alone is never proof). Every outcome is verified against our own attempt (reference, amount, currency)
 * by the CMS before money is recorded. The PaymentIntent ID is stored as the capture reference for later refunds.
 */
final class StripeCheckoutPaymentMethod implements ExpiresProviderPayments, HandlesPaymentWebhooks
{
    public const ID = 'stripe_checkout';

    /** Hosted Checkout Sessions live for an hour, like the CMS payment attempts. */
    private const SESSION_SECONDS = 3600;

    /** Languages Checkout renders natively among the CMS's languages. */
    private const LOCALES = ['el', 'en', 'es', 'fr', 'it', 'nl'];

    public function __construct(private StripeClients $clients) {}

    public function id(): string
    {
        return self::ID;
    }

    public function provider(): string
    {
        return 'Stripe';
    }

    public function defaultText(string $locale): array
    {
        return ['name' => __('ambresoft-stripe::stripe.default_name', [], $locale), 'description' => __('ambresoft-stripe::stripe.default_description', [], $locale)];
    }

    public function settings(): array
    {
        return [
            new SettingField('mode', 'select', 'ambresoft-stripe::stripe.mode', 'ambresoft-stripe::stripe.mode_hint', true,
                ['test' => 'ambresoft-stripe::stripe.mode_test', 'live' => 'ambresoft-stripe::stripe.mode_live'], 'test', true),
            new SettingField('secret_key', 'secret', 'ambresoft-stripe::stripe.secret_key', 'ambresoft-stripe::stripe.secret_key_hint', true, credential: true, maxLength: 250),
            new SettingField('webhook_secret', 'secret', 'ambresoft-stripe::stripe.webhook_secret', 'ambresoft-stripe::stripe.webhook_secret_hint', true, maxLength: 250),
        ];
    }

    public function validate(array $values): array
    {
        $errors = [];
        $key = $values['secret_key'] ?? '';
        if ($key !== '') {
            if (preg_match('/^(?:sk|rk)_(test|live)_[A-Za-z0-9]{10,240}$/D', $key, $m) !== 1) {
                $errors['secret_key'] = __('ambresoft-stripe::stripe.secret_key_invalid');
            } elseif ($m[1] !== ($values['mode'] ?? 'test')) {
                // Test and live credentials are never mixed.
                $errors['secret_key'] = __('ambresoft-stripe::stripe.secret_key_mode', ['mode' => __('ambresoft-stripe::stripe.mode_'.($values['mode'] ?? 'test'))]);
            }
        }
        if (($values['webhook_secret'] ?? '') !== '' && preg_match('/^whsec_[A-Za-z0-9+\/=_-]{10,240}$/D', $values['webhook_secret']) !== 1) {
            $errors['webhook_secret'] = __('ambresoft-stripe::stripe.webhook_secret_invalid');
        }

        return $errors;
    }

    public function supports(PaymentAmount $amount, array $values): bool
    {
        return StripeCurrencies::supports($amount->currency, $amount->decimals)
            && ($amount->value === '0' || StripeCurrencies::toMinor($amount->value, $amount->currency) !== null);
    }

    public function verify(array $values): ?string
    {
        // A harmless read that every Checkout-capable key may perform; run only when credentials are saved.
        try {
            $this->clients->make($values)->checkout->sessions->all(['limit' => 1]);

            return null;
        } catch (AuthenticationException|PermissionException) {
            return 'credentials_rejected';
        } catch (ApiConnectionException) {
            throw new PaymentProviderUnavailable('provider_unavailable');
        } catch (ApiErrorException $e) {
            Log::info('stripe_verify_failed', ['type' => $e::class, 'status' => $e->getHttpStatus()]);

            return 'credentials_rejected';
        }
    }

    public function start(PaymentStart $start, array $values): PaymentRedirect
    {
        $minor = StripeCurrencies::toMinor($start->amount->value, $start->amount->currency) ?? throw new PaymentProviderUnavailable('amount_unsupported');
        $name = mb_substr(__('ambresoft-stripe::stripe.order_description', ['number' => $start->orderNumber, 'store' => $start->storeName], $start->locale), 0, 250);
        $metadata = ['payment_reference' => $start->reference, 'order_number' => mb_substr($start->orderNumber, 0, 100)];
        // Prefilled so the customer does not type the checkout e-mail again; Stripe rejects malformed addresses.
        $email = $start->email !== null && filter_var($start->email, FILTER_VALIDATE_EMAIL) !== false ? ['customer_email' => $start->email] : [];
        try {
            $session = $this->clients->make($values)->checkout->sessions->create([
                ...$email,
                'mode' => 'payment',
                'client_reference_id' => $start->reference,
                'success_url' => $start->returnUrl.(str_contains($start->returnUrl, '?') ? '&' : '?').'session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $start->cancelUrl,
                'expires_at' => time() + self::SESSION_SECONDS,
                'locale' => in_array($start->locale, self::LOCALES, true) ? $start->locale : 'auto',
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => ['currency' => strtolower($start->amount->currency), 'unit_amount' => (int) $minor, 'product_data' => ['name' => $name]],
                ]],
                'metadata' => $metadata,
                'payment_intent_data' => ['metadata' => $metadata, 'description' => $name],
            ], ['idempotency_key' => 'cms-create-'.$start->reference]);
        } catch (ApiConnectionException) {
            throw new PaymentProviderUnavailable('provider_unavailable');
        } catch (AuthenticationException|PermissionException) {
            throw new PaymentProviderUnavailable('credentials_rejected');
        } catch (InvalidRequestException $e) {
            Log::warning('stripe_session_rejected', ['status' => $e->getHttpStatus(), 'code' => $e->getStripeCode()]);
            throw new PaymentProviderUnavailable('provider_rejected');
        } catch (ApiErrorException $e) {
            Log::warning('stripe_session_failed', ['type' => $e::class, 'status' => $e->getHttpStatus()]);
            throw new PaymentProviderUnavailable('provider_error');
        }
        $id = $session->id ?? null;
        $url = $session->url ?? null;
        // Only ever send the customer to Stripe itself.
        if (! is_string($id) || ! str_starts_with($id, 'cs_') || ! is_string($url) || ! str_starts_with($url, 'https://checkout.stripe.com/')) {
            throw new PaymentProviderUnavailable('provider_error');
        }

        return new PaymentRedirect($id, $url);
    }

    public function complete(PaymentCheck $check, array $values): PaymentResult
    {
        return $this->read($check, $values);
    }

    public function refresh(PaymentCheck $check, array $values): PaymentResult
    {
        return $this->read($check, $values);
    }

    public function expire(PaymentCheck $check, array $values): void
    {
        try {
            $this->clients->make($values)->checkout->sessions->expire($check->providerReference);
        } catch (ApiErrorException) {
            // Already expired or completed: nothing left to end.
        }
    }

    public function webhook(PaymentWebhook $webhook, array $values): PaymentWebhookEvent
    {
        $secret = $values['webhook_secret'] ?? '';
        if ($secret === '') {
            throw new PaymentWebhookRejected(PaymentWebhookRejected::NOT_CONFIGURED);
        }
        // The raw body, exactly as received, is what Stripe signed.
        try {
            $event = Webhook::constructEvent($webhook->body, $webhook->header('stripe-signature'), $secret);
        } catch (SignatureVerificationException) {
            throw new PaymentWebhookRejected(PaymentWebhookRejected::INVALID_SIGNATURE);
        } catch (UnexpectedValueException|\UnexpectedValueException|\JsonException) {
            throw new PaymentWebhookRejected(PaymentWebhookRejected::MALFORMED);
        }
        $id = (string) ($event->id ?? '');
        $type = (string) ($event->type ?? '');
        if ($id === '' || $type === '') {
            throw new PaymentWebhookRejected(PaymentWebhookRejected::MALFORMED);
        }
        $object = $event->data->object ?? null;
        $handled = ['checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed', 'checkout.session.expired'];
        $live = ($values['mode'] ?? 'test') === 'live';
        // Other events, other kinds of sessions and events of the other mode change nothing.
        if (! in_array($type, $handled, true) || ! $object instanceof Session || ($object->mode ?? null) !== 'payment' || (bool) ($event->livemode ?? false) !== $live) {
            return new PaymentWebhookEvent($id, $type);
        }
        $reference = is_string($object->client_reference_id ?? null) ? $object->client_reference_id : null;
        $result = match ($type) {
            'checkout.session.expired' => new PaymentResult('cancelled', detail: 'session_expired'),
            'checkout.session.async_payment_failed' => new PaymentResult('failed', detail: 'async_payment_failed'),
            'checkout.session.async_payment_succeeded' => $this->paid($object) ?? new PaymentResult('failed', detail: 'verification_failed'),
            default => $this->completed($object),
        };

        return new PaymentWebhookEvent($id, $type, (string) $object->id, $reference, $result);
    }

    /**
     * Reads the session from Stripe (return visit, staff "Check status") and translates it.
     *
     * @param  array<string, string>  $values
     */
    private function read(PaymentCheck $check, array $values): PaymentResult
    {
        try {
            $session = $this->clients->make($values)->checkout->sessions->retrieve($check->providerReference, ['expand' => ['payment_intent']]);
        } catch (ApiConnectionException) {
            throw new PaymentProviderUnavailable('provider_unavailable');
        } catch (InvalidRequestException $e) {
            return new PaymentResult('failed', detail: $e->getHttpStatus() === 404 ? 'provider_not_found' : 'provider_rejected');
        } catch (AuthenticationException|PermissionException) {
            throw new PaymentProviderUnavailable('credentials_rejected');
        } catch (ApiErrorException) {
            throw new PaymentProviderUnavailable('provider_error');
        }
        if (($session->client_reference_id ?? null) !== $check->reference || ($session->mode ?? null) !== 'payment') {
            return new PaymentResult('failed', detail: 'verification_failed');
        }
        $intent = $session->payment_intent ?? null;
        $status = is_object($intent) ? ($intent->status ?? null) : null;

        return match ($session->status ?? null) {
            'expired' => new PaymentResult('cancelled', detail: 'session_expired'),
            'complete' => ($session->payment_status ?? null) === 'unpaid' && in_array($status, ['requires_payment_method', 'canceled'], true)
                ? new PaymentResult('failed', detail: 'async_payment_failed')
                : $this->completed($session),
            default => new PaymentResult('open'),
        };
    }

    /** A completed session: paid now, still awaiting a delayed payment method, or not a payment at all. */
    private function completed(Session $session): PaymentResult
    {
        return match ($session->payment_status ?? null) {
            'paid' => $this->paid($session) ?? new PaymentResult('failed', detail: 'verification_failed'),
            'unpaid' => $this->money($session, 'pending', 'payment_pending') ?? new PaymentResult('failed', detail: 'verification_failed'),
            default => new PaymentResult('failed', detail: 'verification_failed'),
        };
    }

    private function paid(Session $session): ?PaymentResult
    {
        return ($session->payment_status ?? null) === 'paid' ? $this->money($session, 'captured', null) : null;
    }

    /** The session's amount and PaymentIntent as a result; the CMS compares them with the attempt before recording. */
    private function money(Session $session, string $state, ?string $detail): ?PaymentResult
    {
        $currency = is_string($session->currency ?? null) ? strtoupper($session->currency) : '';
        $total = $session->amount_total ?? null;
        $amount = is_int($total) ? StripeCurrencies::fromMinor($total, $currency) : null;
        $intent = $session->payment_intent ?? null;
        $intentId = is_object($intent) ? ($intent->id ?? null) : $intent;
        if ($amount === null || ($state === 'captured' && (! is_string($intentId) || $intentId === '')) || ($amount === '0')) {
            return null;
        }

        return new PaymentResult($state, is_string($intentId) && $intentId !== '' ? $intentId : null, $amount, $currency, $detail);
    }
}
