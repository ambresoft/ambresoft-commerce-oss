<?php

namespace App\Catalog\Infrastructure;

use App\Catalog\Domain\Service\CategoryAgeRestrictions;
use App\Catalog\Infrastructure\Persistence\CategoryMapper;
use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Localization\Domain\Service\TranslationResolver;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Media\Infrastructure\MediaMetadata;
use App\Website\Infrastructure\BlogLinks;
use App\Website\Infrastructure\FaqLinks;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final readonly class CategoryQueries
{
    private string $defaultLocale;

    public function __construct(private CategoryMapper $mapper, private TranslationResolver $resolver, private LocaleCatalog $locales, private CategoryHierarchy $hierarchy)
    {
        $this->defaultLocale = $locales->defaultCode();
    }

    /** @return Builder<CategoryRecord> */
    private function localized(): Builder
    {
        $codes = array_unique([app()->getLocale(), $this->defaultLocale, 'en']);

        return CategoryRecord::with(['translations' => fn ($q) => $q->whereHas('locale', fn ($l) => $l->whereIn('code', $codes))->with('locale')])->withCount('children');
    }

    /** @return array<string, mixed> */
    public function summary(CategoryRecord $record): array
    {
        $entity = $this->mapper->toDomain($record);
        $translation = $this->resolver->resolveTranslation($entity, app()->getLocale(), $this->defaultLocale);

        return [
            'id' => $entity->id, 'parentId' => $entity->parentId, 'position' => $entity->position,
            'status' => $entity->status, 'system' => $entity->systemKey !== null, 'version' => $entity->version,
            'name' => $entity->systemKey === 'uncategorized' ? __('categories.uncategorized') : ($translation?->fields['name'] ?? __('categories.translation_missing')),
            'resolvedLocale' => $translation?->locale,
            'translationState' => $entity->systemKey !== null ? 'system' : ($entity->translation(app()->getLocale()) === null ? 'fallback' : 'translated'),
            'childrenCount' => (int) $record->children_count,
            'editUrl' => route('admin.categories.edit', $record->id, false),
            'childUrl' => route('admin.categories.create', ['parent' => $record->id], false),
        ];
    }

    /** @return array<string, mixed> */
    public function branch(?string $parentId, int $page = 1): array
    {
        if ($parentId !== null) {
            CategoryRecord::whereNull('system_key')->findOrFail($parentId);
        }
        $pagination = $this->localized()->where('parent_id', $parentId)->orderBy('position')->orderBy('id')->paginate(40, ['*'], 'page', $page);

        return ['parentId' => $parentId, 'data' => $pagination->getCollection()->map(fn (CategoryRecord $c) => $this->summary($c))->all(), 'page' => $pagination->currentPage(), 'lastPage' => $pagination->lastPage(), 'total' => $pagination->total()];
    }

    /** @param Builder<CategoryRecord> $query */
    private function matchingName(Builder $query, string $search): void
    {
        // Search the same resolved row shown in the UI, not a hidden other-language name.
        $query->whereRaw("COALESCE((SELECT ct.name FROM category_translations ct JOIN locales l ON l.id = ct.locale_id WHERE ct.category_id = categories.id AND l.code = ?), (SELECT ct.name FROM category_translations ct JOIN locales l ON l.id = ct.locale_id WHERE ct.category_id = categories.id AND l.code = ?), (SELECT ct.name FROM category_translations ct JOIN locales l ON l.id = ct.locale_id WHERE ct.category_id = categories.id AND l.code = 'en'), '') ILIKE ?", [app()->getLocale(), $this->defaultLocale, '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%']);
    }

    /** @return array<string, mixed> */
    public function search(string $search, ?string $status, int $page): array
    {
        $query = $this->localized()->whereNull('system_key');
        if ($search !== '') {
            $this->matchingName($query, $search);
        }
        $pagination = $query->when($status !== null && $status !== '', fn ($q) => $q->where('status', $status))->orderBy('parent_id')->orderBy('position')->orderBy('id')->paginate(40, ['*'], 'page', $page);
        $paths = $this->paths($pagination->getCollection()->pluck('id')->map(fn ($id): string => (string) $id)->all());
        $pagination->setCollection($pagination->getCollection()->map(fn (CategoryRecord $c): array => [...$this->summary($c), 'path' => $paths[(string) $c->id] ?? []]));

        return $pagination->withQueryString()->toArray();
    }

    /**
     * @param  list<string>  $ids
     * @return array<int|string, list<string>>
     */
    public function paths(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = DB::select("WITH RECURSIVE ancestors AS (SELECT id AS origin, id, parent_id, 0 AS depth FROM categories WHERE id IN ($placeholders) UNION ALL SELECT a.origin, c.id, c.parent_id, a.depth + 1 FROM categories c JOIN ancestors a ON c.id = a.parent_id) SELECT origin, id FROM ancestors ORDER BY origin, depth DESC", $ids);
        $records = $this->localized()->whereIn('id', array_unique(array_column($rows, 'id')))->get()->keyBy('id');
        $paths = [];
        foreach ($rows as $row) {
            $paths[(string) $row->origin][] = $this->summary($records[$row->id])['name'];
        }

        return $paths;
    }

    /** @return list<array{id: string, name: string}> */
    public function parents(?string $exclude, string $search, ?string $selected): array
    {
        $query = $this->localized()->whereNull('system_key');
        if ($exclude !== null) {
            $query->whereRaw('categories.id NOT IN (WITH RECURSIVE subtree AS (SELECT id FROM categories WHERE id = ? UNION ALL SELECT c.id FROM categories c JOIN subtree s ON c.parent_id = s.id) SELECT id FROM subtree)', [$exclude]);
        }
        if ($search !== '') {
            $this->matchingName($query, $search);
        }
        $records = $query->orderBy('position')->orderBy('id')->limit(30)->get();
        if ($selected !== null && ! $records->contains('id', $selected) && ! in_array($exclude, $this->hierarchy->pathIds($selected), true)) {
            $current = $this->localized()->whereNull('system_key')->find($selected);
            if ($current !== null) {
                $records->prepend($current);
            }
        }

        // Full paths ("Technology › Phones"), so same-named categories can be told apart.
        $paths = $this->paths($records->pluck('id')->map(strval(...))->all());

        return $records->map(fn (CategoryRecord $record): array => ['id' => (string) $record->id, 'name' => implode(' › ', $paths[(string) $record->id] ?? [$this->summary($record)['name']])])->values()->all();
    }

    /** @return array<string, mixed> */
    public function editor(CategoryRecord $record): array
    {
        $codes = $this->locales->active()->pluck('code')->all();
        $record->load(['translations' => fn ($q) => $q->whereHas('locale', fn ($l) => $l->whereIn('code', array_unique([...$codes, 'en'])))->with('locale')]);
        $record->loadCount('children');
        $name = $this->resolver->resolveTranslation($this->mapper->toDomain($record), app()->getLocale(), $this->defaultLocale)?->fields['name'] ?? __('categories.translation_missing');
        $translations = [];
        foreach ($record->translations as $translation) {
            if (! in_array($translation->locale->code, $codes, true)) {
                continue;
            }
            $translations[$translation->locale->code] = $translation->only(['name', 'description', 'seo_title', 'seo_description', 'slug', 'image_alt', 'meta_keywords', 'age_warning_message']);
        }

        return ['id' => (string) $record->id, 'faqIds' => app(FaqLinks::class)->assigned('category', (string) $record->id), 'blogPostIds' => app(BlogLinks::class)->assigned((string) $record->id), 'displayName' => $name, 'childrenCount' => (int) $record->children_count, 'parentId' => $record->parent_id === null ? null : (string) $record->parent_id, 'position' => $record->position, 'status' => $record->status, 'excludeFromDiscovery' => $record->exclude_from_discovery, 'excludeFromSitemap' => (bool) $record->exclude_from_sitemap, 'isLandingPage' => (bool) $record->is_landing_page, 'customerGroups' => app(CatalogCustomerGroups::class)->assigned('category', (string) $record->id), 'ageVerification' => $record->age_verification, 'ageLimit' => $record->age_limit, 'version' => $record->lock_version, 'translations' => (object) $translations, 'imageUrl' => $record->image_media_id === null ? null : route('admin.categories.image', $record->id, false).'?v='.$record->lock_version, 'imageMeta' => app(MediaMetadata::class)->one($record->image_media_id === null ? null : (string) $record->image_media_id), 'blockImageUrl' => $record->block_image_media_id === null ? null : route('admin.categories.block-image', $record->id, false).'?v='.$record->lock_version, 'blockImageMeta' => app(MediaMetadata::class)->one($record->block_image_media_id === null ? null : (string) $record->block_image_media_id), 'menuIconUrl' => $record->menu_icon_media_id === null ? null : route('admin.categories.menu-icon', $record->id, false).'?v='.$record->lock_version, 'menuIconMeta' => app(MediaMetadata::class)->one($record->menu_icon_media_id === null ? null : (string) $record->menu_icon_media_id), 'bannerLink' => $record->banner_link ?? '', 'bannerImageUrl' => $record->banner_image_media_id === null ? null : route('admin.categories.banner-image', $record->id, false).'?v='.$record->lock_version, 'bannerImageMeta' => app(MediaMetadata::class)->one($record->banner_image_media_id === null ? null : (string) $record->banner_image_media_id)];
    }

    /** @return array{parentId: ?string, verification: bool, ageLimit: ?int, sourceCategoryId: ?string} */
    public function parentAgeRestriction(?string $parentId): array
    {
        if ($parentId === null) {
            return ['parentId' => null, 'verification' => false, 'ageLimit' => null, 'sourceCategoryId' => null];
        }
        CategoryRecord::whereNull('system_key')->findOrFail($parentId);
        $ids = $this->hierarchy->pathIds($parentId);
        $records = CategoryRecord::whereIn('id', $ids)->get()->keyBy('id');
        $path = array_map(fn (string $id) => $this->mapper->toDomain($records[$id]), $ids);

        return ['parentId' => $parentId, ...(new CategoryAgeRestrictions)->forPath($path)];
    }
}
