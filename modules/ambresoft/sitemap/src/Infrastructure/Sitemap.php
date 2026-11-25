<?php

namespace Ambresoft\Sitemap\Infrastructure;

use Ambresoft\Sitemap\Domain\Service\SitemapUrl;
use Ambresoft\Sitemap\Domain\Service\SitemapXml;
use Ambresoft\Sitemap\Infrastructure\Jobs\GenerateDraft;
use Ambresoft\Sitemap\Infrastructure\Jobs\RefreshSection;
use App\Shared\Application\Contract\AddonStatus;
use App\Storefront\Application\Contract\StorefrontUrls;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Draft writes and complete immutable publications share one database lock, never the public read path. */
final class Sitemap
{
    public const FREQUENCIES = ['hour', 'day', 'week', 'month', 'year', 'never'];

    private const STATE = 'ambresoft_sitemap_state';

    private const SECTIONS = 'ambresoft_sitemap_sections';

    private const VERSIONS = 'ambresoft_sitemap_versions';

    private const FILES = 'ambresoft_sitemap_files';

    private const FIELDS = ['section', 'source_id', 'canonical_url', 'url_override', 'inclusion_override', 'available', 'lastmod', 'warning'];

    public function __construct(private Providers $providers, private StorefrontUrls $source, private SitemapXml $xml) {}

    private function authorize(string $actor, string $ability): void
    {
        Gate::forUser(Auth::getProvider()->retrieveById($actor) ?? abort(403))->authorize('ambresoft.sitemap.'.$ability);
    }

    private function locked(Closure $action): mixed
    {
        return DB::transaction(function () use ($action): mixed {
            if (! DB::selectOne("SELECT pg_try_advisory_xact_lock(hashtextextended('ambresoft:sitemap', 0)) AS locked")->locked) {
                throw ValidationException::withMessages(['sitemap' => __('cms-sitemap::ui.busy')]);
            }

            return $action();
        });
    }

    private function state(?int $expected = null): object
    {
        $state = DB::table(self::STATE)->where('id', 1)->firstOrFail();
        if ($expected !== null && (int) $state->draft_version !== $expected) {
            throw ValidationException::withMessages(['version' => __('cms-sitemap::ui.stale')]);
        }

        return $state;
    }

    private function changed(): void
    {
        DB::table(self::STATE)->where('id', 1)->update(['draft_version' => DB::raw('draft_version + 1'), 'draft_pending' => true, 'updated_at' => now()]);
    }

    private function rows(?string $version = null): Builder
    {
        return $version === null ? DB::table('ambresoft_sitemap_draft_urls') : DB::table('ambresoft_sitemap_version_urls')->where('version_id', $version);
    }

    /** @return array<string,mixed> */
    public function overview(): array
    {
        $state = $this->state();
        $published = $state->published_version === null ? null : DB::table(self::VERSIONS)->where('id', $state->published_version)->first();
        $liveSettings = $published === null ? [] : json_decode($published->sections, true, flags: JSON_THROW_ON_ERROR);
        $sections = [];
        foreach (DB::table(self::SECTIONS)->get() as $row) {
            $sections[$row->key] = [
                'enabled' => (bool) $row->enabled, 'frequency' => $row->frequency, 'status' => $row->status, 'error' => $row->error,
                'eligible' => (int) $row->eligible_count,
                'draft' => $row->enabled ? $this->included($this->rows()->where('section', $row->key))->count() : 0,
                'published' => $published !== null && ($liveSettings[$row->key] ?? false) ? $this->included($this->rows($published->id)->where('section', $row->key))->count() : 0,
                'last_refresh_at' => $row->last_refresh_at, 'next_refresh_at' => $row->frequency === 'never' || ! $row->enabled ? null : $row->next_refresh_at,
            ];
        }

        return ['version' => (int) $state->draft_version, 'pending' => (bool) $state->draft_pending, 'sections' => $sections,
            'published' => $published === null ? null : ['id' => $published->id, 'at' => $published->published_at, 'count' => (int) $published->url_count],
            'publishedUrl' => $this->source->origin().'/sitemap.xml'];
    }

    /** @return array<string,mixed> */
    public function listing(string $section, int $page): array
    {
        $this->providers->get($section);
        $result = $this->rows()->where('section', $section)->orderBy('canonical_url')->paginate(50, ['*'], 'page', max(1, $page));

        return ['items' => $result->items(), 'page' => $result->currentPage(), 'last_page' => $result->lastPage(), 'total' => $result->total()];
    }

    private function included(Builder $q): Builder
    {
        return $q->where('available', true)->whereNull('warning')->where(fn ($q) => $q->whereNull('inclusion_override')->orWhere('inclusion_override', true));
    }

    /** @param array<string,mixed> $input */
    public function saveSettings(string $actor, array $input): void
    {
        $this->authorize($actor, 'manage');
        $data = Validator::make($input, ['version' => ['required', 'integer', 'min:1'], 'sections' => ['required', 'array:'.implode(',', Providers::SECTIONS), 'size:7'], 'sections.*' => ['array:enabled,frequency'], 'sections.*.enabled' => ['required', 'boolean'], 'sections.*.frequency' => ['required', Rule::in(self::FREQUENCIES)]])->validate();
        $this->locked(function () use ($data): void {
            $this->state((int) $data['version']);
            foreach ($data['sections'] as $key => $settings) {
                DB::table(self::SECTIONS)->where('key', $key)->update(['enabled' => (bool) $settings['enabled'], 'frequency' => $settings['frequency'], 'next_refresh_at' => $settings['enabled'] ? $this->next($settings['frequency']) : null, 'updated_at' => now()]);
            }
            $this->changed();
        });
    }

    /** Save only submitted rows; never replace a whole catalog from a paginated table.
     * @param array<string,mixed> $input */
    public function saveDraft(string $actor, array $input): void
    {
        $this->authorize($actor, 'manage');
        $data = Validator::make($input, ['version' => ['required', 'integer', 'min:1'], 'entries' => ['required', 'array', 'max:50'], 'entries.*' => ['array:id,include,url'], 'entries.*.id' => ['required', 'integer', 'distinct'], 'entries.*.include' => ['present', 'nullable', 'boolean'], 'entries.*.url' => ['present', 'nullable', 'string', 'max:2048']])->validate();
        $this->locked(function () use ($data): void {
            $this->state((int) $data['version']);
            foreach ($data['entries'] as $n => $entry) {
                $row = $this->rows()->where('id', $entry['id'])->first();
                if ($row === null) {
                    throw ValidationException::withMessages(['entries.'.$n.'.id' => __('cms-sitemap::ui.missing')]);
                }
                $url = trim((string) ($entry['url'] ?? ''));
                if ($url !== '') {
                    $found = iterator_to_array($this->providers->get($row->section)->urls($row->source_id));
                    if (! SitemapUrl::valid($url, $this->source->origin()) || count($found) !== 1 || $found[0]->url !== $url) {
                        throw ValidationException::withMessages(['entries.'.$n.'.url' => __('cms-sitemap::ui.invalid_url')]);
                    }
                }
                $updates = $url === '' ? [] : ['canonical_url' => $found[0]->url, 'lastmod' => $found[0]->lastmod, 'available' => true];
                $this->rows()->where('id', $entry['id'])->update([...$updates, 'url_override' => $url === '' ? null : $url, 'inclusion_override' => $entry['include'], 'warning' => $url !== '' || $row->warning === 'noncanonical' ? null : $row->warning, 'updated_at' => now()]);
            }
            $this->assertUnique($this->rows());
            $this->changed();
        });
    }

    /** @param list<string> $sections */
    public function requestRefresh(string $actor, int $version, array $sections): void
    {
        $this->authorize($actor, 'manage');
        $token = (string) Str::uuid();
        $expected = $this->locked(function () use ($version, $sections, $token): int {
            $this->state($version);
            foreach ($sections as $section) {
                $this->providers->get($section);
            }
            DB::table(self::SECTIONS)->whereIn('key', $sections)->update(['status' => 'queued', 'error' => null, 'generation_token' => $token, 'last_attempt_at' => now()]);
            $this->changed();

            return $version + 1;
        });
        GenerateDraft::dispatch($actor, $expected, $sections, $token)->afterCommit();
    }

    /** @param list<string> $sections */
    public function refreshDraft(string $actor, int $version, array $sections = Providers::SECTIONS): void
    {
        $this->authorize($actor, 'manage');
        $this->locked(function () use ($version, $sections): void {
            $this->state($version);
            $this->refreshRows(null, $sections);
            $this->changed();
        });
    }

    /** @param list<string> $sections */
    private function refreshRows(?string $version, array $sections): void
    {
        $origin = $this->source->origin();
        foreach ($sections as $section) {
            $provider = $this->providers->get($section);
            $this->rows($version)->where('section', $section)->update(['available' => false, 'warning' => 'unavailable']);
            $batch = [];
            $count = 0;
            foreach ($provider->urls() as $entry) {
                $valid = SitemapUrl::valid($entry->url, $origin);
                $batch[] = [...($version === null ? ['created_at' => now(), 'updated_at' => now()] : ['version_id' => $version]), 'section' => $section, 'source_id' => $entry->sourceId, 'canonical_url' => $entry->url,
                    'url_override' => null, 'inclusion_override' => null, 'lastmod' => $entry->lastmod, 'available' => $valid, 'warning' => $valid ? null : 'invalid'];
                $count += (int) $valid;
                if (count($batch) === 250) {
                    $this->upsert($version, $batch);
                    $batch = [];
                }
            }
            $this->upsert($version, $batch);
            // A retained override may have become obsolete after the content's own URL changed.
            $this->rows($version)->where('section', $section)->where('available', true)->whereNotNull('url_override')->whereColumn('url_override', '<>', 'canonical_url')->update(['warning' => 'noncanonical']);
            $row = DB::table(self::SECTIONS)->where('key', $section)->firstOrFail();
            DB::table(self::SECTIONS)->where('key', $section)->update(['eligible_count' => $count, 'status' => 'completed', 'error' => null, 'generation_token' => null, 'last_refresh_at' => now(), 'next_refresh_at' => $this->next($row->frequency), 'updated_at' => now()]);
        }
        $duplicates = $this->rows($version)->where('available', true)->select('canonical_url')->groupBy('canonical_url')->havingRaw('count(*) > 1')->pluck('canonical_url');
        foreach ($duplicates as $url) {
            $rows = $this->rows($version)->where('canonical_url', $url)->where('available', true)->orderBy('section')->orderBy('source_id')->get(['section', 'source_id']);
            foreach ($rows->skip(1) as $row) {
                $this->rows($version)->where('section', $row->section)->where('source_id', $row->source_id)->update(['available' => false, 'warning' => 'duplicate']);
            }
        }
    }

    /** @param list<array<string,mixed>> $batch */
    private function upsert(?string $version, array $batch): void
    {
        if ($batch !== []) {
            $this->rows($version)->upsert($batch, $version === null ? ['section', 'source_id'] : ['version_id', 'section', 'source_id'], ['canonical_url', 'lastmod', 'available', 'warning', ...($version === null ? ['updated_at'] : [])]);
        }
    }

    private function assertUnique(Builder $rows): void
    {
        $duplicate = $this->included($rows)->selectRaw('coalesce(url_override, canonical_url) as url')->groupByRaw('coalesce(url_override, canonical_url)')->havingRaw('count(*) > 1')->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['sitemap' => __('cms-sitemap::ui.duplicate')]);
        }
    }

    public function publish(string $actor, int $version): string
    {
        $this->authorize($actor, 'publish');

        return $this->locked(function () use ($version, $actor): string {
            $this->state($version);
            if (DB::table(self::SECTIONS)->where('enabled', true)->whereNull('last_refresh_at')->exists()) {
                throw ValidationException::withMessages(['sitemap' => __('cms-sitemap::ui.generate_first')]);
            }
            if (DB::table(self::SECTIONS)->where('status', 'queued')->exists()) {
                throw ValidationException::withMessages(['sitemap' => __('cms-sitemap::ui.busy')]);
            }
            $settings = DB::table(self::SECTIONS)->pluck('enabled', 'key')->map(fn ($v): bool => (bool) $v)->all();
            $id = $this->candidate($settings);
            $this->copyRows(null, $id);
            // Recheck current visibility/canonical addresses at publication, without silently discovering new rows.
            foreach (array_keys(array_filter($settings)) as $section) {
                $current = [];
                foreach ($this->providers->get($section)->urls() as $url) {
                    $current[$url->sourceId] = $url;
                }
                foreach ($this->rows($id)->where('section', $section)->where('available', true)->where(fn ($q) => $q->whereNull('inclusion_override')->orWhere('inclusion_override', true))->cursor() as $entry) {
                    $found = $current[$entry->source_id] ?? null;
                    if ($found === null || $found->url !== ($entry->url_override ?? $entry->canonical_url) || $entry->warning !== null) {
                        throw ValidationException::withMessages(['sitemap' => __('cms-sitemap::ui.refresh_required')]);
                    }
                    if (($entry->lastmod === null) !== ($found->lastmod === null) || ($entry->lastmod !== null && $found->lastmod !== null && ! CarbonImmutable::parse($entry->lastmod)->equalTo(CarbonImmutable::parse($found->lastmod)))) {
                        $this->rows($id)->where('section', $section)->where('source_id', $entry->source_id)->update(['lastmod' => $found->lastmod]);
                    }
                }
            }
            $this->finish($id, $settings);
            DB::table(self::STATE)->where('id', 1)->update(['published_version' => $id, 'draft_pending' => false, 'draft_version' => DB::raw('draft_version + 1'), 'updated_at' => now()]);
            Log::info('sitemap_published', ['actor' => $actor, 'version' => $id]);

            return $id;
        });
    }

    /** @param array<string,bool> $settings */
    private function candidate(array $settings): string
    {
        $id = (string) Str::uuid();
        DB::table(self::VERSIONS)->insert(['id' => $id, 'origin' => $this->source->origin(), 'sections' => json_encode($settings, JSON_THROW_ON_ERROR), 'url_count' => 0, 'published_at' => now()]);

        return $id;
    }

    private function copyRows(?string $from, string $to): void
    {
        DB::table('ambresoft_sitemap_version_urls')->insertUsing(['version_id', ...self::FIELDS], $this->rows($from)->selectRaw('? as version_id', [$to])->addSelect(self::FIELDS));
    }

    /** @param array<string,bool> $settings */
    private function finish(string $id, array $settings): void
    {
        $rows = $this->included($this->rows($id))->whereIn('section', array_keys(array_filter($settings)));
        $this->assertUnique(clone $rows);
        $urls = function () use ($rows): iterable {
            foreach ($rows->orderBy('section')->orderBy('source_id')->cursor() as $row) {
                $url = $row->url_override ?? $row->canonical_url;
                if (! SitemapUrl::valid($url, $this->source->origin())) {
                    throw ValidationException::withMessages(['sitemap' => __('cms-sitemap::ui.invalid_url')]);
                }
                yield ['url' => $url, 'lastmod' => $row->lastmod === null ? null : CarbonImmutable::parse($row->lastmod)->utc()->toIso8601String()];
            }
        };
        $files = [];
        $total = 0;
        foreach ($this->xml->parts($urls()) as $part) {
            $name = 'sitemap-'.$id.'-'.(count($files) + 1).'.xml';
            DB::table(self::FILES)->insert(['version_id' => $id, 'name' => $name, 'xml' => $part['xml'], 'url_count' => $part['count'], 'bytes' => strlen($part['xml'])]);
            $files[] = $name;
            $total += $part['count'];
        }
        if (count($files) === 1) {
            DB::table(self::FILES)->where('version_id', $id)->update(['name' => 'sitemap.xml']);
        } else {
            $xml = $this->xml->index(array_map(fn ($name): string => $this->source->origin().'/'.$name, $files), now()->utc()->toIso8601String());
            DB::table(self::FILES)->insert(['version_id' => $id, 'name' => 'sitemap.xml', 'xml' => $xml, 'url_count' => 0, 'bytes' => strlen($xml)]);
        }
        DB::table(self::VERSIONS)->where('id', $id)->update(['url_count' => $total]);
    }

    public function dispatchDue(): void
    {
        if (! app(AddonStatus::class)->isEnabled('ambresoft/sitemap')) {
            return;
        }
        foreach (DB::table(self::SECTIONS)->where('enabled', true)->where('frequency', '<>', 'never')->where(fn ($q) => $q->whereNull('next_refresh_at')->orWhere('next_refresh_at', '<=', now()))->where(fn ($q) => $q->where('status', '<>', 'queued')->orWhere('last_attempt_at', '<', now()->subMinutes(10)))->pluck('key') as $section) {
            $token = (string) Str::uuid();
            $queued = $this->locked(function () use ($section, $token): bool {
                $q = DB::table(self::SECTIONS)->where('key', $section)->where('frequency', '<>', 'never')->where('enabled', true)->where(fn ($q) => $q->whereNull('next_refresh_at')->orWhere('next_refresh_at', '<=', now()))->where(fn ($q) => $q->where('status', '<>', 'queued')->orWhere('last_attempt_at', '<', now()->subMinutes(10)));

                return $q->update(['status' => 'queued', 'generation_token' => $token, 'last_attempt_at' => now(), 'error' => null]) === 1;
            });
            if ($queued) {
                RefreshSection::dispatch($section, $token)->afterCommit();
            }
        }
    }

    public function refreshPublished(string $section, string $token): void
    {
        if (! app(AddonStatus::class)->isEnabled('ambresoft/sitemap')) {
            return;
        }
        $this->locked(function () use ($section, $token): void {
            $config = DB::table(self::SECTIONS)->where('key', $section)->where('generation_token', $token)->first();
            if ($config === null) {
                return;
            }
            if (! $config->enabled || $config->frequency === 'never') {
                DB::table(self::SECTIONS)->where('key', $section)->where('generation_token', $token)->update(['status' => 'idle', 'generation_token' => null, 'next_refresh_at' => null]);

                return;
            }
            $state = $this->state();
            if ($state->published_version === null) {
                DB::table(self::SECTIONS)->where('key', $section)->update(['status' => 'idle', 'generation_token' => null, 'error' => __('cms-sitemap::ui.publish_first'), 'next_refresh_at' => $this->next($config->frequency)]);

                return;
            }
            $previous = DB::table(self::VERSIONS)->where('id', $state->published_version)->firstOrFail();
            $settings = json_decode($previous->sections, true, flags: JSON_THROW_ON_ERROR);
            $id = $this->candidate($settings);
            $this->copyRows($previous->id, $id);
            $this->refreshRows($id, [$section]);
            $this->finish($id, $settings);
            DB::table(self::STATE)->where('id', 1)->update(['published_version' => $id, 'updated_at' => now()]);
            if (! $state->draft_pending) {
                $this->rows()->where('section', $section)->delete();
                DB::table('ambresoft_sitemap_draft_urls')->insertUsing([...self::FIELDS, 'created_at', 'updated_at'], $this->rows($id)->where('section', $section)->select(self::FIELDS)->selectRaw('CURRENT_TIMESTAMP, CURRENT_TIMESTAMP'));
                DB::table(self::STATE)->where('id', 1)->update(['draft_version' => DB::raw('draft_version + 1')]);
            }
        });
    }

    /** @param list<string> $sections */
    public function failed(array $sections, string $token, Throwable $error): void
    {
        $message = $error instanceof ValidationException ? implode(' ', array_merge(...array_values($error->errors()))) : __('cms-sitemap::ui.operation_failed');
        $changed = DB::table(self::SECTIONS)->whereIn('key', $sections)->where('generation_token', $token)->update(['status' => 'failed', 'error' => Str::limit($message, 490), 'generation_token' => null, 'next_refresh_at' => now()->addMinutes(5)]);
        if ($changed > 0) {
            Log::error('sitemap_refresh_failed', ['sections' => $sections, 'type' => $error::class]);
        }
    }

    private function next(string $frequency): ?CarbonImmutable
    {
        $date = CarbonImmutable::now();

        return match ($frequency) {
            'hour' => $date->addHour(), 'day' => $date->addDay(), 'week' => $date->addWeek(), 'month' => $date->addMonthNoOverflow(), 'year' => $date->addYearNoOverflow(), default => null,
        };
    }

    /** @return array{xml:string,etag:string}|null */
    public function file(?string $version, string $name): ?array
    {
        $version ??= $this->state()->published_version;
        if ($version === null) {
            return null;
        }
        $row = DB::table(self::FILES)->where('version_id', $version)->where('name', $name)->first(['xml']);

        return $row === null ? null : ['xml' => $row->xml, 'etag' => hash('sha256', $row->xml)];
    }
}
