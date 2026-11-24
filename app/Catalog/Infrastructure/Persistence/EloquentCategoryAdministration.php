<?php

namespace App\Catalog\Infrastructure\Persistence;

use App\Audit\Domain\Model\AuditEvent;
use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Catalog\Application\Command\CategoryDeletion;
use App\Catalog\Application\Command\DeleteCategoryTarget;
use App\Catalog\Application\Command\SaveCategoryData;
use App\Catalog\Application\Contract\CategoryAdministrationInterface;
use App\Catalog\Application\Service\CategoryMutationParticipants;
use App\Catalog\Domain\Event\CategoryCreated;
use App\Catalog\Domain\Event\CategoryDeleted;
use App\Catalog\Domain\Event\CategoryMoved;
use App\Catalog\Domain\Event\CategoryUpdated;
use App\Catalog\Domain\Exception\InvalidCategoryChange;
use App\Catalog\Domain\Service\CategoryRules;
use App\Catalog\Infrastructure\CatalogCustomerGroups;
use App\Catalog\Infrastructure\CategoryHierarchy;
use App\Catalog\Infrastructure\CategoryInput;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Media\Infrastructure\Persistence\MediaFileRecord;
use App\Media\Infrastructure\PrivateImageStorage;
use App\Website\Infrastructure\BlogLinks;
use App\Website\Infrastructure\FaqLinks;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Throwable;

final readonly class EloquentCategoryAdministration implements CategoryAdministrationInterface
{
    public function __construct(private CategoryHierarchy $hierarchy, private CategoryRules $rules, private CategoryMapper $mapper, private CategoryInput $input, private CategoryMutationParticipants $participants, private PrivateImageStorage $images, private CatalogCustomerGroups $customerGroups) {}

    private function authorize(string $actorId, string $ability): void
    {
        app(AuditContext::class)->actor($actorId);
        $actor = UserRecord::findOrFail($actorId);
        Gate::forUser($actor)->authorize('access-admin');
        Gate::forUser($actor)->authorize($ability);
    }

    public function save(string $actorId, ?string $categoryId, SaveCategoryData $data): string
    {
        return app(AuditLogger::class)->operation('category', $actorId, function () use ($actorId, $categoryId, $data): string {
            return $this->persist($actorId, $categoryId, $data, false);

        });
    }

    public function saveImported(string $actorId, ?string $categoryId, SaveCategoryData $data): string
    {
        return app(AuditLogger::class)->operation('category', $actorId, function () use ($actorId, $categoryId, $data): string {
            return $this->persist($actorId, $categoryId, $data, true);

        });
    }

    private function persist(string $actorId, ?string $categoryId, SaveCategoryData $data, bool $importing): string
    {
        $image = null;
        $blockImage = $bannerImage = $menuIcon = null;
        try {
            return DB::transaction(function () use ($actorId, $categoryId, $data, $importing, &$image, &$blockImage, &$bannerImage, &$menuIcon): string {
                $this->hierarchy->lock();
                if ($importing) {
                    $this->authorize($actorId, 'categories.import');
                }
                $this->authorize($actorId, $categoryId === null ? 'categories.create' : 'categories.update');
                $record = $categoryId === null ? new CategoryRecord : CategoryRecord::findOrFail($categoryId);
                $oldParent = $record->parent_id === null ? null : (string) $record->parent_id;
                if ($record->exists) {
                    $this->rules->assertEditable($this->mapper->toDomain($record));
                    $this->assertVersion($record, $data->version);
                }
                $translations = $this->input->normalize($data->translations);
                $excludeFromDiscovery = $data->excludeFromDiscovery ?? $record->exclude_from_discovery ?? false;
                $excludeFromSitemap = $data->excludeFromSitemap ?? $record->exclude_from_sitemap ?? false;
                $isLandingPage = $data->isLandingPage ?? $record->is_landing_page ?? false;
                $ageVerification = $data->ageVerification ?? $record->age_verification ?? false;
                $ageLimit = $data->ageVerification === null && $data->ageLimit === null ? $record->age_limit : $data->ageLimit;
                Validator::make(['parent_id' => $data->parentId, 'position' => $data->position, 'status' => $data->status, 'version' => $data->version, 'translations' => $translations, 'remove_image' => $data->removeImage, 'remove_block_image' => $data->removeBlockImage, 'remove_banner_image' => $data->removeBannerImage, 'age_verification' => $ageVerification, 'age_limit' => $ageLimit, 'exclude_from_discovery' => $excludeFromDiscovery, 'exclude_from_sitemap' => $excludeFromSitemap, 'is_landing_page' => $isLandingPage, ...($data->customerGroups === null ? [] : ['customer_groups' => $data->customerGroups])], $importing ? $this->input->importRules($categoryId) : $this->input->rules($categoryId), [], $this->input->attributes())->validate();
                if (! $importing && app(LocaleCatalog::class)->active()->count() >= 2) {
                    $complete = $record->exists ? $record->translations()->with('locale')->get()->mapWithKeys(fn ($t): array => [$t->locale->code => $t->only(['name', 'description', 'seo_title', 'seo_description', 'slug', 'image_alt', 'meta_keywords', 'age_warning_message'])])->all() : [];
                    $this->input->assertComplete(array_replace($complete, $translations));
                }
                $this->rules->assertPosition($data->position);
                if ($data->parentId !== null) {
                    $parent = CategoryRecord::findOrFail($data->parentId);
                    $this->rules->assertParent($categoryId, $this->hierarchy->pathIds($data->parentId), $parent->system_key !== null);
                    if (! $record->exists || $oldParent !== $data->parentId) {
                        $this->participants->beforeParentUsed($data->parentId);
                    }
                }
                // A landing page lists subcategories instead of products, so it cannot hold any.
                if ($isLandingPage && $record->exists && ! $record->is_landing_page && DB::table('products')->where('category_id', $record->id)->exists()) {
                    throw new InvalidCategoryChange('categories.errors.landing_has_products');
                }
                if ($data->image !== null && $data->removeImage) {
                    throw new InvalidCategoryChange('categories.errors.image_conflict');
                }
                if (($data->blockImage !== null && $data->removeBlockImage) || ($data->bannerImage !== null && $data->removeBannerImage) || ($data->menuIcon !== null && $data->removeMenuIcon)) {
                    throw new InvalidCategoryChange('categories.errors.image_conflict');
                }
                if (! $record->exists || $oldParent !== $data->parentId || $record->position !== $data->position) {
                    $this->reservePosition($data->parentId, $data->position, $categoryId);
                }
                $record->fill(['parent_id' => $data->parentId, 'position' => $data->position, 'status' => $data->status, 'age_verification' => $ageVerification, 'age_limit' => $ageLimit, 'exclude_from_discovery' => $excludeFromDiscovery, 'exclude_from_sitemap' => $excludeFromSitemap]);
                // Set from the editor only; CSV imports leave it as it is.
                $record->is_landing_page = $isLandingPage;
                if ($data->bannerLink !== null) {
                    $record->banner_link = $data->bannerLink === '' ? null : $data->bannerLink;
                }
                if ($data->image !== null) {
                    $image = $this->images->store($data->image);
                    $media = MediaFileRecord::create($image);
                    $record->image_media_id = $media->id;
                } elseif ($data->removeImage) {
                    $record->image_media_id = null;
                }
                if ($data->blockImage !== null) {
                    $blockImage = $this->images->store($data->blockImage);
                    $record->block_image_media_id = MediaFileRecord::create($blockImage)->id;
                } elseif ($data->removeBlockImage) {
                    $record->block_image_media_id = null;
                }
                if ($data->menuIcon !== null) {
                    $menuIcon = $this->images->store($data->menuIcon);
                    $record->menu_icon_media_id = MediaFileRecord::create($menuIcon)->id;
                } elseif ($data->removeMenuIcon) {
                    $record->menu_icon_media_id = null;
                }
                if ($data->bannerImage !== null) {
                    $bannerImage = $this->images->store($data->bannerImage);
                    $record->banner_image_media_id = MediaFileRecord::create($bannerImage)->id;
                } elseif ($data->removeBannerImage) {
                    $record->banner_image_media_id = null;
                }
                $record->lock_version = $record->exists ? $record->lock_version + 1 : 1;
                $record->save();
                $this->participants->afterCategorySave((string) $record->id, $actorId);
                if ($data->customerGroups !== null) {
                    $this->customerGroups->sync('category', (string) $record->id, $data->customerGroups);
                }
                if ($data->blogPostIds !== null) {
                    app(BlogLinks::class)->sync((string) $record->id, $data->blogPostIds);
                }
                if ($data->faqIds !== null || $data->faqDrafts !== []) {
                    app(FaqLinks::class)->sync('category', (string) $record->id, $data->faqIds ?? [], $actorId, $data->faqDrafts);
                }
                $localeIds = app(LocaleCatalog::class)->active()->pluck('id', 'code');
                foreach ($translations as $code => $fields) {
                    $record->translations()->updateOrCreate(['locale_id' => $localeIds[$code]], $fields);
                }
                // Validate the final sibling set before publishing a fact, including under test/outer transactions.
                DB::statement('SET CONSTRAINTS categories_sibling_position_unique IMMEDIATE');
                $id = (string) $record->id;
                $occurredAt = new DateTimeImmutable;
                $fact = $categoryId === null ? new CategoryCreated($id, $actorId, $occurredAt) : new CategoryUpdated($id, $actorId, $occurredAt);
                $newParent = $data->parentId;
                $newPosition = $data->position;
                DB::afterCommit(function () use ($fact, $categoryId, $oldParent, $newParent, $newPosition, $id, $actorId, $occurredAt): void {
                    $this->publish($fact);
                    if ($categoryId !== null && $oldParent !== $newParent) {
                        $this->publish(new CategoryMoved($id, $oldParent, $newParent, $newPosition, $actorId, $occurredAt));
                    }
                });

                return $id;
            });
        } catch (Throwable $failure) {
            foreach ([$image, $blockImage, $bannerImage, $menuIcon] as $stored) {
                if ($stored !== null && ! MediaFileRecord::where('storage_key', $stored['storage_key'])->exists()) {
                    $this->images->discard($stored['storage_key']);
                }
            }
            throw $failure;
        }
    }

    private function reservePosition(?string $parentId, int $position, ?string $exclude): void
    {
        DB::statement('SET CONSTRAINTS categories_sibling_position_unique DEFERRED');
        $siblings = CategoryRecord::where('parent_id', $parentId)->whereNull('system_key')->when($exclude !== null, fn ($q) => $q->where('id', '<>', $exclude));
        if (! (clone $siblings)->where('position', $position)->exists()) {
            return;
        }
        if ((clone $siblings)->where('position', '>=', $position)->max('position') >= 2147483647) {
            throw new InvalidCategoryChange('categories.errors.position_full');
        }
        $siblings->where('position', '>=', $position)->update(['position' => DB::raw('position + 1'), 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]);
    }

    private function publish(object $fact): void
    {
        try {
            Event::dispatch($fact);
        } catch (Throwable $failure) {
            // SQL is already committed. A notification failure cannot turn a successful
            // import into a misleading rollback result or encourage duplicate creation.
            report($failure);
        }
    }

    private function assertVersion(CategoryRecord $record, ?int $version): void
    {
        if ($record->lock_version !== $version) {
            throw new InvalidCategoryChange('categories.errors.stale');
        }
    }

    public function delete(string $actorId, string $categoryId, int $version): void
    {
        app(AuditLogger::class)->operation('category', $actorId, function () use ($actorId, $categoryId, $version): void {
            DB::transaction(function () use ($actorId, $categoryId, $version): void {
                $this->hierarchy->lock();
                $this->authorize($actorId, 'categories.delete');
                $record = CategoryRecord::withCount('children')->findOrFail($categoryId);
                $this->assertDeletableRecord($record, $version);
                $uncategorizedId = (string) CategoryRecord::where('system_key', 'uncategorized')->sole()->id;
                $this->performDeletion($record, $uncategorizedId, $actorId);
            });

        });
    }

    /** @param list<DeleteCategoryTarget> $targets */
    public function deleteMany(string $actorId, array $targets): void
    {
        app(AuditLogger::class)->operation('category', $actorId, function () use ($actorId, $targets): void {
            DB::transaction(function () use ($actorId, $targets): void {
                $this->hierarchy->lock();
                $this->authorize($actorId, 'categories.delete');
                $ids = array_map(fn (DeleteCategoryTarget $target): string => $target->id, $targets);
                if ($ids === [] || count($ids) > 40 || count(array_unique($ids)) !== count($ids)) {
                    throw new InvalidCategoryChange('categories.errors.bulk_selection');
                }
                $records = CategoryRecord::withCount('children')->whereIn('id', $ids)->get()->keyBy(fn (CategoryRecord $record): string => (string) $record->id);
                $validated = [];
                foreach ($targets as $target) {
                    $record = $records->get($target->id);
                    if ($record === null || $record->parent_id === null) {
                        throw new InvalidCategoryChange('categories.errors.bulk_selection');
                    }
                    $this->assertDeletableRecord($record, $target->version);
                    $validated[] = $record;
                }
                $uncategorizedId = (string) CategoryRecord::where('system_key', 'uncategorized')->sole()->id;
                foreach ($validated as $record) {
                    $this->performDeletion($record, $uncategorizedId, $actorId);
                }
                app(AuditLogger::class)->record(new AuditEvent('category.bulk_completed', 'category', metadata: ['success_count' => count($validated), 'failure_count' => 0]));
            });

        });
    }

    private function assertDeletableRecord(CategoryRecord $record, int $version): void
    {
        $this->rules->assertDeletable($this->mapper->toDomain($record), (int) $record->children_count > 0);
        $this->assertVersion($record, $version);
    }

    private function performDeletion(CategoryRecord $record, string $uncategorizedId, string $actorId): void
    {
        $categoryId = (string) $record->id;
        $deletion = new CategoryDeletion($categoryId, $uncategorizedId, $actorId);
        $this->participants->beforeDeletion($deletion);
        $record->delete();
        $fact = new CategoryDeleted($categoryId, $uncategorizedId, $actorId, new DateTimeImmutable);
        DB::afterCommit(fn () => $this->publish($fact));
    }
}
