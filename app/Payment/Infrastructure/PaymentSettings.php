<?php

namespace App\Payment\Infrastructure;

use App\Payment\Application\Command\PaymentAmount;
use App\Payment\Application\Contract\PaymentMethod;
use App\Payment\Application\Contract\RedirectPaymentMethod;
use App\Payment\Application\Service\PaymentMethodRegistry;
use App\Shared\Infrastructure\Modules\AddonRuntime;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Stored configuration of registered methods. Plain settings are JSON; secret settings are one JSON object encrypted
 * with the application key, decrypted only on the server when a provider is called. A method is available only when
 * it is registered by core or by an add-on that is still enabled, active, completely configured, verified (redirect
 * methods) and supports the amount.
 */
final class PaymentSettings
{
    public function __construct(private PaymentMethodRegistry $registry, private AddonRuntime $addons) {}

    /**
     * Creates the row and default names (every language) of registered methods that have none. Cash on delivery starts
     * enabled; bank deposit starts disabled until its description carries the store's IBANs; add-on methods start
     * disabled until configured.
     */
    public function ensure(): void
    {
        $existing = DB::table('payment_methods')->pluck('code')->all();
        $locales = DB::table('locales')->get(['id', 'code']);
        foreach ($this->registry->ids() as $code) {
            if (in_array($code, $existing, true)) {
                continue;
            }
            $method = $this->registry->get($code);
            $owner = (string) $this->registry->owner($code);
            DB::table('payment_methods')->insertOrIgnore([
                'code' => $code, 'owner' => $owner, 'is_active' => $owner === PaymentMethodRegistry::CORE && $code === CashOnDelivery::ID,
                'position' => min(99999, (int) DB::table('payment_methods')->max('position') + 10), 'settings' => '{}', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $id = DB::table('payment_methods')->where('code', $code)->value('id');
            foreach ($locales as $locale) {
                $text = $method->defaultText($locale->code);
                $name = mb_substr(trim($text['name']), 0, 100);
                DB::table('payment_method_translations')->insertOrIgnore(['method_id' => $id, 'locale_id' => $locale->id, 'name' => $name === '' ? $code : $name, 'description' => mb_substr(trim($text['description']), 0, 500)]);
            }
        }
    }

    public function row(string $code): ?object
    {
        return DB::table('payment_methods')->where('code', $code)->first();
    }

    /** The registered method behind a stored row, unless its owner changed or its add-on is no longer loaded. */
    public function method(object $row): ?PaymentMethod
    {
        return $this->registry->has($row->code) && $this->registry->owner($row->code) === $row->owner ? $this->registry->get($row->code) : null;
    }

    public function ownerEnabled(object $row): bool
    {
        return $row->owner === PaymentMethodRegistry::CORE || $this->addons->isEnabled($row->owner);
    }

    /**
     * Current values with defaults applied; secrets decrypted.
     *
     * @return array<string, string>
     */
    public function values(PaymentMethod $method, object $row): array
    {
        $stored = json_decode((string) $row->settings, true) ?: [];
        $secrets = $this->secrets($row);
        $values = [];
        foreach ($method->settings() as $field) {
            $values[$field->key] = $field->type === 'secret' ? (string) ($secrets[$field->key] ?? '') : (string) ($stored[$field->key] ?? $field->default);
        }

        return $values;
    }

    /** @return array<string, string> */
    public function secrets(object $row): array
    {
        if ($row->secrets === null || $row->secrets === '') {
            return [];
        }
        try {
            $decoded = json_decode(Crypt::decryptString($row->secrets), true);

            return is_array($decoded) ? array_map(strval(...), $decoded) : [];
        } catch (DecryptException) {
            // A rotated application key makes stored secrets unreadable; the method then reports missing settings.
            Log::warning('payment_secrets_unreadable', ['method' => $row->code]);

            return [];
        }
    }

    /** @param array<string, string> $secrets */
    public function encrypt(array $secrets): ?string
    {
        $secrets = array_filter($secrets, fn (string $v): bool => $v !== '');

        return $secrets === [] ? null : Crypt::encryptString(json_encode($secrets, JSON_THROW_ON_ERROR));
    }

    /**
     * HMAC of the credential values, so verification can be recorded without storing the credentials twice.
     *
     * @param  array<string, string>  $values
     */
    public function fingerprint(PaymentMethod $method, array $values): string
    {
        $credentials = [];
        foreach ($method->settings() as $field) {
            if ($field->credential) {
                $credentials[$field->key] = $values[$field->key] ?? '';
            }
        }

        return hash_hmac('sha256', $method->id().'|'.json_encode($credentials, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    /**
     * Whether every required setting has a value.
     *
     * @param  array<string, string>  $values
     */
    public function complete(PaymentMethod $method, array $values): bool
    {
        foreach ($method->settings() as $field) {
            if ($field->required && trim($values[$field->key] ?? '') === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Configuration problems shown to administrators; a method with any of them is never offered.
     *
     * @return list<string>
     */
    public function issues(?PaymentMethod $method, object $row): array
    {
        if ($method === null || ! $this->ownerEnabled($row)) {
            return [__('payments.issue_addon_disabled')];
        }
        $issues = [];
        $values = $this->values($method, $row);
        foreach ($method->settings() as $field) {
            if ($field->required && trim($values[$field->key]) === '') {
                $issues[] = __('payments.issue_required', ['field' => __($field->label)]);
            }
        }
        if ($issues === [] && $method instanceof RedirectPaymentMethod && $row->verified_fingerprint !== $this->fingerprint($method, $values)) {
            $issues[] = __('payments.issue_unverified');
        }

        return $issues;
    }

    public function available(object $row, PaymentAmount $amount): bool
    {
        $method = $this->method($row);

        return $method !== null && $row->is_active && $this->issues($method, $row) === [] && $method->supports($amount, $this->values($method, $row));
    }

    /** The method's customer-facing name in $locale, then the default language, then its code. */
    public function name(string $methodId, string $locale): string
    {
        $names = DB::table('payment_method_translations as t')->join('locales as l', 'l.id', '=', 't.locale_id')->where('t.method_id', $methodId)->pluck('t.name', 'l.code');
        $default = (string) DB::table('locales')->where('is_default', true)->value('code');

        return (string) ($names[$locale] ?? $names[$default] ?? DB::table('payment_methods')->where('id', $methodId)->value('code'));
    }

    /** The method's customer-facing description (e.g. the bank deposit's IBANs) in $locale, then the default language. */
    public function description(string $methodId, string $locale): string
    {
        $texts = DB::table('payment_method_translations as t')->join('locales as l', 'l.id', '=', 't.locale_id')->where('t.method_id', $methodId)->pluck('t.description', 'l.code');
        $default = (string) DB::table('locales')->where('is_default', true)->value('code');

        return trim((string) ($texts[$locale] ?? '')) !== '' ? trim((string) $texts[$locale]) : trim((string) ($texts[$default] ?? ''));
    }
}
