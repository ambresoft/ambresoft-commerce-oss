<?php

namespace App\Shared\Infrastructure\Modules;

use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Shared\Application\Contract\AddonSettings;
use App\Shared\Application\Contract\AddonSettingValues;
use App\Shared\Infrastructure\Settings\FieldValues;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Settings declared by enabled add-ons (the `settings` contribution), one row per add-on in `addon_settings`. Rows
 * outlive their add-on, so a reinstall keeps its configuration.
 */
final class AddonSettingsStore implements AddonSettingValues
{
    /** @var array<string, Closure(): AddonSettings> */
    private array $factories = [];

    /** @var array<string, AddonSettings> */
    private array $resolved = [];

    public function __construct(private FieldValues $fields, private AddonRuntime $addons) {}

    /** @param Closure(): AddonSettings $factory */
    public function add(string $addon, Closure $factory): void
    {
        $this->factories[$addon] = $factory;
    }

    /** The settings of an enabled add-on that declares them. */
    public function for(string $addon): ?AddonSettings
    {
        if (! isset($this->factories[$addon]) || ! $this->addons->isEnabled($addon)) {
            return null;
        }

        return $this->resolved[$addon] ??= ($this->factories[$addon])();
    }

    public function row(string $addon): object
    {
        // Add-ons read their settings while booting, so a request must not write to find them.
        $row = DB::table('addon_settings')->where('addon', $addon)->first();
        if ($row !== null) {
            return $row;
        }
        DB::table('addon_settings')->insertOrIgnore(['addon' => $addon, 'settings' => '{}', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('addon_settings')->where('addon', $addon)->first();
    }

    public function values(string $addon): array
    {
        $settings = $this->for($addon);
        if ($settings === null) {
            return [];
        }
        $row = $this->row($addon);

        return $this->fields->values($settings->settings(), json_decode((string) $row->settings, true) ?: [], $this->fields->decrypt($row->secrets, 'addon:'.$addon));
    }

    /** @param array<string, mixed> $input `settings` (values) and `clear` (secrets to remove) */
    public function save(string $actor, string $addon, int $version, array $input): void
    {
        $settings = $this->for($addon) ?? abort(404);
        app(AuditLogger::class)->operation('addon_settings', $actor, fn () => DB::transaction(function () use ($actor, $addon, $settings, $version, $input): void {
            app(AuditContext::class)->actor($actor);
            Gate::forUser(UserRecord::findOrFail($actor))->authorize('addons.manage');
            $row = DB::table('addon_settings')->where('addon', $this->row($addon)->addon)->lockForUpdate()->first();
            if ((int) $row->lock_version !== $version) {
                throw ValidationException::withMessages(['version' => __('addons.settings_stale')]);
            }
            $result = $this->fields->submit($settings->settings(), $this->values($addon), is_array($input['settings'] ?? null) ? $input['settings'] : [], is_array($input['clear'] ?? null) ? $input['clear'] : []);
            $errors = $result['errors'];
            if ($errors === []) {
                foreach ($settings->validateSettings($result['values']) as $field => $message) {
                    $errors['settings.'.$field] = $message;
                }
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
            $split = $this->fields->split($settings->settings(), $result['values']);
            DB::table('addon_settings')->where('addon', $addon)->update(['settings' => json_encode((object) $split['plain'], JSON_THROW_ON_ERROR),
                'secrets' => $this->fields->encrypt($split['secrets']), 'lock_version' => $version + 1, 'updated_by' => $actor, 'updated_at' => now()]);
        }));
    }

    public function fieldValues(): FieldValues
    {
        return $this->fields;
    }
}
