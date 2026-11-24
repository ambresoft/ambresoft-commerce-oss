<?php

namespace App\Settings\Infrastructure;

use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Catalog\Domain\Service\ProductRules;
use App\Catalog\Infrastructure\ProductWeightConversion;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Shared\Domain\Service\Decimal;
use App\Shared\Infrastructure\Geography\Countries;
use App\Tax\Domain\Model\CalculationSettings;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Installation-wide settings (one typed row). Product-level values may override a
 * setting; reading the effective value is the caller's rule (see ProductRules).
 */
final class GeneralSettings
{
    /** Order email sender; empty means order emails cannot be sent (recorded as failed). */
    public const ORDER_EMAIL_FIELDS = ['order_email_from', 'order_email_from_name', 'order_email_reply_to'];

    /** Delivery method and its settings; the SMTP password is handled separately (write-only, encrypted). */
    public const MAIL_DELIVERY_FIELDS = ['mail_method', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_auth', 'smtp_username', 'sendmail_path'];

    public const MAIL_METHODS = ['php_mail', 'smtp', 'sendmail'];

    public const SMTP_ENCRYPTIONS = ['none', 'tls', 'ssl'];

    /** Programs accepted as "sendmail": a free path would let an administrator run any program on the server. */
    public const SENDMAIL_PROGRAMS = ['sendmail', 'msmtp', 'ssmtp', 'exim', 'exim4', 'esmtp'];

    private const TAX_FIELDS = ['tax_calculation_method', 'display_catalog_prices_with_tax', 'display_cart_prices_with_tax', 'tax_estimate_without_address', 'tax_default_country', 'tax_default_region'];

    /** @var array<string, mixed>|null */
    private ?array $cached = null;

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->cached ??= (function (): array {
            $row = DB::table('general_settings')->where('id', 1)->first();

            $basic = [];
            foreach (GeneralOptions::DEFAULTS as $field => $default) {
                $basic[$field] = match (true) {
                    is_bool($default) => (bool) ($row->$field ?? $default),
                    is_int($default) => (int) ($row->$field ?? $default),
                    default => (string) ($row->$field ?? $default),
                };
            }

            $basic['grams_per_weight_unit'] = Decimal::trim($basic['grams_per_weight_unit']);

            return [
                ...$basic,
                'bestsellers_excluded_categories' => DB::table('bestseller_excluded_categories')->pluck('category_id')->map(strval(...))->all(),
                'closed_messages' => DB::table('general_settings_translations as t')->join('locales as l', 'l.id', '=', 't.locale_id')->where('t.settings_id', 1)->pluck('t.storefront_closed_message', 'l.code')->all(),
                'price_hidden_messages' => DB::table('general_settings_translations as t')->join('locales as l', 'l.id', '=', 't.locale_id')->where('t.settings_id', 1)->pluck('t.price_hidden_message', 'l.code')->all(),
                'out_of_stock_action' => $row->out_of_stock_action ?? ProductRules::DEFAULT_OUT_OF_STOCK_ACTION,
                'version' => (int) ($row->lock_version ?? 1),
                'tax_calculation_method' => $row->tax_calculation_method ?? CalculationSettings::UNIT_PRICE,
                'display_catalog_prices_with_tax' => (bool) ($row->display_catalog_prices_with_tax ?? false),
                'display_cart_prices_with_tax' => (bool) ($row->display_cart_prices_with_tax ?? false),
                'tax_estimate_without_address' => (bool) ($row->tax_estimate_without_address ?? false),
                'tax_default_country' => $row->tax_default_country ?? null,
                'tax_default_region' => $row->tax_default_region ?? null,
                'order_email_from' => $row->order_email_from ?? null,
                'order_email_from_name' => $row->order_email_from_name ?? null,
                'order_email_reply_to' => $row->order_email_reply_to ?? null,
                'mail_method' => $row->mail_method ?? 'php_mail',
                'smtp_host' => $row->smtp_host ?? null,
                'smtp_port' => isset($row->smtp_port) ? (int) $row->smtp_port : null,
                'smtp_encryption' => $row->smtp_encryption ?? 'none',
                'smtp_auth' => (bool) ($row->smtp_auth ?? false),
                'smtp_username' => $row->smtp_username ?? null,
                // Only whether a password is stored: the value itself never leaves the server.
                'smtp_password_set' => ($row->smtp_password ?? null) !== null,
                'sendmail_path' => $row->sendmail_path ?? '/usr/sbin/sendmail',
            ];
        })();
    }

    /**
     * Delivery configuration for the mail transport, including the decrypted SMTP password. Server-side use only.
     *
     * @return array{method: string, host: ?string, port: ?int, encryption: string, auth: bool, username: ?string, password: ?string, sendmail: string}
     */
    public function mailDelivery(): array
    {
        $row = DB::table('general_settings')->where('id', 1)->first();
        $password = null;
        if (($row->smtp_password ?? null) !== null) {
            try {
                $password = Crypt::decryptString($row->smtp_password);
            } catch (DecryptException) {
                // A rotated application key leaves an unreadable password: treat it as missing.
                $password = null;
            }
        }

        return ['method' => $row->mail_method ?? 'php_mail', 'host' => $row->smtp_host ?? null, 'port' => isset($row->smtp_port) ? (int) $row->smtp_port : null, 'encryption' => $row->smtp_encryption ?? 'none',
            'auth' => (bool) ($row->smtp_auth ?? false), 'username' => $row->smtp_username ?? null, 'password' => $password, 'sendmail' => $row->sendmail_path ?? '/usr/sbin/sendmail'];
    }

    /**
     * Whether the storefront must not display product prices. This is the single switch for every product, listing,
     * search result and cart view; the administration keeps showing and editing prices regardless.
     */
    public function pricesHidden(): bool
    {
        return (bool) $this->all()['hide_product_prices'];
    }

    /** The merchant's text shown in place of a hidden price for a language; empty when none is configured. */
    public function priceHiddenMessage(string $locale): string
    {
        return (string) ($this->all()['price_hidden_messages'][$locale] ?? '');
    }

    public function outOfStockAction(): string
    {
        return (string) $this->all()['out_of_stock_action'];
    }

    /**
     * Omitted fields keep their saved values, so callers written before a setting existed stay valid.
     *
     * @param  array<string, mixed>  $fields
     */
    public function save(string $actor, array $fields): void
    {
        foreach (['weight_unit', 'grams_per_weight_unit'] as $field) {
            if (isset($fields[$field]) && is_string($fields[$field])) {
                $fields[$field] = trim($fields[$field]);
            }
        }
        foreach (['tax_default_country', 'tax_default_region'] as $field) {
            if (isset($fields[$field]) && is_string($fields[$field])) {
                $fields[$field] = strtoupper(trim($fields[$field]));
            }
        }
        foreach (['smtp_host', 'smtp_username', 'sendmail_path'] as $field) {
            if (array_key_exists($field, $fields) && is_string($fields[$field])) {
                $fields[$field] = trim($fields[$field]) === '' ? null : trim($fields[$field]);
            }
        }
        if (array_key_exists('smtp_port', $fields) && ($fields['smtp_port'] === '' || $fields['smtp_port'] === null)) {
            $fields['smtp_port'] = null;
        }
        foreach (self::ORDER_EMAIL_FIELDS as $field) {
            if (array_key_exists($field, $fields) && is_string($fields[$field])) {
                $value = trim($fields[$field]);
                $fields[$field] = $value === '' ? null : ($field === 'order_email_from_name' ? $value : strtolower($value));
            }
        }
        app(AuditLogger::class)->operation('general_settings', $actor, function () use ($actor, $fields): void {
            DB::transaction(function () use ($actor, $fields): void {
                app(AuditContext::class)->actor($actor);
                Gate::forUser(UserRecord::findOrFail($actor))->authorize('settings.manage');
                // Tax amounts follow the primary currency: serialize with its precision and conversion operations.
                DB::select('SELECT pg_advisory_xact_lock(2142513)');
                $row = DB::table('general_settings')->where('id', 1)->lockForUpdate()->first();
                $v = Validator::make($fields, $this->rules(), $this->messages())->after(function ($validator) use ($actor, $fields, $row): void {
                    if (array_key_exists('brand_landing_pages', $fields) && filter_var($fields['brand_landing_pages'], FILTER_VALIDATE_BOOLEAN) !== (bool) $row->brand_landing_pages) {
                        Gate::forUser(UserRecord::findOrFail($actor))->authorize('settings.brand-landing-pages');
                        if (! filter_var($fields['brand_landing_pages_confirmed'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                            $validator->errors()->add('brand_landing_pages', __('settings.landing_source_confirmation'));
                        }
                    }
                    $estimate = array_key_exists('tax_estimate_without_address', $fields) ? filter_var($fields['tax_estimate_without_address'], FILTER_VALIDATE_BOOLEAN) : (bool) $row->tax_estimate_without_address;
                    $country = array_key_exists('tax_default_country', $fields) ? ($fields['tax_default_country'] ?: null) : $row->tax_default_country;
                    $region = array_key_exists('tax_default_region', $fields) ? ($fields['tax_default_region'] ?: null) : $row->tax_default_region;
                    if ($estimate && $country === null) {
                        $validator->errors()->add('tax_default_country', __('settings.tax_default_country_required'));
                    }
                    if ($region !== null && $country === null) {
                        $validator->errors()->add('tax_default_region', __('settings.tax_default_region_needs_country'));
                    }
                    // The resulting delivery configuration must be usable.
                    $pick = fn (string $field): mixed => array_key_exists($field, $fields) ? $fields[$field] : $row->$field;
                    if ($pick('mail_method') === 'smtp' && ($pick('smtp_host') === null || $pick('smtp_host') === '')) {
                        $validator->errors()->add('smtp_host', __('settings.smtp_host_required'));
                    }
                    if ($pick('mail_method') === 'smtp' && filter_var($pick('smtp_auth'), FILTER_VALIDATE_BOOLEAN)) {
                        if ($pick('smtp_username') === null || $pick('smtp_username') === '') {
                            $validator->errors()->add('smtp_username', __('settings.smtp_username_required'));
                        }
                        $clearing = filter_var($fields['smtp_password_clear'] ?? false, FILTER_VALIDATE_BOOLEAN);
                        if ((($fields['smtp_password'] ?? '') === '' || $fields['smtp_password'] === null) && ($row->smtp_password === null || $clearing)) {
                            $validator->errors()->add('smtp_password', __('settings.smtp_password_required'));
                        }
                    }
                    $path = $pick('sendmail_path');
                    if (is_string($path) && ! in_array(basename($path), self::SENDMAIL_PROGRAMS, true)) {
                        $validator->errors()->add('sendmail_path', __('settings.sendmail_path_invalid'));
                    }
                })->validate();
                if ((int) $row->lock_version !== (int) $v['version']) {
                    throw ValidationException::withMessages(['version' => __('settings.stale')]);
                }
                $values = ['out_of_stock_action' => $v['out_of_stock_action']];
                foreach (GeneralOptions::DEFAULTS as $field => $default) {
                    if (array_key_exists($field, $v)) {
                        $values[$field] = is_bool($default) ? (bool) $v[$field] : $v[$field];
                    }
                }
                if (array_key_exists('bestsellers_excluded_categories', $v)) {
                    DB::table('bestseller_excluded_categories')->delete();
                    foreach ($v['bestsellers_excluded_categories'] as $category) {
                        DB::table('bestseller_excluded_categories')->insert(['category_id' => $category]);
                    }
                }
                $factor = (string) ($values['grams_per_weight_unit'] ?? $row->grams_per_weight_unit);
                if (Decimal::compare($factor, (string) $row->grams_per_weight_unit) !== 0) {
                    app(ProductWeightConversion::class)->convert((string) $row->grams_per_weight_unit, $factor, $actor);
                } elseif (isset($values['weight_unit']) && $values['weight_unit'] !== $row->weight_unit) {
                    // Reject editors opened with the previous unit label even if the scale is unchanged.
                    DB::table('products')->whereNotNull('weight')->update(['lock_version' => DB::raw('lock_version + 1')]);
                }
                if (isset($v['closed_messages'])) {
                    foreach (app(LocaleCatalog::class)->active() as $locale) {
                        DB::table('general_settings_translations')->updateOrInsert(
                            ['settings_id' => 1, 'locale_id' => $locale->id],
                            ['storefront_closed_message' => trim($v['closed_messages'][$locale->code])],
                        );
                    }
                }
                if (isset($v['price_hidden_messages'])) {
                    foreach (app(LocaleCatalog::class)->active() as $locale) {
                        $message = trim((string) ($v['price_hidden_messages'][$locale->code] ?? ''));
                        $key = ['settings_id' => 1, 'locale_id' => $locale->id];
                        if (DB::table('general_settings_translations')->where($key)->exists()) {
                            DB::table('general_settings_translations')->where($key)->update(['price_hidden_message' => $message]);
                        } else {
                            DB::table('general_settings_translations')->insert([...$key, 'storefront_closed_message' => __('settings.closed_default', [], $locale->code), 'price_hidden_message' => $message]);
                        }
                    }
                }
                foreach (self::TAX_FIELDS as $field) {
                    if (array_key_exists($field, $fields)) {
                        $values[$field] = match ($field) {
                            'tax_default_country', 'tax_default_region' => ($v[$field] ?? null) === null || $v[$field] === '' ? null : strtoupper((string) $v[$field]),
                            'tax_calculation_method' => $v[$field],
                            default => (bool) $v[$field],
                        };
                    }
                }
                foreach (self::ORDER_EMAIL_FIELDS as $field) {
                    if (array_key_exists($field, $fields)) {
                        $values[$field] = $v[$field] ?? null;
                    }
                }
                foreach (self::MAIL_DELIVERY_FIELDS as $field) {
                    if (array_key_exists($field, $fields)) {
                        $values[$field] = match ($field) {
                            'smtp_auth' => (bool) $v[$field],
                            'smtp_port' => $v[$field] === null ? null : (int) $v[$field],
                            'sendmail_path' => $v[$field] ?? '/usr/sbin/sendmail',
                            default => $v[$field] ?? null,
                        };
                    }
                }
                // Write-only password: a new value replaces it (encrypted), an empty one keeps it, "clear" removes it.
                if (is_string($v['smtp_password'] ?? null) && $v['smtp_password'] !== '') {
                    $values['smtp_password'] = Crypt::encryptString($v['smtp_password']);
                } elseif (filter_var($v['smtp_password_clear'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    $values['smtp_password'] = null;
                }
                if (array_key_exists('tax_default_country', $values) && $values['tax_default_country'] === null) {
                    // A default region cannot outlive its country.
                    $values['tax_default_region'] = null;
                }
                DB::table('general_settings')->where('id', 1)->update([...$values, 'lock_version' => DB::raw('lock_version + 1'), 'updated_by' => $actor, 'updated_at' => now()]);
                $this->cached = null;
            });
        });
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        $rules = [
            'storefront_open' => ['sometimes', 'required', 'boolean'],
            'storefront_noindex' => ['sometimes', 'required', 'boolean'],
            'brand_landing_pages_confirmed' => ['sometimes', 'boolean'],
            'date_format' => ['sometimes', 'required', Rule::in(GeneralOptions::DATE_FORMATS)],
            'product_card_style' => ['sometimes', 'required', Rule::in(GeneralOptions::PRODUCT_CARD_STYLES)],
            'time_format' => ['sometimes', 'required', Rule::in(GeneralOptions::TIME_FORMATS)],
            'timezone' => ['sometimes', 'required', Rule::in(GeneralOptions::timezones())],
            'calendar_date_format' => ['sometimes', 'required', Rule::in(GeneralOptions::DATE_FORMATS)],
            'calendar_week_start' => ['sometimes', 'required', 'integer', Rule::in([0, 1])],
            'site_max_width' => ['sometimes', 'required', 'integer', 'between:320,3840'],
            'reels_days' => ['sometimes', 'required', 'integer', 'between:1,3650'],
            'bestsellers_days' => ['sometimes', 'required', 'integer', 'between:0,3650'],
            'bestsellers_min_units' => ['sometimes', 'required', 'integer', 'between:1,1000000'],
            'bestsellers_min_orders' => ['sometimes', 'required', 'integer', 'between:1,1000000'],
            'bestsellers_excluded_categories' => ['sometimes', 'array', 'max:100'],
            'bestsellers_excluded_categories.*' => ['required', 'integer', 'distinct', Rule::exists('categories', 'id')->whereNull('system_key')],
            'new_products_days' => ['sometimes', 'required', 'integer', 'between:1,3650'],
            'comparison_max_products' => ['sometimes', 'required', 'integer', 'between:2,10'],
            'notification_duration' => ['sometimes', 'required', 'integer', 'between:0,300'],
            'phone_validation' => ['sometimes', 'required', Rule::in(['formatted', 'digits'])],
            'weight_unit' => ['sometimes', 'required', 'string', 'max:20', 'not_regex:/[\\p{C}]/u'],
            'grams_per_weight_unit' => ['sometimes', 'required', 'regex:/^[0-9]{1,7}(?:\\.[0-9]{1,6})?$/D', 'numeric', 'gt:0', 'max:1000000'],
            'low_stock_threshold' => ['sometimes', 'required', 'integer', 'between:0,2147483647'],
            'closed_messages' => ['sometimes', 'required', 'array:'.implode(',', app(LocaleCatalog::class)->active()->pluck('code')->all())],
            'price_hidden_messages' => ['sometimes', 'required', 'array:'.implode(',', app(LocaleCatalog::class)->active()->pluck('code')->all())],
            'version' => ['required', 'integer', 'min:1'],
            'out_of_stock_action' => ['required', Rule::in(ProductRules::OUT_OF_STOCK_ACTIONS)],
            'tax_calculation_method' => ['sometimes', 'required', Rule::in([CalculationSettings::UNIT_PRICE, CalculationSettings::SUBTOTAL])],
            'display_catalog_prices_with_tax' => ['sometimes', 'required', 'boolean'],
            'display_cart_prices_with_tax' => ['sometimes', 'required', 'boolean'],
            'tax_estimate_without_address' => ['sometimes', 'required', 'boolean'],
            'tax_default_country' => ['sometimes', 'nullable', 'string', Rule::in(app(Countries::class)->codes())],
            'tax_default_region' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Za-z0-9]{1,10}$/D'],
            'mail_method' => ['sometimes', 'required', Rule::in(self::MAIL_METHODS)],
            'smtp_host' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9.:\[\]-]+$/D'],
            'smtp_port' => ['sometimes', 'nullable', 'integer', 'between:1,65535'],
            'smtp_encryption' => ['sometimes', 'required', Rule::in(self::SMTP_ENCRYPTIONS)],
            'smtp_auth' => ['sometimes', 'required', 'boolean'],
            'smtp_username' => ['sometimes', 'nullable', 'string', 'max:255', 'not_regex:/[\x00-\x1f\x7f]/'],
            'smtp_password' => ['sometimes', 'nullable', 'string', 'max:255', 'not_regex:/[\x00-\x1f\x7f]/'],
            'smtp_password_clear' => ['sometimes', 'boolean'],
            'sendmail_path' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^\/[A-Za-z0-9._\/-]+$/D', 'not_regex:/\.\./'],
            // Header values: validated addresses, a name without line breaks.
            'order_email_from' => ['sometimes', 'nullable', 'string', 'max:254', 'email:rfc', 'not_regex:/[\s<>"]/'],
            'order_email_from_name' => ['sometimes', 'nullable', 'string', 'max:200', 'not_regex:/[\x00-\x1f\x7f]/'],
            'order_email_reply_to' => ['sometimes', 'nullable', 'string', 'max:254', 'email:rfc', 'not_regex:/[\s<>"]/'],
        ];
        foreach (GeneralOptions::DEFAULTS as $field => $default) {
            if (is_bool($default)) {
                $rules[$field] = ['sometimes', 'required', 'boolean'];
            }
        }
        foreach (app(LocaleCatalog::class)->active() as $locale) {
            $rules['price_hidden_messages.'.$locale->code] = ['nullable', 'string', 'max:500', 'not_regex:/[\\x00-\\x08\\x0b\\x0c\\x0e-\\x1f\\x7f]/'];
            $rules['closed_messages.'.$locale->code] = ['required_with:closed_messages', 'string', 'max:2000', 'not_regex:/[\\x00-\\x08\\x0b\\x0c\\x0e-\\x1f\\x7f]/'];
        }

        return $rules;
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return ['tax_default_country.in' => __('settings.tax_default_country_invalid'), 'tax_default_region.regex' => __('settings.tax_default_region_invalid'), 'tax_calculation_method.*' => __('settings.tax_method_invalid')];
    }
}
