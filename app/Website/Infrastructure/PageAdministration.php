<?php

namespace App\Website\Infrastructure;

use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Website\Domain\Service\PageRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Writes content pages: tree position, status, availability, per-language name/content/SEO and customer groups.
 * Every write authorizes `pages.manage`; edits are optimistic-locked; tree changes are serialised by an advisory lock.
 */
final class PageAdministration
{
    private const TEXTS = ['seo_title' => 200, 'meta_description' => 500, 'meta_keywords' => 500];

    public function __construct(private PageContentSanitizer $sanitizer, private PageFormWriter $forms) {}

    /** @param array<string, mixed> $input */
    public function save(string $actor, ?string $id, ?int $version, array $input): string
    {
        return app(AuditLogger::class)->operation('page', $actor, fn (): string => $this->unique(fn (): string => DB::transaction(function () use ($actor, $id, $version, $input): string {
            $this->authorize($actor);
            DB::select('SELECT pg_advisory_xact_lock(2142513)');
            DB::select('SELECT pg_advisory_xact_lock(2142601)');
            if ($id !== null) {
                $this->row($id, (int) $version);
            }
            $data = $this->validated($input, $id);
            Validator::make($input, ['faqs_submitted' => ['sometimes', 'boolean'], 'faq_ids' => ['sometimes', 'array'], 'faq_drafts' => ['sometimes', 'array']])->validate();
            if ($id === null) {
                $id = (string) DB::table('pages')->insertGetId([...$data['page'], 'created_by' => $actor, 'updated_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
            } else {
                DB::table('pages')->where('id', $id)->update([...$data['page'], 'updated_by' => $actor, 'updated_at' => now(), 'lock_version' => (int) $version + 1]);
            }
            $this->writeTranslations((int) $id, $data['translations']);
            DB::table('page_customer_groups')->where('page_id', $id)->delete();
            foreach ($data['customer_groups'] as $group) {
                DB::table('page_customer_groups')->insert(['page_id' => $id, 'group_id' => $group]);
            }
            if (array_key_exists('faq_ids', $input) || ($input['faqs_submitted'] ?? false) || ! empty($input['faq_drafts'])) {
                app(FaqLinks::class)->sync('page', $id, $input['faq_ids'] ?? [], $actor, $input['faq_drafts'] ?? []);
            }
            if ($data['form'] !== null) {
                $this->forms->write((int) $id, $data['form']);
            }

            return $id;
        })));
    }

    /** Refused while the page has sub-pages. */
    public function delete(string $actor, string $id, int $version): void
    {
        app(AuditLogger::class)->operation('page', $actor, fn () => DB::transaction(function () use ($actor, $id, $version): void {
            $this->authorize($actor);
            DB::select('SELECT pg_advisory_xact_lock(2142601)');
            $this->row($id, $version);
            $children = DB::table('pages')->where('parent_id', $id)->count();
            if ($children > 0) {
                throw ValidationException::withMessages(['page' => trans_choice('pages.has_children', $children, ['count' => $children])]);
            }
            DB::table('pages')->where('id', $id)->delete();
        }));
    }

    /**
     * @param  list<string>  $ids
     * @return int Pages changed.
     */
    public function bulk(string $actor, array $ids, string $action): int
    {
        return app(AuditLogger::class)->operation('page', $actor, fn (): int => DB::transaction(function () use ($actor, $ids, $action): int {
            $this->authorize($actor);
            DB::select('SELECT pg_advisory_xact_lock(2142601)');
            if ($action === 'delete') {
                // Children are only deleted together with their parent when they are selected too.
                $blocked = DB::table('pages')->whereIn('parent_id', $ids)->whereNotIn('id', $ids)->count();
                if ($blocked > 0) {
                    throw ValidationException::withMessages(['page' => trans_choice('pages.has_children', $blocked, ['count' => $blocked])]);
                }
                $deleted = 0;
                // Leaves first so the parent foreign key never blocks.
                while ($ids !== []) {
                    $leaves = DB::table('pages')->whereIn('id', $ids)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('pages as c')->whereColumn('c.parent_id', 'pages.id'))->pluck('id')->map(fn ($v): string => (string) $v)->all();
                    if ($leaves === []) {
                        break;
                    }
                    $deleted += DB::table('pages')->whereIn('id', $leaves)->delete();
                    $ids = array_values(array_diff($ids, $leaves));
                }

                return $deleted;
            }
            if (! in_array($action, PageRules::STATUSES, true)) {
                throw ValidationException::withMessages(['action' => __('pages.choice_invalid')]);
            }

            return DB::table('pages')->whereIn('id', $ids)->where('status', '<>', $action)
                ->update(['status' => $action, 'updated_by' => $actor, 'updated_at' => now(), 'lock_version' => DB::raw('lock_version + 1')]);
        }));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{page: array<string, mixed>, translations: array<string, array<string, string>>, customer_groups: list<int>, form: array<string, mixed>|null}
     */
    private function validated(array $input, ?string $id): array
    {
        $locales = app(LocaleCatalog::class);
        $codes = $locales->active()->pluck('code')->all();
        $default = $locales->defaultCode();
        $errors = [];
        $text = fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '';
        $bool = fn (mixed $value): bool => filter_var($value, FILTER_VALIDATE_BOOLEAN);

        $page = [];
        $parent = $text($input['parent_id'] ?? '');
        $page['parent_id'] = $parent === '' ? null : (int) $parent;
        if ($parent !== '') {
            if (! DB::table('pages')->where('id', (int) $parent)->exists()) {
                $errors['parent_id'] = __('pages.parent_missing');
            } elseif ($id !== null && ((int) $parent === (int) $id || in_array((int) $parent, $this->descendants((int) $id), true))) {
                $errors['parent_id'] = __('pages.parent_cycle');
            }
        }
        $position = filter_var($input['position'] ?? 0, FILTER_VALIDATE_INT);
        if ($position === false || $position < -99999 || $position > 99999) {
            $errors['position'] = __('pages.number_invalid');
        }
        $page['position'] = (int) $position;
        $page['status'] = $text($input['status'] ?? 'active');
        if (! in_array($page['status'], PageRules::STATUSES, true)) {
            $errors['status'] = __('pages.choice_invalid');
        }
        $page['facebook_type'] = $text($input['facebook_type'] ?? 'website');
        if (! PageRules::facebookType($page['facebook_type'])) {
            $errors['facebook_type'] = __('pages.choice_invalid');
        }
        $created = $text($input['created_on'] ?? '');
        $page['created_on'] = $created === '' ? now()->toDateString() : $created;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $page['created_on']) !== 1 || ! checkdate((int) substr($page['created_on'], 5, 2), (int) substr($page['created_on'], 8, 2), (int) substr($page['created_on'], 0, 4))) {
            $errors['created_on'] = __('pages.date_invalid');
        }
        $page['available_from'] = $page['available_until'] = null;
        if ($bool($input['available'] ?? false)) {
            foreach (['available_from', 'available_until'] as $field) {
                $value = $text($input[$field] ?? '');
                try {
                    // ISO 8601 with the administrator's offset; bare values use the app timezone.
                    $page[$field] = $value === '' ? null : CarbonImmutable::parse($value, config('app.timezone'))->utc();
                } catch (Throwable) {
                    $errors[$field] = __('pages.date_invalid');
                }
            }
            if ($page['available_from'] === null && $page['available_until'] === null) {
                $errors['available_until'] = __('pages.period_required');
            } elseif ($page['available_from'] !== null && $page['available_until'] !== null && $page['available_until'] <= $page['available_from']) {
                $errors['available_until'] = __('pages.period_order');
            }
        }

        $translations = [];
        foreach ((array) ($input['translations'] ?? []) as $code => $fields) {
            if (! in_array($code, $codes, true)) {
                $errors['translations'] = __('pages.language_invalid');

                continue;
            }
            $fields = (array) $fields;
            $row = ['name' => $text($fields['name'] ?? ''), 'slug' => strtolower($text($fields['slug'] ?? ''))];
            $prefix = 'translations.'.$code.'.';
            foreach (self::TEXTS as $field => $max) {
                $row[$field] = $text($fields[$field] ?? '');
                if (mb_strlen($row[$field]) > $max || preg_match('/[\x00-\x09\x0b\x0c\x0e-\x1f\x7f]/', $row[$field]) === 1) {
                    $errors[$prefix.$field] = __('pages.text_invalid', ['max' => $max]);
                }
            }
            $raw = is_scalar($fields['content'] ?? null) ? (string) $fields['content'] : '';
            if (strlen($raw) > 500000) {
                $errors[$prefix.'content'] = __('pages.content_long');
                $raw = '';
            }
            $row['content'] = $this->sanitizer->sanitize($raw);
            if ($row['name'] === '') {
                // A language without a name has no page row; other fields filled in would be lost.
                if ($row['content'] !== '' || $row['slug'] !== '' || $row['seo_title'] !== '' || $row['meta_description'] !== '' || $row['meta_keywords'] !== '') {
                    $errors[$prefix.'name'] = __('pages.name_required_for_language');
                }

                continue;
            }
            if (mb_strlen($row['name']) > 200 || preg_match('/[\x00-\x1f\x7f]/', $row['name']) === 1) {
                $errors[$prefix.'name'] = __('pages.name_invalid');
            }
            if ($row['slug'] !== '' && ! PageRules::slug($row['slug'])) {
                $errors[$prefix.'slug'] = __('pages.slug_invalid');
            }
            $translations[$code] = $row;
        }
        if (! isset($translations[$default])) {
            $errors['translations.'.$default.'.name'] = __('pages.name_required');
        }

        $groups = [];
        foreach ((array) ($input['customer_groups'] ?? []) as $group) {
            $value = filter_var($group, FILTER_VALIDATE_INT);
            if ($value === false || $value < 1) {
                $errors['customer_groups'] = __('pages.choice_invalid');
                break;
            }
            $groups[] = $value;
        }
        $groups = array_values(array_unique($groups));
        if ($groups !== [] && DB::table('customer_groups')->whereIn('id', $groups)->count() !== count($groups)) {
            $errors['customer_groups'] = __('pages.customer_groups_missing');
        }
        // The form tab is optional in the request; without it the stored form stays untouched.
        $form = is_array($input['form'] ?? null) ? $this->forms->validated($input['form'], $translations[$default]['content'] ?? '', $errors) : null;
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['page' => $page, 'translations' => $translations, 'customer_groups' => $groups, 'form' => $form];
    }

    /** @param array<string, array<string, string>> $translations */
    private function writeTranslations(int $id, array $translations): void
    {
        foreach (app(LocaleCatalog::class)->active() as $locale) {
            $row = $translations[$locale->code] ?? null;
            if ($row === null) {
                DB::table('page_translations')->where('page_id', $id)->where('locale_id', $locale->id)->delete();

                continue;
            }
            $row['slug'] = $row['slug'] !== '' ? $this->assertFreeSlug($row['slug'], $locale->id, $id, $locale->code) : $this->generatedSlug($row['name'], $locale->id, $id);
            DB::table('page_translations')->updateOrInsert(['page_id' => $id, 'locale_id' => $locale->id], $row);
        }
    }

    private function assertFreeSlug(string $slug, int $locale, int $id, string $code): string
    {
        if (DB::table('page_translations')->where('locale_id', $locale)->where('slug', $slug)->where('page_id', '<>', $id)->exists()) {
            throw ValidationException::withMessages(['translations.'.$code.'.slug' => __('pages.slug_taken')]);
        }

        return $slug;
    }

    /** SEO name from the page name (Greek is transliterated); a numeric suffix keeps it unique per language. */
    private function generatedSlug(string $name, int $locale, int $id): string
    {
        $base = trim(substr(Str::slug($name), 0, 190), '-') ?: 'page-'.$id;
        $slug = $base;
        for ($n = 2; DB::table('page_translations')->where('locale_id', $locale)->where('slug', $slug)->where('page_id', '<>', $id)->exists(); $n++) {
            $slug = $base.'-'.$n;
        }

        return $slug;
    }

    /** @return list<int> */
    private function descendants(int $id): array
    {
        $rows = DB::select('WITH RECURSIVE tree AS (SELECT id FROM pages WHERE parent_id = ? UNION ALL SELECT p.id FROM pages p JOIN tree ON p.parent_id = tree.id) SELECT id FROM tree', [$id]);

        return array_map(fn (object $row): int => (int) $row->id, $rows);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function unique(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                throw ValidationException::withMessages(['translations' => __('pages.slug_taken')]);
            }
            throw $e;
        }
    }

    private function row(string $id, int $version): object
    {
        $page = DB::table('pages')->where('id', $id)->lockForUpdate()->first() ?? throw ValidationException::withMessages(['version' => __('pages.stale')]);
        if ((int) $page->lock_version !== $version) {
            throw ValidationException::withMessages(['version' => __('pages.stale')]);
        }

        return $page;
    }

    private function authorize(string $actor): void
    {
        app(AuditContext::class)->actor($actor);
        Gate::forUser(UserRecord::findOrFail($actor))->authorize('pages.manage');
    }
}
