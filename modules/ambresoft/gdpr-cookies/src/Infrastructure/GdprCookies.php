<?php

namespace Ambresoft\GdprCookies\Infrastructure;

use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Storefront\Infrastructure\StorefrontConsent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * GDPR cookie consent (A149): banner texts, declared cookies, consented scripts and the proof-of-consent log.
 * Merchant text is per language; whatever is left empty falls back to the add-on's own wording.
 */
final class GdprCookies
{
    public const OPTIONAL = ['preferences', 'statistics', 'marketing'];

    private const T = 'cms-gdpr::gdpr.';

    /**
     * Admin data for the settings screen.
     *
     * @return array<string, mixed>
     */
    public function admin(bool $withScripts): array
    {
        $settings = $this->settings();
        $locales = $this->locales();
        $codes = $locales->pluck('code', 'id');
        $texts = DB::table('ambresoft_gdpr_texts')->get()->keyBy(fn (object $r): string => (string) ($codes[$r->locale_id] ?? ''));
        $categoryTexts = DB::table('ambresoft_gdpr_category_texts')->get();
        $purposes = DB::table('ambresoft_gdpr_cookie_texts')->get()->groupBy('cookie_id');

        return [
            'settings' => ['version' => (int) $settings->version, 'consent_months' => (int) $settings->consent_months, 'privacy_url' => (string) ($settings->privacy_url ?? ''),
                'reopen_button' => (bool) $settings->reopen_button, 'initial_categories' => $this->initialCategories($settings), 'lock_version' => (int) $settings->lock_version],
            'languages' => $locales->map(fn (object $l): array => ['code' => $l->code, 'name' => $l->native_name])->values()->all(),
            'texts' => $locales->mapWithKeys(fn (object $l): array => [$l->code => ['title' => (string) ($texts[$l->code]->title ?? ''), 'body' => (string) ($texts[$l->code]->body ?? ''), 'about' => (string) ($texts[$l->code]->about ?? '')]])->all(),
            'categories' => collect(StorefrontConsent::CATEGORIES)->mapWithKeys(fn (string $c): array => [$c => $locales->mapWithKeys(function (object $l) use ($c, $categoryTexts): array {
                $row = $categoryTexts->first(fn (object $r): bool => $r->category === $c && (int) $r->locale_id === (int) $l->id);

                return [$l->code => ['name' => (string) ($row->name ?? ''), 'description' => (string) ($row->description ?? '')]];
            })->all()])->all(),
            'cookies' => DB::table('ambresoft_gdpr_cookies')->orderBy('position')->orderBy('id')->get()->map(fn (object $c): array => ['id' => (int) $c->id, 'category' => $c->category,
                'name' => $c->name, 'provider' => $c->provider,
                'purposes' => $locales->mapWithKeys(fn (object $l): array => [$l->code => (string) (collect($purposes[$c->id] ?? [])->firstWhere('locale_id', $l->id)->purpose ?? '')])->all(),
                'expiries' => $locales->mapWithKeys(fn (object $l): array => [$l->code => (string) (collect($purposes[$c->id] ?? [])->firstWhere('locale_id', $l->id)->expiry ?? '')])->all()])->all(),
            'scripts' => $withScripts ? DB::table('ambresoft_gdpr_scripts')->orderBy('position')->orderBy('id')->get(['id', 'category', 'name', 'code', 'enabled'])
                ->map(fn (object $s): array => ['id' => (int) $s->id, 'category' => $s->category, 'name' => $s->name, 'code' => $s->code, 'enabled' => (bool) $s->enabled])->all() : null,
            'defaults' => $this->defaults(app()->getLocale()),
            'consents' => ['total' => DB::table('ambresoft_gdpr_consents')->count(),
                'recent' => DB::table('ambresoft_gdpr_consents')->where('created_at', '>=', now()->subDays(30))->selectRaw('action, count(*) as total')->groupBy('action')->pluck('total', 'action')->map(fn ($total): int => (int) $total)->all()],
        ];
    }

    /** @param array<string, mixed> $input */
    public function save(string $actor, array $input): void
    {
        $codes = $this->locales()->pluck('code')->all();
        $data = Validator::make($input, [
            'lock_version' => ['required', 'integer', 'min:1'],
            'consent_months' => ['required', 'integer', 'between:1,12'],
            'privacy_url' => ['nullable', 'string', 'max:2048', 'regex:~^(https?://|/)[^\s<>"]*$~'],
            'reopen_button' => ['required', 'boolean'],
            'renew' => ['sometimes', 'boolean'],
            'initial_categories' => ['sometimes', 'array:preferences,statistics,marketing'],
            'initial_categories.preferences' => ['required_with:initial_categories', 'boolean'],
            'initial_categories.statistics' => ['required_with:initial_categories', 'boolean'],
            'initial_categories.marketing' => ['required_with:initial_categories', 'boolean'],
            'texts' => ['array'], 'texts.*' => ['array'],
            'texts.*.title' => ['nullable', 'string', 'max:200'], 'texts.*.body' => ['nullable', 'string', 'max:5000'], 'texts.*.about' => ['nullable', 'string', 'max:5000'],
            'categories' => ['array'], 'categories.*' => ['array'],
            'categories.*.*.name' => ['nullable', 'string', 'max:100'], 'categories.*.*.description' => ['nullable', 'string', 'max:2000'],
            'cookies' => ['array', 'max:200'],
            'cookies.*.id' => ['nullable', 'integer'],
            'cookies.*.category' => ['required', Rule::in(StorefrontConsent::CATEGORIES)],
            'cookies.*.name' => ['required', 'string', 'max:120', 'not_regex:/^\s*$/'],
            'cookies.*.provider' => ['nullable', 'string', 'max:120'],
            'cookies.*.purposes' => ['array'], 'cookies.*.purposes.*' => ['nullable', 'string', 'max:500'],
            'cookies.*.expiries' => ['array'], 'cookies.*.expiries.*' => ['nullable', 'string', 'max:60'],
        ])->after(function ($v) use ($input, $codes): void {
            // Text is accepted only for active languages; inactive languages keep what they had.
            $cookieCodes = collect((array) ($input['cookies'] ?? []))->flatMap(fn ($c) => [...array_keys((array) ($c['purposes'] ?? [])), ...array_keys((array) ($c['expiries'] ?? []))])->all();
            foreach ([...array_keys((array) ($input['texts'] ?? [])), ...collect((array) ($input['categories'] ?? []))->flatMap(fn ($l) => array_keys((array) $l))->all(), ...$cookieCodes] as $code) {
                if (! in_array($code, $codes, true)) {
                    $v->errors()->add('texts', __(self::T.'inactive_language', ['code' => $code]));
                }
            }
            foreach (array_keys((array) ($input['categories'] ?? [])) as $category) {
                if (! in_array($category, StorefrontConsent::CATEGORIES, true)) {
                    $v->errors()->add('categories', __('validation.in', ['attribute' => 'category']));
                }
            }
        })->validate();
        $ids = $this->locales()->pluck('id', 'code');

        $this->audited($actor, function () use ($data, $ids): void {
            $initial = [];
            foreach ($data['initial_categories'] ?? [] as $category => $enabled) {
                $initial['initial_'.$category] = (bool) $enabled;
            }
            $updated = DB::table('ambresoft_gdpr_settings')->where('id', 1)->where('lock_version', $data['lock_version'])->update([
                'consent_months' => $data['consent_months'], 'privacy_url' => ($data['privacy_url'] ?? '') === '' ? null : $data['privacy_url'], 'reopen_button' => (bool) $data['reopen_button'],
                ...$initial,
                'version' => ! empty($data['renew']) ? DB::raw('version + 1') : DB::raw('version'), 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]);
            if ($updated === 0) {
                throw ValidationException::withMessages(['lock_version' => __(self::T.'stale')]);
            }
            foreach ($data['texts'] ?? [] as $code => $text) {
                DB::table('ambresoft_gdpr_texts')->upsert([['locale_id' => $ids[$code], 'title' => trim((string) ($text['title'] ?? '')), 'body' => trim((string) ($text['body'] ?? '')),
                    'about' => trim((string) ($text['about'] ?? ''))]], ['locale_id'], ['title', 'body', 'about']);
            }
            foreach ($data['categories'] ?? [] as $category => $languages) {
                foreach ($languages as $code => $text) {
                    DB::table('ambresoft_gdpr_category_texts')->upsert([['category' => $category, 'locale_id' => $ids[$code], 'name' => trim((string) ($text['name'] ?? '')),
                        'description' => trim((string) ($text['description'] ?? ''))]], ['category', 'locale_id'], ['name', 'description']);
                }
            }
            $kept = [];
            foreach (array_values($data['cookies'] ?? []) as $position => $cookie) {
                $values = ['category' => $cookie['category'], 'name' => trim($cookie['name']), 'provider' => trim((string) ($cookie['provider'] ?? '')), 'position' => $position];
                $id = isset($cookie['id']) && DB::table('ambresoft_gdpr_cookies')->where('id', $cookie['id'])->update($values) > 0 ? (int) $cookie['id'] : null;
                $id ??= (int) DB::table('ambresoft_gdpr_cookies')->insertGetId($values);
                $kept[] = $id;
                foreach (array_unique([...array_keys($cookie['purposes'] ?? []), ...array_keys($cookie['expiries'] ?? [])]) as $code) {
                    DB::table('ambresoft_gdpr_cookie_texts')->upsert([['cookie_id' => $id, 'locale_id' => $ids[$code], 'purpose' => trim((string) ($cookie['purposes'][$code] ?? '')),
                        'expiry' => trim((string) ($cookie['expiries'][$code] ?? ''))]], ['cookie_id', 'locale_id'], ['purpose', 'expiry']);
                }
            }
            DB::table('ambresoft_gdpr_cookies')->whereNotIn('id', $kept ?: [0])->delete();
        });
    }

    /**
     * Replaces the consented scripts. Any change asks every visitor again: new code is new processing.
     *
     * @param  array<string, mixed>  $input
     */
    public function saveScripts(string $actor, array $input): void
    {
        $data = Validator::make($input, [
            'scripts' => ['array', 'max:50'],
            'scripts.*.id' => ['nullable', 'integer'],
            'scripts.*.category' => ['required', Rule::in(self::OPTIONAL)],
            'scripts.*.name' => ['required', 'string', 'max:120', 'not_regex:/^\s*$/'],
            'scripts.*.code' => ['required', 'string', 'max:20000'],
            'scripts.*.enabled' => ['required', 'boolean'],
        ])->validate();

        $this->audited($actor, function () use ($data): void {
            $kept = [];
            $changed = false;
            foreach (array_values($data['scripts'] ?? []) as $position => $script) {
                $values = ['category' => $script['category'], 'name' => trim($script['name']), 'code' => $script['code'], 'enabled' => (bool) $script['enabled'], 'position' => $position];
                $current = isset($script['id']) ? DB::table('ambresoft_gdpr_scripts')->where('id', $script['id'])->first() : null;
                if ($current === null) {
                    $kept[] = (int) DB::table('ambresoft_gdpr_scripts')->insertGetId([...$values, 'created_at' => now(), 'updated_at' => now()]);
                    $changed = true;

                    continue;
                }
                $kept[] = (int) $current->id;
                if ([$current->category, $current->name, $current->code, (bool) $current->enabled, (int) $current->position] !== array_values($values)) {
                    DB::table('ambresoft_gdpr_scripts')->where('id', $current->id)->update([...$values, 'updated_at' => now()]);
                    $changed = true;
                }
            }
            $changed = DB::table('ambresoft_gdpr_scripts')->whereNotIn('id', $kept ?: [0])->delete() > 0 || $changed;
            if ($changed) {
                DB::table('ambresoft_gdpr_settings')->where('id', 1)->update(['version' => DB::raw('version + 1'), 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]);
            }
        });
    }

    /** @return array<string, mixed> The storefront banner for one language. */
    public function storefront(string $locale): array
    {
        $settings = $this->settings();
        $locales = $this->locales();
        $default = app(LocaleCatalog::class)->defaultCode();
        $order = array_values(array_unique([$locale, $default]));
        $ids = $locales->pluck('id', 'code');
        $pick = function (Collection $rows, string $field) use ($order, $ids): string {
            foreach ($order as $code) {
                $value = trim((string) ($rows->firstWhere('locale_id', $ids[$code] ?? 0)->{$field} ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }

            return '';
        };
        $defaults = $this->defaults($locale);
        $texts = DB::table('ambresoft_gdpr_texts')->get();
        $categoryTexts = DB::table('ambresoft_gdpr_category_texts')->get()->groupBy('category');
        $cookies = [];
        foreach (DB::table('ambresoft_gdpr_cookies')->orderBy('position')->orderBy('id')->get() as $cookie) {
            $cookies[$cookie->category][] = $cookie;
        }
        $purposes = DB::table('ambresoft_gdpr_cookie_texts')->get()->groupBy('cookie_id');

        return [
            'version' => (int) $settings->version, 'months' => (int) $settings->consent_months, 'privacyUrl' => $settings->privacy_url, 'reopen' => (bool) $settings->reopen_button,
            'initialCategories' => $this->initialCategories($settings),
            'recordUrl' => '/storefront/gdpr-cookies/consents',
            'texts' => [
                'title' => $pick($texts, 'title') ?: $defaults['title'], 'body' => $pick($texts, 'body') ?: $defaults['body'], 'about' => $pick($texts, 'about') ?: $defaults['about'],
                ...collect(['accept_all', 'reject_all', 'settings', 'save', 'consent_tab', 'details_tab', 'about_tab', 'always_active', 'cookie_name', 'cookie_provider', 'cookie_purpose',
                    'cookie_expiry', 'privacy_link', 'reopen', 'consent_id', 'consent_date', 'close', 'no_cookies', 'dialog'])->mapWithKeys(fn (string $k): array => [$k => __(self::T.'ui_'.$k, [], $locale)])->all(),
            ],
            'categories' => array_map(fn (string $c): array => [
                'key' => $c,
                'name' => $pick(collect($categoryTexts[$c] ?? []), 'name') ?: $defaults['categories'][$c]['name'],
                'description' => $pick(collect($categoryTexts[$c] ?? []), 'description') ?: $defaults['categories'][$c]['description'],
                'cookies' => array_map(fn (\stdClass $k): array => ['name' => $k->name, 'provider' => $k->provider,
                    'expiry' => $pick(collect($purposes[$k->id] ?? []), 'expiry'), 'purpose' => $pick(collect($purposes[$k->id] ?? []), 'purpose')], $cookies[$c] ?? []),
            ], StorefrontConsent::CATEGORIES),
            'scripts' => DB::table('ambresoft_gdpr_scripts')->where('enabled', true)->orderBy('position')->orderBy('id')->get(['id', 'category', 'code'])
                ->map(fn (object $s): array => ['id' => (int) $s->id, 'category' => $s->category, 'code' => $s->code])->all(),
        ];
    }

    /**
     * Records one decision as proof of consent. Only the current version is accepted.
     *
     * @param  array<string, mixed>  $input
     */
    public function record(array $input): void
    {
        $data = Validator::make($input, [
            'consent_id' => ['required', 'uuid'],
            'version' => ['required', 'integer', Rule::in([(int) $this->settings()->version])],
            'preferences' => ['required', 'boolean'], 'statistics' => ['required', 'boolean'], 'marketing' => ['required', 'boolean'],
            'action' => ['required', Rule::in(['accept_all', 'reject_all', 'custom'])],
        ])->validate();
        DB::table('ambresoft_gdpr_consents')->insert(['consent_id' => strtolower($data['consent_id']), 'version' => $data['version'], 'preferences' => (bool) $data['preferences'],
            'statistics' => (bool) $data['statistics'], 'marketing' => (bool) $data['marketing'], 'action' => $data['action'], 'created_at' => now()]);
    }

    /** @return list<array<string, mixed>> Every recorded decision of one consent ID, newest first */
    public function lookup(string $consentId): array
    {
        return DB::table('ambresoft_gdpr_consents')->where('consent_id', strtolower($consentId))->orderByDesc('id')->limit(50)
            ->get(['version', 'preferences', 'statistics', 'marketing', 'action', 'created_at'])->map(fn (object $r): array => (array) $r)->all();
    }

    public function renew(string $actor): void
    {
        $this->audited($actor, fn () => DB::table('ambresoft_gdpr_settings')->where('id', 1)->update(['version' => DB::raw('version + 1'), 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]));
    }

    /** Keeps proof for the consent period plus a year to answer later questions, then deletes it. */
    public function purge(): int
    {
        return DB::table('ambresoft_gdpr_consents')->where('created_at', '<', now()->subMonths((int) $this->settings()->consent_months + 12))->delete();
    }

    /** @return array{preferences: bool, statistics: bool, marketing: bool} */
    private function initialCategories(object $settings): array
    {
        return ['preferences' => (bool) $settings->initial_preferences, 'statistics' => (bool) $settings->initial_statistics, 'marketing' => (bool) $settings->initial_marketing];
    }

    private function settings(): object
    {
        return DB::table('ambresoft_gdpr_settings')->where('id', 1)->first();
    }

    /** @return Collection<int, \stdClass> */
    private function locales(): Collection
    {
        /** @var Collection<int, \stdClass> $locales */
        $locales = app(LocaleCatalog::class)->active()->map(fn ($l): \stdClass => (object) ['id' => (int) $l->id, 'code' => $l->code, 'native_name' => $l->native_name])->values();

        return $locales;
    }

    /** @return array{title: string, body: string, about: string, categories: array<string, array{name: string, description: string}>} */
    private function defaults(string $locale): array
    {
        return ['title' => __(self::T.'default_title', [], $locale), 'body' => __(self::T.'default_body', [], $locale), 'about' => __(self::T.'default_about', [], $locale),
            'categories' => collect(StorefrontConsent::CATEGORIES)->mapWithKeys(fn (string $c): array => [$c => ['name' => __(self::T.'category_'.$c, [], $locale),
                'description' => __(self::T.'category_'.$c.'_description', [], $locale)]])->all()];
    }

    private function audited(string $actor, callable $operation): void
    {
        app(AuditLogger::class)->operation('gdpr_cookies', $actor, fn () => DB::transaction(function () use ($actor, $operation): void {
            app(AuditContext::class)->actor($actor);
            $operation();
        }));
    }
}
