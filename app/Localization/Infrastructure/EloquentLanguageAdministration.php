<?php

namespace App\Localization\Infrastructure;

use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Application\Contract\LanguageAdministrationInterface;
use App\Localization\Domain\Event\LanguagesConfigured;
use App\Localization\Infrastructure\Persistence\LocaleRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final readonly class EloquentLanguageAdministration implements LanguageAdministrationInterface
{
    public function __construct(private TranslationDictionary $dictionary) {}

    private function authorize(string $actorId): void
    {
        app(AuditContext::class)->actor($actorId);
        $actor = UserRecord::findOrFail($actorId);
        Gate::forUser($actor)->authorize('access-admin');
        Gate::forUser($actor)->authorize('languages.manage');
    }

    public function configure(string $actorId, array $active, string $default): void
    {
        app(AuditLogger::class)->operation('language', $actorId, function () use ($actorId, $active, $default): void {
            $this->authorize($actorId);
            Validator::make(compact('active', 'default'), ['active' => ['required', 'array', 'min:1', 'max:6'], 'active.*' => ['required', 'distinct', Rule::in(array_keys(config('languages.pool')))], 'default' => ['required', Rule::in($active)]])->validate();
            DB::transaction(function () use ($actorId, $active, $default): void {
                // Same lock as catalog writes: activation cannot race final translation validation/import.
                DB::select('SELECT pg_advisory_xact_lock(2142513)');
                $this->authorize($actorId);
                LocaleRecord::where('is_default', true)->update(['is_default' => false]);
                LocaleRecord::query()->update(['is_active' => false]);
                LocaleRecord::whereIn('code', $active)->update(['is_active' => true]);
                LocaleRecord::where('code', $default)->update(['is_default' => true]);
                // Synchronous invariant participants must finish before committing new defaults.
                Event::dispatch(new LanguagesConfigured($actorId));
            });
            Log::info('system_languages_configured', ['actor_id' => $actorId, 'active' => $active, 'default' => $default]);

        });
    }

    public function override(string $actorId, string $locale, string $key, ?string $value): void
    {
        app(AuditLogger::class)->operation('language', $actorId, function () use ($actorId, $locale, $key, $value): void {
            $this->authorize($actorId);
            $base = $this->dictionary->originals('en');
            Validator::make(compact('locale', 'key', 'value'), ['locale' => ['required', Rule::in(array_keys(config('languages.pool')))], 'key' => ['required', Rule::in(array_keys($base))], 'value' => ['nullable', 'string', 'min:1', 'max:20000']])->validate();
            if ($value !== null) {
                if (trim($value) === '') {
                    throw ValidationException::withMessages(['value' => __('validation.required', ['attribute' => __('languages.translations')])]);
                }
                preg_match_all('/:[a-zA-Z_][a-zA-Z0-9_]*/', $base[$key], $expected);
                preg_match_all('/:[a-zA-Z_][a-zA-Z0-9_]*/', $value, $actual);
                $expected = array_unique($expected[0]);
                sort($expected);
                $actual = array_unique($actual[0]);
                sort($actual);
                if ($expected !== $actual) {
                    throw ValidationException::withMessages(['value' => __('languages.placeholders')]);
                }
            }
            [$namespace, $tail] = str_contains($key, '::') ? explode('::', $key, 2) : ['*', $key];
            [$group, $line] = explode('.', $tail, 2);
            $identity = ['locale_id' => LocaleRecord::where('code', $locale)->sole()->id, 'namespace' => $namespace, 'group' => $group, 'key' => $line];
            if ($value === null) {
                DB::table('ui_translation_overrides')->where($identity)->delete();
            } else {
                DB::table('ui_translation_overrides')->upsert([[...$identity, 'value' => $value, 'updated_by' => $actorId, 'created_at' => now(), 'updated_at' => now()]], array_keys($identity), ['value', 'updated_by', 'updated_at']);
            }
            app('translator')->setLoaded([]);
            Log::info('ui_translation_changed', ['actor_id' => $actorId, 'locale' => $locale, 'key' => $key, 'restored' => $value === null]);

        });
    }
}
