<?php

namespace App\Search\Infrastructure;

use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Search\Application\Contract\SearchProvider;
use App\Search\Application\Contract\SearchUnavailable;
use App\Search\Application\Service\SearchProviderRegistry;
use App\Shared\Infrastructure\Modules\AddonRuntime;
use App\Shared\Infrastructure\Settings\FieldValues;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The active search provider and its stored settings. A provider is active while it is registered and its add-on is
 * enabled; with several, the first by identifier serves the store. Settings are declared by the provider; secrets are
 * decrypted here, handed only to the provider and never returned to the browser.
 */
final class SearchSettings
{
    public function __construct(private SearchProviderRegistry $registry, private AddonRuntime $addons, private FieldValues $fields) {}

    public function provider(): ?SearchProvider
    {
        foreach ($this->registry->ids() as $id) {
            if ($this->addons->isEnabled((string) $this->registry->owner($id))) {
                return $this->registry->get($id);
            }
        }

        return null;
    }

    public function row(SearchProvider $provider): object
    {
        DB::table('search_provider_settings')->insertOrIgnore(['provider' => $provider->id(), 'settings' => '{}', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('search_provider_settings')->where('provider', $provider->id())->first();
    }

    /** @return array<string, string> */
    public function values(SearchProvider $provider): array
    {
        $row = $this->row($provider);

        return $this->fields->values($provider->settings(), json_decode((string) $row->settings, true) ?: [], $this->fields->decrypt($row->secrets, 'search:'.$provider->id()));
    }

    /**
     * Saves the provider's settings (declared fields only, validated by the provider) and applies those that live in the
     * engine. Returns false when the engine could not take them yet (they are saved and applied with the next rebuild).
     *
     * @param  array<string, mixed>  $input  `settings` (values) and `clear` (secrets to remove)
     */
    public function save(string $actor, int $version, array $input): bool
    {
        $provider = $this->provider() ?? throw ValidationException::withMessages(['search' => __('search.no_provider')]);
        $values = app(AuditLogger::class)->operation('search_provider_settings', $actor, fn (): array => DB::transaction(function () use ($actor, $provider, $version, $input): array {
            app(AuditContext::class)->actor($actor);
            Gate::forUser(UserRecord::findOrFail($actor))->authorize('search.manage');
            $row = DB::table('search_provider_settings')->where('id', $this->row($provider)->id)->lockForUpdate()->first();
            if ((int) $row->lock_version !== $version) {
                throw ValidationException::withMessages(['version' => __('search.stale')]);
            }
            $result = $this->fields->submit($provider->settings(), $this->values($provider), is_array($input['settings'] ?? null) ? $input['settings'] : [], is_array($input['clear'] ?? null) ? $input['clear'] : []);
            $errors = $result['errors'];
            if ($errors === []) {
                foreach ($provider->validateSettings($result['values']) as $field => $message) {
                    $errors['settings.'.$field] = $message;
                }
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
            $split = $this->fields->split($provider->settings(), $result['values']);
            DB::table('search_provider_settings')->where('id', $row->id)->update([
                'settings' => json_encode((object) $split['plain'], JSON_THROW_ON_ERROR), 'secrets' => $this->fields->encrypt($split['secrets']),
                'lock_version' => $version + 1, 'updated_by' => $actor, 'updated_at' => now(),
            ]);

            return $result['values'];
        }));
        try {
            $provider->configure($values, $this->languages());

            return true;
        } catch (SearchUnavailable) {
            return false;
        }
    }

    /** Active languages for the index layout: the default first, then the others in their display order.
     * @return list<string> */
    public function languages(): array
    {
        $catalog = app(LocaleCatalog::class);
        $default = $catalog->defaultCode();

        return array_values(array_unique([$default, ...$catalog->active()->pluck('code')->all()]));
    }

    public function fieldValues(): FieldValues
    {
        return $this->fields;
    }
}
