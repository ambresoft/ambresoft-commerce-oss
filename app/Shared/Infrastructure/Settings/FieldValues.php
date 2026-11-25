<?php

namespace App\Shared\Infrastructure\Settings;

use App\Shared\Application\Command\SettingField;
use App\Shared\Domain\Service\Decimal;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Stores, validates and renders values of declared setting fields. Plain values are a JSON object; secrets are one
 * JSON object encrypted with the application key, decrypted only on the server. Secrets are write-only: an empty
 * submission keeps the stored value, only an explicit "remove" clears it, and editors only learn whether one is set.
 */
final class FieldValues
{
    /**
     * Current values with defaults applied; secrets decrypted.
     *
     * @param  list<SettingField>  $fields
     * @param  array<string, mixed>  $stored
     * @param  array<string, string>  $secrets
     * @return array<string, string>
     */
    public function values(array $fields, array $stored, array $secrets): array
    {
        $values = [];
        foreach ($fields as $field) {
            $values[$field->key] = $field->type === 'secret' ? (string) ($secrets[$field->key] ?? '') : (string) ($stored[$field->key] ?? $field->default);
        }

        return $values;
    }

    /**
     * Validates a submission against the fields and the current values.
     *
     * @param  list<SettingField>  $fields
     * @param  array<string, string>  $current
     * @param  array<string, mixed>  $submitted
     * @param  array<string, mixed>  $clear  Secret keys whose stored value should be removed
     * @return array{values: array<string, string>, errors: array<string, string>}
     */
    public function submit(array $fields, array $current, array $submitted, array $clear = [], string $prefix = 'settings.'): array
    {
        $values = [];
        $errors = [];
        foreach ($fields as $field) {
            $raw = $submitted[$field->key] ?? null;
            $given = array_key_exists($field->key, $submitted);
            $value = is_scalar($raw) ? trim((string) $raw) : '';
            $key = $prefix.$field->key;
            switch ($field->type) {
                case 'secret':
                    $value = filter_var($clear[$field->key] ?? false, FILTER_VALIDATE_BOOLEAN) ? '' : ($value === '' ? ($current[$field->key] ?? '') : $value);
                    break;
                case 'boolean':
                    $value = $given ? (filter_var($raw, FILTER_VALIDATE_BOOLEAN) ? '1' : '0') : ($current[$field->key] ?? $field->default);
                    break;
                default:
                    $value = $given ? $value : ($current[$field->key] ?? $field->default);
            }
            $error = $this->check($field, $value);
            if ($error !== null) {
                $errors[$key] = $error;
            }
            $values[$field->key] = $value;
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /** Type-level validation of one value; required-ness is a configuration issue, not a save error. */
    public function check(SettingField $field, string $value): ?string
    {
        if (mb_strlen($value) > $field->maxLength || preg_match($field->type === 'textarea' ? '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/' : '/[\x00-\x1f\x7f]/', $value) === 1) {
            return __('settings_fields.too_long', ['max' => $field->maxLength]);
        }
        if ($value === '') {
            return null;
        }

        return match ($field->type) {
            'select' => array_key_exists($value, $field->options) ? null : __('settings_fields.choice_invalid'),
            'integer' => preg_match('/^-?\d{1,9}$/D', $value) !== 1 || $this->outOfRange($field, $value) ? __('settings_fields.number_range', ['min' => $field->min ?? '−', 'max' => $field->max ?? '∞']) : null,
            'decimal' => preg_match('/^-?\d{1,12}(?:\.\d{1,6})?$/D', $value) !== 1 || $this->outOfRange($field, $value) ? __('settings_fields.number_range', ['min' => $field->min ?? '−', 'max' => $field->max ?? '∞']) : null,
            'date' => $this->isDate($value) ? null : __('settings_fields.date_invalid'),
            'order_status' => DB::table('order_statuses')->where('id', ctype_digit($value) ? $value : 0)->where('is_active', true)->exists() ? null : __('settings_fields.choice_invalid'),
            'payment_method' => DB::table('payment_methods')->where('code', $value)->exists() ? null : __('settings_fields.choice_invalid'),
            default => null,
        };
    }

    /**
     * @param  list<SettingField>  $fields
     * @param  array<string, string>  $values
     * @return array{plain: array<string, string>, secrets: array<string, string>}
     */
    public function split(array $fields, array $values): array
    {
        $plain = [];
        $secrets = [];
        foreach ($fields as $field) {
            if ($field->type === 'secret') {
                $secrets[$field->key] = $values[$field->key] ?? '';
            } else {
                $plain[$field->key] = $values[$field->key] ?? '';
            }
        }

        return ['plain' => $plain, 'secrets' => $secrets];
    }

    /** @param array<string, string> $secrets */
    public function encrypt(array $secrets): ?string
    {
        $secrets = array_filter($secrets, fn (string $v): bool => $v !== '');

        return $secrets === [] ? null : Crypt::encryptString(json_encode($secrets, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, string> */
    public function decrypt(?string $payload, string $context): array
    {
        if ($payload === null || $payload === '') {
            return [];
        }
        try {
            $decoded = json_decode(Crypt::decryptString($payload), true);

            return is_array($decoded) ? array_map(strval(...), $decoded) : [];
        } catch (DecryptException) {
            // A rotated application key makes stored secrets unreadable; the owner then reports missing settings.
            Log::warning('setting_secrets_unreadable', ['context' => $context]);

            return [];
        }
    }

    /**
     * @param  list<SettingField>  $fields
     * @param  array<string, string>  $values
     * @return list<string> Labels (translated) of required fields without a value
     */
    public function missing(array $fields, array $values): array
    {
        $missing = [];
        foreach ($fields as $field) {
            if ($field->required && trim($values[$field->key] ?? '') === '') {
                $missing[] = __($field->label);
            }
        }

        return $missing;
    }

    /**
     * HMAC of the credential fields, so a verification can be recorded without storing credentials twice.
     *
     * @param  list<SettingField>  $fields
     * @param  array<string, string>  $values
     */
    public function fingerprint(string $scope, array $fields, array $values): string
    {
        $credentials = [];
        foreach ($fields as $field) {
            if ($field->credential) {
                $credentials[$field->key] = $values[$field->key] ?? '';
            }
        }

        return hash_hmac('sha256', $scope.'|'.json_encode($credentials, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    /**
     * Editor representation: translated labels, options (core references resolved) and values; secrets only report
     * whether one is stored.
     *
     * @param  list<SettingField>  $fields
     * @param  array<string, string>  $values
     * @return list<array<string, mixed>>
     */
    public function editor(array $fields, array $values): array
    {
        return array_map(function (SettingField $field) use ($values): array {
            $options = match ($field->type) {
                'select' => array_map(fn (string $value, string $label): array => ['value' => $value, 'label' => __($label)], array_keys($field->options), $field->options),
                'order_status' => $this->statusOptions(),
                'payment_method' => $this->paymentOptions(),
                default => [],
            };

            return [
                'key' => $field->key, 'type' => $field->type, 'label' => __($field->label), 'hint' => $field->hint === '' ? '' : __($field->hint),
                'required' => $field->required, 'options' => $options, 'max_length' => $field->maxLength, 'min' => $field->min, 'max' => $field->max,
                'value' => $field->type === 'secret' ? '' : ($values[$field->key] ?? ''), 'set' => $field->type === 'secret' && ($values[$field->key] ?? '') !== '',
            ];
        }, $fields);
    }

    private function outOfRange(SettingField $field, string $value): bool
    {
        return ($field->min !== null && Decimal::compare($value, $field->min) < 0) || ($field->max !== null && Decimal::compare($value, $field->max) > 0);
    }

    /** @return list<array{value: string, label: string}> */
    private function statusOptions(): array
    {
        $locale = app()->getLocale();
        $default = (string) DB::table('locales')->where('is_default', true)->value('code');
        $names = DB::table('order_status_translations as t')->join('locales as l', 'l.id', '=', 't.locale_id')->whereIn('l.code', [$locale, $default])->get(['t.status_id', 'l.code', 't.name'])
            ->groupBy('status_id');

        return DB::table('order_statuses')->where('is_active', true)->orderBy('position')->orderBy('id')->get(['id', 'code'])
            ->map(function (object $s) use ($names, $locale, $default): array {
                $byLocale = collect($names[$s->id] ?? [])->pluck('name', 'code');

                return ['value' => (string) $s->id, 'label' => (string) ($byLocale[$locale] ?? $byLocale[$default] ?? $s->code)];
            })->all();
    }

    /** @return list<array{value: string, label: string}> */
    private function paymentOptions(): array
    {
        $locale = app()->getLocale();
        $default = (string) DB::table('locales')->where('is_default', true)->value('code');

        return DB::table('payment_methods')->orderBy('position')->orderBy('id')->get(['id', 'code'])->map(function (object $m) use ($locale, $default): array {
            $names = DB::table('payment_method_translations as t')->join('locales as l', 'l.id', '=', 't.locale_id')->where('t.method_id', $m->id)->pluck('t.name', 'l.code');

            return ['value' => $m->code, 'label' => (string) ($names[$locale] ?? $names[$default] ?? $m->code)];
        })->all();
    }

    private function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
