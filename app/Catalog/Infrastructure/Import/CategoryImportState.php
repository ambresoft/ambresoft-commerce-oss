<?php

namespace App\Catalog\Infrastructure\Import;

use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Catalog\Infrastructure\Persistence\CategoryTranslationRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Bounded metadata projection, not a CSV or description cache. */
final class CategoryImportState
{
    /** @var array<string, array<string, mixed>> */
    public array $nodes = [];

    /** @var array<string, array<string, list<string>>> */
    private array $names = [];

    /** @var array<string, string> */
    public array $slugs = [];

    /** @var array<string, array<string, string>> */
    private array $ownedSlugs = [];

    /** @var array<string, int> */
    private array $maximum = [];

    /** @var array<string, array<int, string>> */
    private array $positions = [];

    /** @var array<string, bool> Existing customer group IDs => whether the group is active. */
    public array $groupIds = [];

    public string $fingerprint;

    public string $default;

    /** @var array<string, string> */
    public array $localeIds;

    public function __construct()
    {
        $locales = app(LocaleCatalog::class);
        $this->default = $locales->defaultCode();
        $this->localeIds = $locales->active()->mapWithKeys(fn ($l): array => [$l->code => (string) $l->id])->all();
        $hash = hash_init('sha256');
        hash_update($hash, json_encode([$this->default, $this->localeIds], JSON_THROW_ON_ERROR));
        $this->groupIds = DB::table('customer_groups')->orderBy('id')->pluck('status', 'id')->mapWithKeys(fn ($status, $id): array => [(string) $id => $status === 'active'])->all();
        /** @var array<int|string, list<string>> $restrictions */
        $restrictions = [];
        foreach (DB::table('category_customer_groups')->orderBy('category_id')->orderBy('group_id')->get() as $row) {
            $restrictions[(string) $row->category_id][] = (string) $row->group_id;
        }
        foreach (CategoryRecord::orderBy('id')->lazyById(500) as $record) {
            $id = (string) $record->id;
            $node = $record->only(['parent_id', 'position', 'status', 'system_key', 'lock_version', 'age_verification', 'age_limit', 'exclude_from_discovery', 'image_media_id']);
            $node['parent_id'] = $node['parent_id'] === null ? null : (string) $node['parent_id'];
            $node['customer_groups'] = $restrictions[$id] ?? [];
            $node['translations'] = [];
            $this->nodes[$id] = $node;
            hash_update($hash, json_encode([$id, $node], JSON_THROW_ON_ERROR));
            $key = $node['parent_id'] ?? '';
            $this->maximum[$key] = max($this->maximum[$key] ?? 0, $node['position']);
            $this->positions[$key][$node['position']] = $id;
        }
        // Only short path identity/slug data is retained; descriptions are fetched per edited locale.
        foreach (DB::table('category_translations')->orderBy('id')->lazyById(500) as $record) {
            hash_update($hash, json_encode($record, JSON_THROW_ON_ERROR));
            $code = array_search((string) $record->locale_id, $this->localeIds, true);
            $this->slugs[$record->locale_id.':'.$record->slug] = (string) $record->category_id;
            $this->ownedSlugs[(string) $record->category_id][(string) $record->locale_id] = $record->slug;
            if ($code === $this->default) {
                $id = (string) $record->category_id;
                $this->nodes[$id]['name'] = $record->name;
                $this->index($id);
            }
        }
        $this->fingerprint = hash_final($hash);
    }

    private function index(string $id): void
    {
        $node = $this->nodes[$id];
        if ($node['system_key'] === null && isset($node['name'])) {
            $this->names[$node['parent_id'] ?? ''][$node['name']][] = $id;
        }
    }

    public function find(?string $parent, string $name): ?string
    {
        $matches = $this->names[$parent ?? ''][$name] ?? [];
        if (count($matches) > 1) {
            throw new RuntimeException(__('category_import.ambiguous'));
        }

        return $matches[0] ?? null;
    }

    public function nextPosition(?string $parent): int
    {
        return ($this->maximum[$parent ?? ''] ?? 0) + 1;
    }

    public function reserve(string $id, ?string $parent, int $position): void
    {
        $key = $parent ?? '';
        $occupied = $this->positions[$key][$position] ?? null;
        if ($occupied === null || $occupied === $id) {
            return;
        }
        $positions = $this->positions[$key];
        krsort($positions);
        if (($this->maximum[$key] ?? 0) >= 2147483647) {
            throw new RuntimeException(__('categories.errors.position_full'));
        }
        foreach ($positions as $oldPosition => $siblingId) {
            if ($oldPosition >= $position && $siblingId !== $id) {
                $this->nodes[$siblingId]['position']++;
                unset($this->positions[$key][$oldPosition]);
                $this->positions[$key][$oldPosition + 1] = $siblingId;
            }
        }
        $this->maximum[$key]++;
    }

    /** @return array<string, string> */
    public function translation(string $id, string $code): array
    {
        if (isset($this->nodes[$id]['translations'][$code])) {
            return $this->nodes[$id]['translations'][$code];
        }
        if (str_starts_with($id, 'new:')) {
            return [];
        }
        $row = CategoryTranslationRecord::where('category_id', $id)->where('locale_id', $this->localeIds[$code])->first();

        return $row?->only(CategoryImportFields::TRANSLATED) ?? [];
    }

    public function assertParent(string $id, ?string $parent): void
    {
        for ($cursor = $parent; $cursor !== null; $cursor = $this->nodes[$cursor]['parent_id']) {
            if ($cursor === $id) {
                throw new RuntimeException(__('categories.errors.parent'));
            }
        }
    }

    /** @param array<string, mixed> $node */
    public function put(string $id, array $node): void
    {
        $old = $this->nodes[$id] ?? null;
        if ($old !== null && ($this->positions[$old['parent_id'] ?? ''][$old['position']] ?? null) === $id) {
            unset($this->positions[$old['parent_id'] ?? ''][$old['position']]);
        }
        if ($old !== null && isset($old['name'])) {
            $key = $old['parent_id'] ?? '';
            $this->names[$key][$old['name']] = array_values(array_diff($this->names[$key][$old['name']] ?? [], [$id]));
        }
        foreach ($node['translations'] as $code => $fields) {
            $localeId = $this->localeIds[$code];
            $oldSlug = $this->ownedSlugs[$id][$localeId] ?? null;
            if ($oldSlug !== null) {
                unset($this->slugs[$localeId.':'.$oldSlug]);
            }
            $this->slugs[$localeId.':'.$fields['slug']] = $id;
            $this->ownedSlugs[$id][$localeId] = $fields['slug'];
        }
        $this->nodes[$id] = $node;
        $this->maximum[$node['parent_id'] ?? ''] = max($this->maximum[$node['parent_id'] ?? ''] ?? 0, $node['position']);
        $this->positions[$node['parent_id'] ?? ''][$node['position']] = $id;
        $this->index($id);
    }
}
