<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Localization\Application\Command\ManageLanguagesHandler;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Localization\Infrastructure\TranslationDictionary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class LanguageSettingsController
{
    public function index(Request $request, AdminNavigation $navigation, LocaleCatalog $locales): Response
    {
        Gate::authorize('languages.manage');

        return Inertia::render('Admin/Settings/Languages', [...$this->shell($request, $navigation), 'languages' => $locales->supported()->map(fn ($l): array => ['code' => $l->code, 'name' => $l->native_name, 'active' => $l->is_active, 'default' => $l->is_default])->all()]);
    }

    public function update(Request $request, ManageLanguagesHandler $handler): RedirectResponse
    {
        Gate::authorize('languages.manage');
        $data = $request->validate(['active' => ['required', 'array', 'min:1', 'max:6'], 'active.*' => ['required', 'string', 'distinct', Rule::in(array_keys(config('languages.pool')))], 'default' => ['required', 'string', Rule::in(array_keys(config('languages.pool')))]]);
        $handler->configure((string) $request->user()->getAuthIdentifier(), $data['active'], $data['default']);

        return back()->with('status', __('languages.saved'));
    }

    public function translations(Request $request, AdminNavigation $navigation, LocaleCatalog $locales, TranslationDictionary $dictionary): Response
    {
        Gate::authorize('languages.manage');
        $data = $request->validate(['locale' => ['nullable', Rule::in($locales->active()->pluck('code')->all())], 'q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $locale = $data['locale'] ?? app()->getLocale();
        $base = $dictionary->originals('en');
        $originals = $dictionary->originals($locale);
        $overrides = DB::table('ui_translation_overrides as o')->join('locales as l', 'l.id', '=', 'o.locale_id')->where('l.code', $locale)->get(['o.namespace', 'o.group', 'o.key', 'o.value'])->mapWithKeys(fn ($o): array => [($o->namespace === '*' ? '' : $o->namespace.'::').$o->group.'.'.$o->key => $o->value]);
        $rows = collect($base)->map(fn ($english, $key): array => ['key' => $key, 'original' => $originals[$key] ?? $english, 'value' => $overrides[$key] ?? $originals[$key] ?? $english, 'overridden' => $overrides->has($key)])->filter(fn ($r): bool => empty($data['q']) || mb_stripos($r['key'].' '.$r['value'], $data['q']) !== false)->values();
        $page = $data['page'] ?? 1;

        return Inertia::render('Admin/Settings/Translations', [...$this->shell($request, $navigation), 'languages' => $locales->active()->map(fn ($l): array => ['code' => $l->code, 'name' => $l->native_name])->all(), 'filters' => ['locale' => $locale, 'q' => $data['q'] ?? ''], 'rows' => $rows->slice(($page - 1) * 25, 25)->values()->all(), 'page' => $page, 'lastPage' => max(1, (int) ceil($rows->count() / 25)), 'total' => $rows->count()]);
    }

    public function override(Request $request, ManageLanguagesHandler $handler): RedirectResponse
    {
        Gate::authorize('languages.manage');
        $data = $request->validate(['locale' => ['required', 'string', 'max:3'], 'key' => ['required', 'string', 'max:400'], 'value' => ['nullable', 'string', 'max:20000'], 'restore' => ['sometimes', 'boolean']]);
        $handler->override((string) $request->user()->getAuthIdentifier(), $data['locale'], $data['key'], $request->boolean('restore') ? null : ($data['value'] ?? ''));

        return back()->with('status', __('languages.translation_saved'));
    }

    /** @return array<string, mixed> */
    private function shell(Request $request, AdminNavigation $navigation): array
    {
        return ['navigation' => $navigation->forUser($request->user()), 'breadcrumbs' => [['label' => __('navigation.dashboard'), 'href' => '/admin'], ['label' => __('languages.title'), 'href' => '/admin/settings/languages']]];
    }
}
