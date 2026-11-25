<?php

namespace App\Settings\Infrastructure;

use App\Audit\Infrastructure\AuditContext;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Shared\Infrastructure\Geography\Countries;
use App\Shared\Infrastructure\Settings\FieldValues;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Checkout invoice providers. Credentials are encrypted and write-only, including the AADE username. */
final class CheckoutSettings
{
    public const CREDENTIALS = ['aade_username', 'aade_password', 'vatsense_key'];

    public function __construct(private FieldValues $fields) {}

    /** @return array{enabled: bool, version: int, greek_provider: string, international_provider: string, country_codes: list<string>, aade_configured: bool, vatsense_configured: bool} */
    public function all(): array
    {
        $row = DB::table('checkout_settings')->where('id', 1)->firstOrFail();
        $values = $this->credentials();

        return ['enabled' => (bool) $row->enabled, 'version' => (int) $row->lock_version, 'greek_provider' => $row->greek_provider, 'international_provider' => $row->international_provider,
            'country_codes' => DB::table('checkout_invoice_countries')->orderBy('country_code')->pluck('country_code')->all(),
            'aade_configured' => ($values['aade_username'] ?? '') !== '' && ($values['aade_password'] ?? '') !== '',
            'vatsense_configured' => ($values['vatsense_key'] ?? '') !== ''];
    }

    /** @return array<string, string> Server-side only. */
    public function credentials(): array
    {
        return $this->fields->decrypt(DB::table('checkout_settings')->where('id', 1)->value('secrets'), 'checkout_settings');
    }

    /** @param array<string, mixed> $input */
    public function save(string $actor, array $input): void
    {
        Gate::forUser(UserRecord::findOrFail($actor))->authorize('settings.manage');
        $rules = ['version' => ['required', 'integer', 'min:1'], 'enabled' => ['required', 'boolean'], 'greek_provider' => ['sometimes', Rule::in(['aade', 'vies', 'local'])], 'international_provider' => ['sometimes', Rule::in(['vies', 'vatsense'])]];
        $rules['country_codes'] = ['sometimes', 'array', 'list', 'max:250'];
        $rules['country_codes.*'] = ['required', 'string', 'distinct', Rule::in(app(Countries::class)->codes())];
        foreach (self::CREDENTIALS as $key) {
            $rules[$key] = ['nullable', 'string', 'max:500', 'not_regex:/[\x00-\x1f\x7f]/'];
            $rules['clear_'.$key] = ['sometimes', 'boolean'];
        }
        $valid = Validator::make($input, $rules)->validate();
        DB::transaction(function () use ($actor, $valid): void {
            app(AuditContext::class)->actor($actor);
            $row = DB::table('checkout_settings')->where('id', 1)->lockForUpdate()->firstOrFail();
            if ((int) $row->lock_version !== (int) $valid['version']) {
                throw ValidationException::withMessages(['version' => __('checkout.stale')]);
            }
            $values = $this->credentials();
            foreach (self::CREDENTIALS as $key) {
                if ($valid['clear_'.$key] ?? false) {
                    unset($values[$key]);
                }
                if (($valid[$key] ?? '') !== '' && $valid[$key] !== null) {
                    $values[$key] = $valid[$key];
                }
            }
            if (($values['aade_username'] ?? '') !== '' xor ($values['aade_password'] ?? '') !== '') {
                throw ValidationException::withMessages(['aade_password' => __('checkout.aade_pair')]);
            }
            $greek = $valid['greek_provider'] ?? $row->greek_provider;
            $international = $valid['international_provider'] ?? $row->international_provider;
            if ($valid['enabled'] && $greek === 'aade' && ($values['aade_username'] ?? '') === '') {
                throw ValidationException::withMessages(['aade_username' => __('checkout.configure_provider')]);
            }
            if ($valid['enabled'] && $international === 'vatsense' && ($values['vatsense_key'] ?? '') === '') {
                throw ValidationException::withMessages(['vatsense_key' => __('checkout.configure_provider')]);
            }
            if (array_key_exists('country_codes', $valid)) {
                DB::table('checkout_invoice_countries')->whereNotIn('country_code', $valid['country_codes'])->delete();
                if ($valid['country_codes'] !== []) {
                    DB::table('checkout_invoice_countries')->insertOrIgnore(array_map(fn (string $code): array => ['country_code' => $code], $valid['country_codes']));
                }
            }
            DB::table('checkout_settings')->where('id', 1)->update(['enabled' => $valid['enabled'], 'greek_provider' => $greek, 'international_provider' => $international, 'secrets' => $this->fields->encrypt($values), 'lock_version' => $row->lock_version + 1, 'updated_at' => now()]);
        });
    }
}
