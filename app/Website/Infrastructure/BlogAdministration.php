<?php

namespace App\Website\Infrastructure;

use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Media\Infrastructure\Persistence\MediaFileRecord;
use App\Media\Infrastructure\PrivateImageStorage;
use App\Shared\Application\Command\ImageUpload;
use App\Website\Domain\Service\PageRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Writes blogs (landing pages) and their posts. Both follow the page rules: status, availability window, Facebook
 * type and per-language name/content/SEO name; a post adds its blog, publication date, excerpt, cover and tags.
 * Every write authorizes `blog.manage`; edits are optimistic-locked.
 */
final class BlogAdministration
{
    /** kind => [table, translation table, owner column] */
    public const TABLES = ['blog' => ['blogs', 'blog_translations', 'blog_id'], 'post' => ['blog_posts', 'blog_post_translations', 'post_id']];

    public const TEXTS = ['seo_title' => 200, 'meta_description' => 500, 'meta_keywords' => 500];

    public const POST_TEXTS = ['excerpt' => 1000, 'cover_alt' => 200];

    public const MAX_TAGS = 20;

    public function __construct(private PageContentSanitizer $sanitizer) {}

    /** @param array<string, mixed> $input */
    public function save(string $kind, string $actor, ?string $id, ?int $version, array $input, ?ImageUpload $cover = null, ?ImageUpload $coverMobile = null): string
    {
        // Covers are stored first and discarded again if the save fails (the caller has authorized the uploads).
        $stored = [];
        try {
            foreach (['cover' => $cover, 'cover_mobile' => $coverMobile] as $field => $upload) {
                if ($kind !== 'post' || $upload === null) {
                    continue;
                }
                try {
                    $stored[$field] = app(PrivateImageStorage::class)->store($upload);
                } catch (ValidationException $failure) {
                    throw ValidationException::withMessages([$field => $failure->errors()['image'] ?? array_merge(...array_values($failure->errors()))]);
                }
            }

            return app(AuditLogger::class)->operation('blog', $actor, fn (): string => $this->unique(fn (): string => DB::transaction(function () use ($kind, $actor, $id, $version, $input, $stored): string {
                $this->authorize($actor);
                [$table] = self::TABLES[$kind];
                $row = $id === null ? null : $this->row($table, $id, (int) $version);
                $data = $this->validated($kind, $input);
                if ($kind === 'post') {
                    // The phone cover is optional; without one, phones show the main cover.
                    foreach (['cover' => 'cover_media_id', 'cover_mobile' => 'cover_mobile_media_id'] as $field => $column) {
                        $data['row'][$column] = isset($stored[$field]) ? MediaFileRecord::create($stored[$field])->id
                            : (filter_var($input['remove_'.$field] ?? false, FILTER_VALIDATE_BOOLEAN) ? null : $row?->{$column});
                    }
                }
                if ($id === null) {
                    $id = (string) DB::table($table)->insertGetId([...$data['row'], 'created_by' => $actor, 'updated_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
                } else {
                    DB::table($table)->where('id', $id)->update([...$data['row'], 'updated_by' => $actor, 'updated_at' => now(), 'lock_version' => (int) $version + 1]);
                }
                $this->writeTranslations($kind, (int) $id, $data['translations']);

                return $id;
            })));
        } catch (Throwable $e) {
            foreach ($stored as $file) {
                if (! DB::table('media_files')->where('storage_key', $file['storage_key'])->exists()) {
                    app(PrivateImageStorage::class)->discard($file['storage_key']);
                }
            }
            throw $e;
        }
    }

    /** A blog is refused while it still has posts. */
    public function delete(string $kind, string $actor, string $id, int $version): void
    {
        app(AuditLogger::class)->operation('blog', $actor, fn () => DB::transaction(function () use ($kind, $actor, $id, $version): void {
            $this->authorize($actor);
            $this->row(self::TABLES[$kind][0], $id, $version);
            if ($kind === 'blog') {
                $this->assertNoPosts([$id]);
            }
            DB::table(self::TABLES[$kind][0])->where('id', $id)->delete();
        }));
    }

    /**
     * @param  list<string>  $ids
     * @return int Rows changed.
     */
    public function bulk(string $kind, string $actor, array $ids, string $action): int
    {
        return app(AuditLogger::class)->operation('blog', $actor, fn (): int => DB::transaction(function () use ($kind, $actor, $ids, $action): int {
            $this->authorize($actor);
            $table = self::TABLES[$kind][0];
            if ($action === 'delete') {
                if ($kind === 'blog') {
                    $this->assertNoPosts($ids);
                }

                return DB::table($table)->whereIn('id', $ids)->delete();
            }
            if (! in_array($action, PageRules::STATUSES, true)) {
                throw ValidationException::withMessages(['action' => __('blogs.choice_invalid')]);
            }

            return DB::table($table)->whereIn('id', $ids)->where('status', '<>', $action)
                ->update(['status' => $action, 'updated_by' => $actor, 'updated_at' => now(), 'lock_version' => DB::raw('lock_version + 1')]);
        }));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{row: array<string, mixed>, translations: array<string, array<string, mixed>>}
     */
    private function validated(string $kind, array $input): array
    {
        $locales = app(LocaleCatalog::class);
        $codes = $locales->active()->pluck('code')->all();
        $default = $locales->defaultCode();
        $errors = [];
        $text = fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '';
        $instant = function (string $field) use ($input, $text, &$errors): ?CarbonImmutable {
            $value = $text($input[$field] ?? '');
            try {
                // ISO 8601 with the administrator's offset; bare values use the app timezone.
                return $value === '' ? null : CarbonImmutable::parse($value, config('app.timezone'))->utc();
            } catch (Throwable) {
                $errors[$field] = __('blogs.date_invalid');

                return null;
            }
        };

        $row = ['status' => $text($input['status'] ?? 'active'), 'facebook_type' => $text($input['facebook_type'] ?? ($kind === 'blog' ? 'blog' : 'article'))];
        if (! in_array($row['status'], PageRules::STATUSES, true)) {
            $errors['status'] = __('blogs.choice_invalid');
        }
        if (! PageRules::facebookType($row['facebook_type'])) {
            $errors['facebook_type'] = __('blogs.choice_invalid');
        }
        if ($kind === 'blog') {
            $position = filter_var($input['position'] ?? 0, FILTER_VALIDATE_INT);
            if ($position === false || $position < -99999 || $position > 99999) {
                $errors['position'] = __('blogs.number_invalid');
            }
            $row['position'] = (int) $position;
        } else {
            $blog = filter_var($input['blog_id'] ?? null, FILTER_VALIDATE_INT);
            if ($blog === false || $blog < 1 || ! DB::table('blogs')->where('id', $blog)->exists()) {
                $errors['blog_id'] = __('blogs.blog_required');
            }
            $row['blog_id'] = $blog === false ? null : $blog;
            // Empty keeps the post a draft.
            $row['published_at'] = $instant('published_at');
        }
        $row['available_from'] = $row['available_until'] = null;
        if (filter_var($input['available'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $row['available_from'] = $instant('available_from');
            $row['available_until'] = $instant('available_until');
            if ($row['available_from'] === null && $row['available_until'] === null && ! isset($errors['available_from']) && ! isset($errors['available_until'])) {
                $errors['available_until'] = __('blogs.period_required');
            } elseif ($row['available_from'] !== null && $row['available_until'] !== null && $row['available_until'] <= $row['available_from']) {
                $errors['available_until'] = __('blogs.period_order');
            }
        }

        $limits = $kind === 'post' ? [...self::TEXTS, ...self::POST_TEXTS] : self::TEXTS;
        $translations = [];
        foreach ((array) ($input['translations'] ?? []) as $code => $fields) {
            if (! in_array($code, $codes, true)) {
                $errors['translations'] = __('blogs.language_invalid');

                continue;
            }
            $fields = (array) $fields;
            $prefix = 'translations.'.$code.'.';
            $values = ['name' => $text($fields['name'] ?? ''), 'slug' => strtolower($text($fields['slug'] ?? ''))];
            foreach ($limits as $field => $max) {
                $values[$field] = $text($fields[$field] ?? '');
                if (mb_strlen($values[$field]) > $max || preg_match('/[\x00-\x09\x0b\x0c\x0e-\x1f\x7f]/', $values[$field]) === 1) {
                    $errors[$prefix.$field] = __('blogs.text_invalid', ['max' => $max]);
                }
            }
            $raw = is_scalar($fields['content'] ?? null) ? (string) $fields['content'] : '';
            if (strlen($raw) > 500000) {
                $errors[$prefix.'content'] = __('blogs.content_long');
                $raw = '';
            }
            $values['content'] = $this->sanitizer->sanitize($raw);
            $tags = [];
            if ($kind === 'post') {
                // Comma-separated in the editor; the same tag twice (any case) is kept once.
                foreach (explode(',', $text($fields['tags'] ?? '')) as $tag) {
                    $tag = trim((string) preg_replace('/\s+/u', ' ', $tag));
                    if ($tag !== '' && ! in_array(mb_strtolower($tag), array_map(mb_strtolower(...), $tags), true)) {
                        $tags[] = $tag;
                    }
                }
                foreach ($tags as $tag) {
                    if (mb_strlen($tag) > 60 || preg_match('/[\x00-\x1f\x7f]/', $tag) === 1) {
                        $errors[$prefix.'tags'] = __('blogs.tag_invalid');
                    }
                }
                if (count($tags) > self::MAX_TAGS) {
                    $errors[$prefix.'tags'] = __('blogs.tags_many', ['max' => self::MAX_TAGS]);
                }
            }
            if ($values['name'] === '') {
                // A language without a name has no row; other fields filled in would be lost.
                if (array_filter([...$values, 'tags' => implode('', $tags)], fn (string $v): bool => $v !== '') !== []) {
                    $errors[$prefix.'name'] = __('blogs.name_required_for_language');
                }

                continue;
            }
            if (mb_strlen($values['name']) > 200 || preg_match('/[\x00-\x1f\x7f]/', $values['name']) === 1) {
                $errors[$prefix.'name'] = __('blogs.name_invalid');
            }
            if ($values['slug'] !== '' && ! PageRules::slug($values['slug'])) {
                $errors[$prefix.'slug'] = __('blogs.slug_invalid');
            }
            $translations[$code] = [...$values, 'tags' => $tags];
        }
        if (! isset($translations[$default])) {
            $errors['translations.'.$default.'.name'] = __('blogs.name_required');
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['row' => $row, 'translations' => $translations];
    }

    /** @param array<string, array<string, mixed>> $translations */
    private function writeTranslations(string $kind, int $id, array $translations): void
    {
        [, $table, $owner] = self::TABLES[$kind];
        foreach (app(LocaleCatalog::class)->active() as $locale) {
            $values = $translations[$locale->code] ?? null;
            if ($values === null) {
                DB::table($table)->where($owner, $id)->where('locale_id', $locale->id)->delete();

                continue;
            }
            $tags = $values['tags'];
            unset($values['tags']);
            $values['slug'] = $values['slug'] !== '' ? $this->assertFreeSlug($table, $owner, $values['slug'], $locale->id, $id, $locale->code) : $this->generatedSlug($table, $owner, $values['name'], $locale->id, $id, $kind);
            DB::table($table)->updateOrInsert([$owner => $id, 'locale_id' => $locale->id], $values);
            if ($kind === 'post') {
                DB::table('blog_post_tags')->where('post_id', $id)->where('locale_id', $locale->id)->delete();
                DB::table('blog_post_tags')->insert(array_map(fn (string $tag): array => ['post_id' => $id, 'locale_id' => $locale->id, 'name' => $tag], $tags));
            }
        }
    }

    private function assertFreeSlug(string $table, string $owner, string $slug, int $locale, int $id, string $code): string
    {
        if ($this->taken($table, $owner, $slug, $locale, $id)) {
            throw ValidationException::withMessages(['translations.'.$code.'.slug' => __('blogs.slug_taken')]);
        }

        return $slug;
    }

    /** SEO name from the name (Greek is transliterated); a numeric suffix keeps it unique per language. */
    private function generatedSlug(string $table, string $owner, string $name, int $locale, int $id, string $kind): string
    {
        $base = trim(substr(Str::slug($name), 0, 190), '-') ?: $kind.'-'.$id;
        $slug = $base;
        for ($n = 2; $this->taken($table, $owner, $slug, $locale, $id); $n++) {
            $slug = $base.'-'.$n;
        }

        return $slug;
    }

    private function taken(string $table, string $owner, string $slug, int $locale, int $id): bool
    {
        return DB::table($table)->where('locale_id', $locale)->where('slug', $slug)->where($owner, '<>', $id)->exists();
    }

    /** @param list<string> $ids */
    private function assertNoPosts(array $ids): void
    {
        $posts = DB::table('blog_posts')->whereIn('blog_id', $ids)->count();
        if ($posts > 0) {
            throw ValidationException::withMessages(['blog' => trans_choice('blogs.has_posts', $posts, ['count' => $posts])]);
        }
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
                throw ValidationException::withMessages(['translations' => __('blogs.slug_taken')]);
            }
            throw $e;
        }
    }

    private function row(string $table, string $id, int $version): object
    {
        $row = DB::table($table)->where('id', $id)->lockForUpdate()->first() ?? throw ValidationException::withMessages(['version' => __('blogs.stale')]);
        if ((int) $row->lock_version !== $version) {
            throw ValidationException::withMessages(['version' => __('blogs.stale')]);
        }

        return $row;
    }

    private function authorize(string $actor): void
    {
        app(AuditContext::class)->actor($actor);
        Gate::forUser(UserRecord::findOrFail($actor))->authorize('blog.manage');
    }
}
