<?php

namespace App\Website\Infrastructure;

use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Shared\Application\Command\SettingField;
use App\Shared\Infrastructure\Settings\FieldValues;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Administration of social login providers (`social-login.manage`). The client secret is write-only: an empty field
 * keeps the stored value and only an explicit "remove" clears it. At most one active provider exists per type, so the
 * storefront has one unambiguous client; stale edits are refused by the version.
 */
final class SocialLoginAdministration
{
    private const LOCK = 2143301;

    public function __construct(private SocialLoginProviders $providers, private FieldValues $fields) {}

    /**
     * Creates (no ID) or updates a provider.
     *
     * @param  array<string, mixed>  $input
     */
    public function save(string $actor, ?string $id, ?int $version, array $input): string
    {
        return $this->audited($actor, fn (): string => DB::transaction(function () use ($actor, $id, $version, $input): string {
            $this->authorize($actor);
            DB::select('SELECT pg_advisory_xact_lock(?)', [self::LOCK]);
            $row = $id === null ? null : $this->locked($id, $version);
            $type = $row !== null ? $row->provider : (is_scalar($input['provider'] ?? null) ? (string) $input['provider'] : '');
            if (! $this->providers->has($type)) {
                throw ValidationException::withMessages(['provider' => __('social_login.provider_invalid')]);
            }
            $name = $this->name($input, $row?->id);
            $active = array_key_exists('is_active', $input) ? filter_var($input['is_active'], FILTER_VALIDATE_BOOLEAN) : ($row === null || (bool) $row->is_active);
            $fields = $this->providers->fields($type);
            $current = $row === null ? $this->fields->values($fields, [], []) : $this->fields->values($fields, $this->plain($row), $this->fields->decrypt($row->secrets, 'social_login:'.$row->id));
            $values = $this->values($type, $fields, $current, $input);
            if ($active && DB::table('social_login_providers')->where('provider', $type)->where('is_active', true)->when($row !== null, fn ($q) => $q->where('id', '<>', $row->id))->exists()) {
                throw ValidationException::withMessages(['is_active' => __('social_login.active_exists', ['provider' => $this->providers->name($type)])]);
            }
            $split = $this->fields->split($fields, $values);
            $data = ['name' => $name, 'is_active' => $active, 'settings' => json_encode((object) $split['plain'], JSON_THROW_ON_ERROR), 'secrets' => $this->fields->encrypt($split['secrets']), 'updated_by' => $actor, 'updated_at' => now()];
            if ($row === null) {
                return (string) DB::table('social_login_providers')->insertGetId([...$data, 'provider' => $type, 'created_by' => $actor, 'created_at' => now()]);
            }
            DB::table('social_login_providers')->where('id', $row->id)->update([...$data, 'lock_version' => (int) $version + 1]);

            return (string) $row->id;
        }));
    }

    public function setActive(string $actor, string $id, int $version, bool $active): void
    {
        $this->audited($actor, fn () => DB::transaction(function () use ($actor, $id, $version, $active): void {
            $this->authorize($actor);
            DB::select('SELECT pg_advisory_xact_lock(?)', [self::LOCK]);
            $row = $this->locked($id, $version);
            if ($active && DB::table('social_login_providers')->where('provider', $row->provider)->where('is_active', true)->where('id', '<>', $row->id)->exists()) {
                throw ValidationException::withMessages(['is_active' => __('social_login.active_exists', ['provider' => $this->providers->name($row->provider)])]);
            }
            DB::table('social_login_providers')->where('id', $row->id)->update(['is_active' => $active, 'lock_version' => $version + 1, 'updated_by' => $actor, 'updated_at' => now()]);
        }));
    }

    public function delete(string $actor, string $id, int $version): void
    {
        $this->audited($actor, fn () => DB::transaction(function () use ($actor, $id, $version): void {
            $this->authorize($actor);
            DB::select('SELECT pg_advisory_xact_lock(?)', [self::LOCK]);
            DB::table('social_login_providers')->where('id', $this->locked($id, $version)->id)->delete();
        }));
    }

    private function locked(string $id, ?int $version): object
    {
        $row = ctype_digit($id) ? DB::table('social_login_providers')->where('id', $id)->lockForUpdate()->first() : null;
        if ($row === null || (int) $row->lock_version !== $version) {
            throw ValidationException::withMessages(['version' => __('social_login.stale')]);
        }

        return $row;
    }

    /** @param array<string, mixed> $input */
    private function name(array $input, mixed $ignore): string
    {
        $name = is_scalar($input['name'] ?? null) ? trim((string) $input['name']) : '';
        if ($name === '' || mb_strlen($name) > 100 || preg_match('/[\x00-\x1f\x7f]/', $name) === 1) {
            throw ValidationException::withMessages(['name' => __('social_login.name_invalid')]);
        }
        if (DB::table('social_login_providers')->whereRaw('lower(name) = lower(?)', [$name])->when($ignore !== null, fn ($q) => $q->where('id', '<>', $ignore))->exists()) {
            throw ValidationException::withMessages(['name' => __('social_login.name_taken')]);
        }

        return $name;
    }

    /**
     * @param  list<SettingField>  $fields
     * @param  array<string, string>  $current
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    private function values(string $type, array $fields, array $current, array $input): array
    {
        $result = $this->fields->submit($fields, $current, is_array($input['settings'] ?? null) ? $input['settings'] : [], is_array($input['clear'] ?? null) ? $input['clear'] : []);
        $errors = $result['errors'];
        foreach ($fields as $field) {
            if ($field->required && ($result['values'][$field->key] ?? '') === '' && ! isset($errors['settings.'.$field->key])) {
                $errors['settings.'.$field->key] = __('social_login.field_required', ['field' => __($field->label)]);
            }
        }
        if ($errors === []) {
            foreach ($this->providers->validate($type, $result['values']) as $key => $message) {
                $errors['settings.'.$key] = $message;
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $result['values'];
    }

    /** @return array<string, mixed> */
    private function plain(object $row): array
    {
        $decoded = json_decode((string) $row->settings, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function authorize(string $actor): void
    {
        app(AuditContext::class)->actor($actor);
        Gate::forUser(UserRecord::findOrFail($actor))->authorize('social-login.manage');
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function audited(string $actor, callable $operation): mixed
    {
        return app(AuditLogger::class)->operation('social_login', $actor, $operation);
    }
}
