<?php

namespace App\Shipping\Infrastructure;

use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Shared\Application\Command\SettingField;
use App\Shipping\Application\Contract\ShippingProvider;
use App\Shipping\Application\Contract\ShippingProviderRejected;
use App\Shipping\Application\Contract\ShippingProviderUnavailable;
use App\Shipping\Application\Contract\VerifiesAccounts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Administration of carrier settings and accounts (`shipping-carriers.manage`). Secrets are write-only: an empty field
 * keeps the stored value, only an explicit "remove" clears it. Each account keeps its own credentials; changing a
 * credential clears the account's verification. Accounts referenced by methods or shipments cannot be deleted.
 */
final class ShippingCarriers
{
    private const LOCK = 2143101;

    public function __construct(private CarrierSettings $carriers) {}

    /** @param array<string, mixed> $input */
    public function saveSettings(string $actor, string $providerId, int $version, array $input): void
    {
        $this->audited($actor, fn () => DB::transaction(function () use ($actor, $providerId, $version, $input): void {
            $this->authorize($actor);
            DB::select('SELECT pg_advisory_xact_lock(?)', [self::LOCK]);
            $provider = $this->provider($providerId);
            $row = $this->carriers->settingsRow($providerId);
            $row = DB::table('shipping_carrier_settings')->where('id', $row->id)->lockForUpdate()->first();
            if ((int) $row->lock_version !== $version) {
                throw ValidationException::withMessages(['version' => __('shipping.stale')]);
            }
            $values = $this->submit($provider->settings(), $this->carriers->settings($provider), $input, fn (array $v): array => $provider->validateSettings($v));
            $split = $this->carriers->fieldValues()->split($provider->settings(), $values);
            DB::table('shipping_carrier_settings')->where('id', $row->id)->update([
                'settings' => json_encode((object) $split['plain'], JSON_THROW_ON_ERROR), 'secrets' => $this->carriers->fieldValues()->encrypt($split['secrets']),
                'lock_version' => $version + 1, 'updated_by' => $actor, 'updated_at' => now(),
            ]);
        }));
    }

    /**
     * Creates (no ID) or updates a named account of a provider.
     *
     * @param  array<string, mixed>  $input
     */
    public function saveAccount(string $actor, string $providerId, ?string $accountId, ?int $version, array $input): string
    {
        return $this->audited($actor, fn (): string => DB::transaction(function () use ($actor, $providerId, $accountId, $version, $input): string {
            $this->authorize($actor);
            DB::select('SELECT pg_advisory_xact_lock(?)', [self::LOCK]);
            $provider = $this->provider($providerId);
            $row = null;
            if ($accountId !== null) {
                $row = DB::table('shipping_carrier_accounts')->where('id', $accountId)->where('provider', $providerId)->lockForUpdate()->first();
                if ($row === null || (int) $row->lock_version !== $version) {
                    throw ValidationException::withMessages(['version' => __('shipping.stale')]);
                }
            }
            $name = is_scalar($input['name'] ?? null) ? trim((string) $input['name']) : '';
            if ($name === '' || mb_strlen($name) > 100 || preg_match('/[\x00-\x1f\x7f]/', $name) === 1) {
                throw ValidationException::withMessages(['name' => __('shipping.account_name_invalid')]);
            }
            $duplicate = DB::table('shipping_carrier_accounts')->where('provider', $providerId)->whereRaw('lower(name) = lower(?)', [$name])
                ->when($accountId !== null, fn ($q) => $q->where('id', '<>', $accountId))->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['name' => __('shipping.account_name_taken')]);
            }
            $current = $row === null ? $this->carriers->fieldValues()->values($provider->accountFields(), [], []) : $this->carriers->accountValues($provider, $row);
            $values = $this->submit($provider->accountFields(), $current, $input, fn (array $v): array => $provider->validateAccount($v));
            $split = $this->carriers->fieldValues()->split($provider->accountFields(), $values);
            $fingerprint = $this->carriers->fieldValues()->fingerprint('carrier:'.$providerId, $provider->accountFields(), $values);
            $keep = $row !== null && $row->verified_fingerprint === $fingerprint;
            $data = [
                'name' => $name, 'settings' => json_encode((object) $split['plain'], JSON_THROW_ON_ERROR), 'secrets' => $this->carriers->fieldValues()->encrypt($split['secrets']),
                'verified_fingerprint' => $keep ? $row->verified_fingerprint : null, 'verified_at' => $keep ? $row->verified_at : null, 'updated_by' => $actor, 'updated_at' => now(),
            ];
            if ($row === null) {
                return (string) DB::table('shipping_carrier_accounts')->insertGetId([...$data, 'provider' => $providerId,
                    'position' => (int) DB::table('shipping_carrier_accounts')->where('provider', $providerId)->max('position') + 10, 'created_by' => $actor, 'created_at' => now()]);
            }
            DB::table('shipping_carrier_accounts')->where('id', $row->id)->update([...$data, 'lock_version' => (int) $version + 1]);

            return (string) $row->id;
        }));
    }

    public function deleteAccount(string $actor, string $accountId, int $version): void
    {
        $this->audited($actor, fn () => DB::transaction(function () use ($accountId, $version, $actor): void {
            $this->authorize($actor);
            DB::select('SELECT pg_advisory_xact_lock(?)', [self::LOCK]);
            $row = DB::table('shipping_carrier_accounts')->where('id', $accountId)->lockForUpdate()->first();
            if ($row === null || (int) $row->lock_version !== $version) {
                throw ValidationException::withMessages(['version' => __('shipping.stale')]);
            }
            $methods = DB::table('shipping_methods')->where('account_id', $accountId)->count();
            $shipments = DB::table('order_shipments')->where('account_id', $accountId)->count() + DB::table('shipping_manifests')->where('account_id', $accountId)->count();
            if ($methods + $shipments > 0) {
                throw ValidationException::withMessages(['account' => __('shipping.account_in_use', ['methods' => $methods, 'shipments' => $shipments])]);
            }
            DB::table('shipping_carrier_accounts')->where('id', $accountId)->delete();
        }));
    }

    /**
     * Checks the stored credentials with the carrier (a harmless read) and records the outcome. The remote call runs
     * outside any transaction; the result is recorded only if the account did not change meanwhile.
     *
     * @return array{outcome: string, message: ?string} outcome `ok`, `incomplete`, `rejected` or `unavailable`; the carrier's message when it refused
     */
    public function verifyAccount(string $actor, string $accountId): array
    {
        $this->authorize($actor);
        $row = $this->carriers->accountRow($accountId) ?? throw ValidationException::withMessages(['account' => __('shipping.account_missing')]);
        $provider = $this->provider($row->provider);
        if (! $provider instanceof VerifiesAccounts) {
            throw ValidationException::withMessages(['account' => __('shipping.verify_unsupported')]);
        }
        if ($this->carriers->accountIssues($provider, $row) !== []) {
            return ['outcome' => 'incomplete', 'message' => null];
        }
        $values = $this->carriers->accountValues($provider, $row);
        try {
            $provider->verify($this->carriers->account($provider, $row), $this->carriers->settings($provider));
            [$outcome, $message] = ['ok', null];
        } catch (ShippingProviderRejected $e) {
            [$outcome, $message] = ['rejected', $e->getMessage()];
        } catch (ShippingProviderUnavailable) {
            [$outcome, $message] = ['unavailable', null];
        }
        if ($outcome !== 'unavailable') {
            $fingerprint = $this->carriers->fieldValues()->fingerprint('carrier:'.$provider->id(), $provider->accountFields(), $values);
            $this->audited($actor, fn () => DB::transaction(function () use ($row, $outcome, $fingerprint, $actor): void {
                $current = DB::table('shipping_carrier_accounts')->where('id', $row->id)->lockForUpdate()->first();
                if ($current === null || $current->secrets !== $row->secrets || $current->settings !== $row->settings) {
                    return;
                }
                DB::table('shipping_carrier_accounts')->where('id', $row->id)->update([
                    'verified_fingerprint' => $outcome === 'ok' ? $fingerprint : null, 'verified_at' => $outcome === 'ok' ? now() : null,
                    'lock_version' => DB::raw('lock_version + 1'), 'updated_by' => $actor, 'updated_at' => now(),
                ]);
            }));
        }

        return ['outcome' => $outcome, 'message' => $message];
    }

    /**
     * @param  list<SettingField>  $fields
     * @param  array<string, string>  $current
     * @param  array<string, mixed>  $input
     * @param  callable(array<string, string>): array<string, string>  $validate
     * @return array<string, string>
     */
    private function submit(array $fields, array $current, array $input, callable $validate): array
    {
        $result = $this->carriers->fieldValues()->submit($fields, $current, is_array($input['settings'] ?? null) ? $input['settings'] : [], is_array($input['clear'] ?? null) ? $input['clear'] : []);
        $errors = $result['errors'];
        if ($errors === []) {
            foreach ($validate($result['values']) as $field => $message) {
                $errors['settings.'.$field] = $message;
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $result['values'];
    }

    private function provider(string $id): ShippingProvider
    {
        return $this->carriers->provider($id) ?? throw ValidationException::withMessages(['provider' => __('shipping.provider_unavailable')]);
    }

    private function authorize(string $actor): void
    {
        app(AuditContext::class)->actor($actor);
        Gate::forUser(UserRecord::findOrFail($actor))->authorize('shipping-carriers.manage');
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function audited(string $actor, callable $operation): mixed
    {
        return app(AuditLogger::class)->operation('shipping_carrier', $actor, $operation);
    }
}
