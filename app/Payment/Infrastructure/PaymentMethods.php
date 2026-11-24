<?php

namespace App\Payment\Infrastructure;

use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Media\Infrastructure\ImageAttachment;
use App\Payment\Application\Contract\PaymentMethod;
use App\Payment\Application\Contract\PaymentProviderUnavailable;
use App\Payment\Application\Contract\RedirectPaymentMethod;
use App\Pricing\Domain\Repository\CurrencyRepository;
use App\Shared\Domain\Service\Decimal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Administration of payment methods (`payment-methods.manage`): customer-facing names per language, display order,
 * enabled state, the optional status applied once an order is paid, and method-specific settings. Secrets are
 * write-only: an empty field keeps the stored secret; only an explicit "remove" clears it. Disabling a method affects
 * new orders only; orders keep their method and history. Writes serialize on one advisory lock.
 */
final class PaymentMethods
{
    private const LOCK = 2142901;

    public function __construct(private PaymentSettings $settings) {}

    /**
     * Saves texts and settings, re-verifies changed credentials, then applies the requested enabled state.
     *
     * @param  array<string, mixed>  $input
     * @return array{verification: string|null, activation: string|null} Verification outcome (`ok` or a reason code)
     *                                                                   when credentials were checked, and why enabling was refused
     */
    public function save(string $actor, string $code, int $version, array $input): array
    {
        $images = app(ImageAttachment::class);
        try {
            [$verify, $activate] = $this->write($actor, $code, $version, $input, $images);
        } catch (\Throwable $e) {
            $images->discardUncommitted();
            throw $e;
        }
        $outcome = $verify ? $this->verify($actor, $code) : null;
        $refused = null;
        if ($activate !== null) {
            $row = $this->settings->row($code);
            if ($row !== null && (bool) $row->is_active !== $activate) {
                try {
                    $this->setActive($actor, $code, (int) $row->lock_version, $activate);
                } catch (ValidationException $e) {
                    // The settings are saved; only the requested state change was refused.
                    $refused = (string) ($e->errors()['is_active'][0] ?? __('payments.not_found'));
                }
            }
        }

        return ['verification' => $outcome, 'activation' => $refused];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: bool, 1: ?bool} Whether to verify credentials, and the requested enabled state
     */
    private function write(string $actor, string $code, int $version, array $input, ImageAttachment $images): array
    {
        return $this->audited($actor, fn (): array => DB::transaction(function () use ($actor, $code, $version, $input, $images): array {
            $this->authorize($actor);
            DB::select('SELECT pg_advisory_xact_lock(?)', [self::LOCK]);
            $row = $this->locked($code, $version);
            $method = $this->settings->method($row) ?? throw ValidationException::withMessages(['method' => __('payments.issue_addon_disabled')]);
            $translations = $this->translations($input);
            $paidStatus = $this->paidStatus($input['paid_status_id'] ?? null);
            $restrictions = $this->restrictions($row, $input);
            $fee = $this->amount($row, $input, 'fee');
            $maxSubtotal = $this->amount($row, $input, 'max_subtotal');
            $values = $this->settingValues($method, $row, $input);
            $plain = [];
            $secrets = [];
            foreach ($method->settings() as $field) {
                if ($field->type === 'secret') {
                    $secrets[$field->key] = $values[$field->key];
                } else {
                    $plain[$field->key] = $values[$field->key];
                }
            }
            $fingerprint = $this->settings->fingerprint($method, $values);
            $keepVerification = $row->verified_fingerprint === $fingerprint;
            // An optional image (e.g. the provider's logo); a new upload replaces the current one.
            $image = $row->image_media_id;
            if (filter_var($input['remove_image'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $image = null;
            }
            if (($input['image'] ?? null) instanceof UploadedFile) {
                $image = $images->store($input['image']);
            }
            DB::table('payment_methods')->where('id', $row->id)->update([
                'settings' => json_encode((object) $plain, JSON_THROW_ON_ERROR), 'secrets' => $this->settings->encrypt($secrets), 'paid_status_id' => $paidStatus, 'image_media_id' => $image,
                'exclude_physical' => $restrictions['exclude_physical'], 'exclude_digital' => $restrictions['exclude_digital'], 'fee' => $fee, 'max_subtotal' => $maxSubtotal,
                'verified_fingerprint' => $keepVerification ? $row->verified_fingerprint : null, 'verified_at' => $keepVerification ? $row->verified_at : null,
                'lock_version' => $version + 1, 'updated_by' => $actor, 'updated_at' => now(),
            ]);
            if ($restrictions['categories'] !== null) {
                DB::table('payment_method_excluded_categories')->where('method_id', $row->id)->whereNotIn('category_id', $restrictions['categories'])->delete();
                DB::table('payment_method_excluded_categories')->insertOrIgnore(array_map(fn (int $c): array => ['method_id' => $row->id, 'category_id' => $c], $restrictions['categories']));
            }
            foreach ($translations as $localeId => $text) {
                if ($text === null) {
                    DB::table('payment_method_translations')->where('method_id', $row->id)->where('locale_id', $localeId)->delete();
                } else {
                    DB::table('payment_method_translations')->updateOrInsert(['method_id' => $row->id, 'locale_id' => $localeId], $text);
                }
            }
            $verify = $method instanceof RedirectPaymentMethod && ! $keepVerification && $this->settings->complete($method, $values);
            $activate = array_key_exists('is_active', $input) ? filter_var($input['is_active'], FILTER_VALIDATE_BOOLEAN) : null;

            return [$verify, $activate];
        }));
    }

    /**
     * The product types and categories the method cannot pay for. Fields left out keep their saved values; both types
     * cannot be excluded at once.
     *
     * @param  array<string, mixed>  $input
     * @return array{exclude_physical: bool, exclude_digital: bool, categories: list<int>|null}
     */
    private function restrictions(object $row, array $input): array
    {
        $data = validator($input, [
            'exclude_physical' => ['sometimes', 'boolean'], 'exclude_digital' => ['sometimes', 'boolean'],
            'excluded_category_ids' => ['sometimes', 'array', 'max:500'], 'excluded_category_ids.*' => ['integer', 'distinct', 'exists:categories,id,system_key,NULL'],
        ])->validate();
        $physical = array_key_exists('exclude_physical', $data) ? (bool) $data['exclude_physical'] : (bool) $row->exclude_physical;
        $digital = array_key_exists('exclude_digital', $data) ? (bool) $data['exclude_digital'] : (bool) $row->exclude_digital;
        if ($physical && $digital) {
            throw ValidationException::withMessages(['exclude_digital' => __('payments.exclude_both_types')]);
        }

        // Multipart forms drop an empty list, so the editor also says the categories were sent.
        $sent = array_key_exists('excluded_category_ids', $data) || filter_var($input['excluded_categories_submitted'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return ['exclude_physical' => $physical, 'exclude_digital' => $digital, 'categories' => $sent ? array_map(intval(...), $data['excluded_category_ids'] ?? []) : null];
    }

    /**
     * An optional amount in the primary currency at its precision; empty or zero means none, left out keeps the saved
     * one. `fee`: added to orders paid with the method (its VAT follows the order's products, OrderPricing).
     * `max_subtotal`: checkout does not offer the method above this order subtotal.
     *
     * @param  array<string, mixed>  $input
     */
    private function amount(object $row, array $input, string $key): ?string
    {
        if (! array_key_exists($key, $input)) {
            return $row->{$key} === null ? null : Decimal::trim((string) $row->{$key});
        }
        $decimals = app(CurrencyRepository::class)->currentPrimary()->decimals;
        $pattern = $decimals > 0 ? '/^\d{1,12}(\.\d{1,'.$decimals.'})?$/D' : '/^\d{1,12}$/D';
        $data = validator($input, [$key => ['nullable', 'regex:'.$pattern]], [$key.'.regex' => __('payments.fee_invalid', ['decimals' => $decimals])])->validate();
        $amount = trim((string) ($data[$key] ?? ''));

        return $amount === '' || Decimal::compare($amount, '0') === 0 ? null : Decimal::trim($amount);
    }

    /** Enables (only when completely configured and verified) or disables a method for new orders. */
    public function setActive(string $actor, string $code, int $version, bool $active): void
    {
        $this->audited($actor, fn () => DB::transaction(function () use ($actor, $code, $version, $active): void {
            $this->authorize($actor);
            DB::select('SELECT pg_advisory_xact_lock(?)', [self::LOCK]);
            $row = $this->locked($code, $version);
            if ($active && ($issues = $this->settings->issues($this->settings->method($row), $row)) !== []) {
                throw ValidationException::withMessages(['is_active' => __('payments.enable_blocked', ['issues' => implode(' ', $issues)])]);
            }
            DB::table('payment_methods')->where('id', $row->id)->update(['is_active' => $active, 'lock_version' => $version + 1, 'updated_by' => $actor, 'updated_at' => now()]);
        }));
    }

    /**
     * Stores the customer-facing order of the listed methods (drag and drop). Methods that are not listed (their add-on
     * is disabled) keep their slots, so re-enabling an add-on restores its place.
     *
     * @param  list<string>  $codes  Every listed method, in the new order
     */
    public function reorder(string $actor, array $codes): void
    {
        $this->audited($actor, fn () => DB::transaction(function () use ($actor, $codes): void {
            $this->authorize($actor);
            DB::select('SELECT pg_advisory_xact_lock(?)', [self::LOCK]);
            $all = DB::table('payment_methods')->orderBy('position')->orderBy('id')->pluck('code')->all();
            $listed = array_values(array_filter($all, function (string $code) use ($all): bool {
                $row = $this->settings->row($code);

                return $row !== null && $this->settings->method($row) !== null && $this->settings->ownerEnabled($row) && in_array($code, $all, true);
            }));
            if (count($codes) !== count(array_unique($codes)) || array_diff($codes, $listed) !== [] || array_diff($listed, $codes) !== []) {
                throw ValidationException::withMessages(['codes' => __('payments.stale')]);
            }
            $queue = $codes;
            foreach ($all as $n => $code) {
                $next = in_array($code, $listed, true) ? array_shift($queue) : $code;
                DB::table('payment_methods')->where('code', $next)->where('position', '<>', ($n + 1) * 10)
                    ->update(['position' => ($n + 1) * 10, 'lock_version' => DB::raw('lock_version + 1'), 'updated_by' => $actor, 'updated_at' => now()]);
            }
        }));
    }

    /**
     * Asks the provider to confirm the stored credentials and records the outcome. The remote call happens outside any
     * transaction; the result is recorded only if the credentials did not change meanwhile.
     *
     * @return string `ok` or a reason code
     */
    public function verify(string $actor, string $code): string
    {
        $this->authorize($actor);
        $row = $this->settings->row($code) ?? throw ValidationException::withMessages(['method' => __('payments.not_found')]);
        $method = $this->settings->method($row);
        if (! $method instanceof RedirectPaymentMethod) {
            throw ValidationException::withMessages(['method' => __('payments.not_found')]);
        }
        $values = $this->settings->values($method, $row);
        if (! $this->settings->complete($method, $values)) {
            return 'incomplete';
        }
        try {
            $error = $method->verify($values);
        } catch (PaymentProviderUnavailable $e) {
            $error = $e->reason;
        }
        $fingerprint = $this->settings->fingerprint($method, $values);
        $this->audited($actor, fn () => DB::transaction(function () use ($actor, $row, $error, $fingerprint): void {
            $current = DB::table('payment_methods')->where('id', $row->id)->lockForUpdate()->first();
            if ($current === null || $current->secrets !== $row->secrets || $current->settings !== $row->settings) {
                return;
            }
            DB::table('payment_methods')->where('id', $row->id)->update([
                'verified_fingerprint' => $error === null ? $fingerprint : null, 'verified_at' => $error === null ? now() : null,
                'lock_version' => DB::raw('lock_version + 1'), 'updated_by' => $actor, 'updated_at' => now(),
            ]);
        }));

        return $error ?? 'ok';
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<int, array{name: string, description: string}|null>
     */
    private function translations(array $input): array
    {
        $errors = [];
        $locales = app(LocaleCatalog::class);
        $translations = [];
        foreach ($locales->active() as $locale) {
            $fields = (array) (($input['translations'] ?? [])[$locale->code] ?? []);
            $name = is_scalar($fields['name'] ?? null) ? trim((string) $fields['name']) : '';
            $description = is_scalar($fields['description'] ?? null) ? trim((string) $fields['description']) : '';
            $key = 'translations.'.$locale->code.'.';
            if (mb_strlen($name) > 100 || preg_match('/[\x00-\x1f\x7f]/', $name) === 1) {
                $errors[$key.'name'] = __('payments.name_invalid');
            }
            if (mb_strlen($description) > 500) {
                $errors[$key.'description'] = __('payments.text_too_long', ['max' => 500]);
            }
            if ($name === '' && $locale->code === $locales->defaultCode()) {
                $errors[$key.'name'] = __('payments.name_required');
            }
            $translations[(int) $locale->id] = $name === '' ? null : ['name' => $name, 'description' => $description];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $translations;
    }

    private function paidStatus(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_scalar($value) || preg_match('/^[1-9]\d{0,18}$/D', (string) $value) !== 1 || ! DB::table('order_statuses')->where('id', $value)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['paid_status_id' => __('payments.paid_status_invalid')]);
        }

        return (int) $value;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    private function settingValues(PaymentMethod $method, object $row, array $input): array
    {
        $current = $this->settings->values($method, $row);
        $submitted = is_array($input['settings'] ?? null) ? $input['settings'] : [];
        $clear = is_array($input['clear'] ?? null) ? $input['clear'] : [];
        $values = [];
        $errors = [];
        foreach ($method->settings() as $field) {
            $raw = $submitted[$field->key] ?? null;
            $value = is_scalar($raw) ? trim((string) $raw) : '';
            $key = 'settings.'.$field->key;
            switch ($field->type) {
                case 'secret':
                    $value = filter_var($clear[$field->key] ?? false, FILTER_VALIDATE_BOOLEAN) ? '' : ($value === '' ? $current[$field->key] : $value);
                    break;
                case 'boolean':
                    $value = filter_var($raw ?? false, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
                    break;
                case 'select':
                    $value = array_key_exists($field->key, $submitted) ? $value : $current[$field->key];
                    if (! array_key_exists($value, $field->options)) {
                        $errors[$key] = __('payments.choice_invalid');
                    }
                    break;
                default:
                    $value = array_key_exists($field->key, $submitted) ? $value : $current[$field->key];
            }
            if (mb_strlen($value) > $field->maxLength || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
                $errors[$key] = __('payments.text_too_long', ['max' => $field->maxLength]);
            }
            $values[$field->key] = $value;
        }
        if ($errors === []) {
            foreach ($method->validate($values) as $field => $message) {
                $errors['settings.'.$field] = $message;
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $values;
    }

    private function locked(string $code, int $version): object
    {
        $row = DB::table('payment_methods')->where('code', $code)->lockForUpdate()->first() ?? throw ValidationException::withMessages(['version' => __('payments.stale')]);
        if ((int) $row->lock_version !== $version) {
            throw ValidationException::withMessages(['version' => __('payments.stale')]);
        }

        return $row;
    }

    private function authorize(string $actor): void
    {
        app(AuditContext::class)->actor($actor);
        Gate::forUser(UserRecord::findOrFail($actor))->authorize('payment-methods.manage');
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function audited(string $actor, callable $operation): mixed
    {
        return app(AuditLogger::class)->operation('payment_method', $actor, $operation);
    }
}
