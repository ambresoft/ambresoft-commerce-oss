<?php

namespace App\Catalog\Infrastructure\Persistence;

use App\Audit\Domain\Model\AuditEvent;
use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Catalog\Application\Command\SaveProductData;
use App\Catalog\Application\Contract\ProductAdministration;
use App\Catalog\Application\Contract\VariationGroupAdministration;
use App\Catalog\Application\Service\ProductDeletionGuards;
use App\Catalog\Domain\Service\ProductRules;
use App\Catalog\Infrastructure\CatalogCustomerGroups;
use App\Catalog\Infrastructure\CategoryHierarchy;
use App\Catalog\Infrastructure\ProductAudit;
use App\Catalog\Infrastructure\ProductCategories;
use App\Catalog\Infrastructure\ProductCompanions;
use App\Catalog\Infrastructure\ProductFeatures;
use App\Catalog\Infrastructure\ProductFiles;
use App\Catalog\Infrastructure\ProductImages;
use App\Catalog\Infrastructure\ProductInput;
use App\Catalog\Infrastructure\ProductPriceHistory;
use App\Catalog\Infrastructure\ProductState;
use App\Catalog\Infrastructure\VariationGroupIntegrity;
use App\Catalog\Infrastructure\VariationJoinOutcome;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Marketing\Infrastructure\Stickers;
use App\Pricing\Infrastructure\ProductSales;
use App\Tax\Application\Contract\ProductTaxAssignment;
use App\Website\Infrastructure\FaqLinks;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class EloquentProductAdministration implements ProductAdministration
{
    /** Non-translated fields recorded as `metadata_changed` when an edit changes them. */
    private const METADATA = ['exclude_from_sitemap', 'brand_id', 'ean', 'mpn', 'isbn', 'upc', 'returnable', 'quantity', 'list_price', 'min_qty', 'max_qty', 'qty_step', 'out_of_stock_actions', 'in_stock_status_id', 'out_of_stock_status_id', 'free_shipping', 'shipping_cost', 'box_min_items', 'box_max_items', 'box_length', 'box_width', 'box_height'];

    public function __construct(private ProductInput $input, private ProductState $state, private ProductAudit $audit, private ProductFiles $files, private ProductImages $images) {}

    private function authorize(string $actor, string $ability): void
    {
        app(AuditContext::class)->actor($actor);
        Gate::forUser(UserRecord::findOrFail($actor))->authorize($ability);
    }

    private function lock(): void
    {
        app(CategoryHierarchy::class)->lock();
    }

    public function draft(string $actor): string
    {
        return app(AuditLogger::class)->operation('product', $actor, function () use ($actor): string {
            return DB::transaction(function () use ($actor): string {
                $this->lock();
                $this->authorize($actor, 'products.create');
                $p = ProductRecord::create(['created_by' => $actor, 'updated_by' => $actor]);
                $this->audit->record((string) $p->id, $actor, 'draft_created');

                return (string) $p->id;
            });

        });
    }

    private function record(string $id, int $version, bool $trashed = false): ProductRecord
    {
        $p = ProductRecord::withTrashed()->lockForUpdate()->findOrFail($id);
        if ($p->lock_version !== $version || $p->trashed() !== $trashed) {
            throw ValidationException::withMessages(['version' => __('products.stale')]);
        }

        return $p;
    }

    public function save(string $actor, string $id, SaveProductData $data): void
    {
        app(AuditLogger::class)->operation('product', $actor, function () use ($actor, $id, $data): void {
            try {
                DB::transaction(function () use ($actor, $id, $data): void {
                    $this->lock();
                    $this->authorize($actor, 'products.update');
                    $p = $this->record($id, $data->version);
                    $wasIncomplete = $this->state->requiredErrors($p) !== [];
                    $oldStatus = $p->status;
                    $oldCategory = $p->category_id;
                    $oldType = $p->type;
                    $oldMetadata = $p->only(self::METADATA);
                    $oldAvailableFrom = $p->available_from?->toDateString();
                    $fields = $this->input->validate(['version' => $data->version, ...$data->fields]);
                    if ($fields['status'] !== $p->status) {
                        $this->authorize($actor, 'products.status');
                    }
                    if (array_key_exists('facebook_type', $fields)) {
                        $fields['facebook_type'] ??= ProductRules::FACEBOOK_TYPE_DEFAULT;
                    }
                    $p->fill(array_intersect_key($fields, array_flip(['brand_id', 'sku', 'ean', 'mpn', 'isbn', 'upc', 'returnable', 'type', 'status', 'price', 'list_price', 'quantity', 'min_qty', 'max_qty', 'qty_step', 'out_of_stock_actions', 'in_stock_status_id', 'out_of_stock_status_id', 'category_id', 'weight', 'length', 'width', 'height', 'free_shipping', 'shipping_cost', 'available_from', 'box_min_items', 'box_max_items', 'box_length', 'box_width', 'box_height', 'similar_products', 'reviews_enabled', 'facebook_type', 'exclude_from_sitemap'])));
                    $this->input->assertPackaging($p);
                    $p->updated_by = (int) $actor;
                    $p->lock_version++;
                    $p->save();
                    if (($fields['stickers_submitted'] ?? false) || array_key_exists('sticker_ids', $fields)) {
                        app(Stickers::class)->assignProduct($actor, $id, $fields['sticker_ids'] ?? []);
                    }
                    if (($fields['companions_submitted'] ?? false) || array_key_exists('companion_ids', $fields)) {
                        app(ProductCompanions::class)->sync($id, $fields['companion_ids'] ?? []);
                    }
                    if (($fields['sales_submitted'] ?? false) || array_key_exists('sales', $fields)) {
                        app(ProductSales::class)->sync($id, $fields['sales'] ?? []);
                    }
                    app(ProductPriceHistory::class)->record($p, $actor);
                    $this->input->translations($p, $fields['translations']);
                    if (array_key_exists('images', $fields)) {
                        $this->images->sync($id, array_map(strval(...), $fields['images']), empty($fields['primary_image']) ? null : (string) $fields['primary_image'], $data->fields['staged_images'] ?? []);
                    }
                    if (array_key_exists('files', $fields)) {
                        $this->files->sync($id, $fields['files'], $data->fields['staged_files'] ?? [], $fields['file_upload_purposes'] ?? [], $p->type);
                    }
                    $taxChange = ['added' => [], 'removed' => []];
                    if (($fields['taxes_submitted'] ?? false) || array_key_exists('tax_ids', $fields)) {
                        $taxChange = app(ProductTaxAssignment::class)->sync((string) $id, array_map(strval(...), $fields['tax_ids'] ?? []));
                    }
                    $groupChange = ['added' => [], 'removed' => []];
                    if (($fields['customer_groups_submitted'] ?? false) || array_key_exists('customer_groups', $fields)) {
                        $groupChange = app(CatalogCustomerGroups::class)->sync('product', $id, $fields['customer_groups'] ?? []);
                    }
                    if (($fields['faqs_submitted'] ?? false) || array_key_exists('faq_ids', $fields) || ! empty($fields['faq_drafts'])) {
                        app(FaqLinks::class)->sync('product', $id, $fields['faq_ids'] ?? [], $actor, $fields['faq_drafts'] ?? []);
                    }
                    $categories = app(ProductCategories::class);
                    $oldExtras = $categories->extras($id);
                    // A main category is never also an extra one, whether or not the extras were sent.
                    $categories->replace($id, (string) $p->category_id, ($fields['extra_categories_submitted'] ?? false) || array_key_exists('extra_category_ids', $fields) ? ($fields['extra_category_ids'] ?? []) : $oldExtras);
                    if ($oldExtras !== $newExtras = $categories->extras($id)) {
                        $this->audit->record($id, $actor, 'extra_categories_changed', ['from' => $oldExtras, 'to' => $newExtras]);
                    }
                    app(ProductFeatures::class)->save($p, $fields, $actor, (string) $oldCategory !== (string) $p->category_id);
                    $this->state->assertComplete($p, $p->status === 'active');
                    if ($p->status === 'active' && $oldStatus !== 'active') {
                        app(ProductFeatures::class)->assertRequired($p);
                    }
                    $this->assertVariationGroup($p, $oldType);
                    app(VariationJoinOutcome::class)->outcome = app(VariationGroupAdministration::class)->joinIntended($actor, $id);
                    $this->audit->record($id, $actor, $wasIncomplete ? 'completed' : 'edited');
                    $metadataChanges = [];
                    foreach ($oldMetadata as $field => $before) {
                        if ($before !== $p->getAttribute($field)) {
                            $metadataChanges[$field] = ['from' => $before, 'to' => $p->getAttribute($field)];
                        }
                    }
                    if ($oldAvailableFrom !== $p->available_from?->toDateString()) {
                        $metadataChanges['available_from'] = ['from' => $oldAvailableFrom, 'to' => $p->available_from?->toDateString()];
                    }
                    if ($groupChange['added'] !== [] || $groupChange['removed'] !== []) {
                        $metadataChanges['customer_groups'] = ['from' => $groupChange['removed'], 'to' => $groupChange['added']];
                    }
                    if ($taxChange['added'] !== [] || $taxChange['removed'] !== []) {
                        $metadataChanges['taxes'] = ['from' => $taxChange['removed'], 'to' => $taxChange['added']];
                    }
                    if ($metadataChanges !== []) {
                        $this->audit->record($id, $actor, 'metadata_changed', $metadataChanges);
                    }
                    if ($p->status !== $oldStatus) {
                        $this->audit->record($id, $actor, 'status_changed', ['from' => $oldStatus, 'to' => $p->status]);
                    }
                    if ((string) $oldCategory !== (string) $p->category_id) {
                        $this->audit->record($id, $actor, 'category_changed', ['from' => $oldCategory, 'to' => $p->category_id]);
                    }
                });
            } catch (QueryException $e) {
                if ($e->getCode() !== '23505') {
                    throw $e;
                }
                throw ValidationException::withMessages(['sku' => __('products.sku_or_slug_conflict')]);
            }

        });
    }

    /** A grouped product keeps its type, and its saved state must still satisfy its group. */
    private function assertVariationGroup(ProductRecord $p, string $oldType): void
    {
        $integrity = app(VariationGroupIntegrity::class);
        if (($group = $integrity->groupOf((string) $p->id)) === null) {
            return;
        }
        if ($p->type !== $oldType) {
            throw ValidationException::withMessages(['type' => __('variations.type_locked', ['group' => $integrity->groupLabel($group)])]);
        }
        $integrity->assert($group, (string) $p->id);
    }

    public function duplicate(string $actor, string $id, int $version): string
    {
        return app(AuditLogger::class)->operation('product', $actor, function () use ($actor, $id, $version): string {
            return DB::transaction(function () use ($actor, $id, $version): string {
                $this->lock();
                $this->authorize($actor, 'products.create');
                $this->authorize($actor, 'products.view');
                $source = $this->record($id, $version);
                $copy = $source->replicate(['sku', 'ean', 'upc', 'deleted_at', 'lock_version', 'created_by', 'updated_by']);
                $copy->status = 'disabled';
                $copy->sku = null;
                $copy->ean = null;
                $copy->upc = null;
                // A copy never inherits stock: no quantity is invented. Ordering rules and list price are copied.
                $copy->quantity = 0;
                $copy->lock_version = 1;
                $copy->created_by = $copy->updated_by = (int) $actor;
                if (! $this->state->validCategory($copy->category_id === null ? null : (string) $copy->category_id)) {
                    $copy->category_id = null;
                }
                $copy->save();
                app(ProductSales::class)->copy($id, (string) $copy->id);
                app(FaqLinks::class)->copyProduct((string) $source->id, (string) $copy->id);
                app(ProductCompanions::class)->copy($id, (string) $copy->id);
                app(ProductCategories::class)->replace((string) $copy->id, $copy->category_id === null ? null : (string) $copy->category_id, app(ProductCategories::class)->extras($id));
                // The copy is a new product: its history starts now, at the copied price.
                app(ProductPriceHistory::class)->record($copy, $actor);
                foreach ($source->translations as $translation) {
                    $values = $translation->only(ProductInput::TEXT);
                    $values['slug'] = ($translation->slug ?? $translation->name) === '' ? null : $this->input->slug($translation->slug ?? $translation->name, $translation->locale_id, (string) $copy->id, true);
                    $copy->translations()->create(['locale_id' => $translation->locale_id, ...$values]);
                }
                $this->audit->record((string) $copy->id, $actor, 'duplicated', ['source' => $id]);
                $this->images->duplicate($id, (string) $copy->id);
                app(ProductFeatures::class)->duplicate($id, $copy);
                app(ProductTaxAssignment::class)->copy($id, (string) $copy->id);
                app(CatalogCustomerGroups::class)->copy('product', $id, (string) $copy->id);

                return (string) $copy->id;
            });

        });
    }

    public function lifecycle(string $actor, string $id, int $version, string $action, string $confirmation = ''): void
    {
        app(AuditLogger::class)->operation('product', $actor, function () use ($actor, $id, $version, $action, $confirmation): void {
            DB::transaction(function () use ($actor, $id, $version, $action, $confirmation): void {
                $this->lock();
                $permission = match ($action) {
                    'trash' => 'products.delete', 'restore' => 'products.restore', 'purge' => 'products.force-delete', 'active', 'disabled', 'hidden' => 'products.status', default => throw ValidationException::withMessages(['action' => __('products.invalid_action')])
                };
                $this->authorize($actor, $permission);
                $p = $this->record($id, $version, in_array($action, ['restore', 'purge'], true));
                if ($action === 'purge') {
                    $this->authorize($actor, 'products.trash-view');
                    $defaultId = app(LocaleCatalog::class)->active()->firstWhere('code', app(LocaleCatalog::class)->defaultCode())->id;
                    $name = $p->translations()->where('locale_id', $defaultId)->value('name');
                    if (trim($name ?? '') === '') {
                        $name = __('products.untitled', ['id' => $id]);
                    }
                    if (! hash_equals($name, $confirmation)) {
                        throw ValidationException::withMessages(['confirmation' => __('products.confirm_exact')]);
                    }
                    if (($reason = app(ProductDeletionGuards::class)->reason($id)) !== null) {
                        throw ValidationException::withMessages(['product' => __($reason)]);
                    }
                    $fileIds = DB::table('product_files')->where('product_id', $id)->pluck('private_file_id')->map(strval(...))->all();
                    $imageIds = DB::table('product_images')->where('product_id', $id)->pluck('media_file_id')->map(strval(...))->all();
                    DB::table('private_product_files')->whereIn('id', $fileIds)->orderBy('id')->lockForUpdate()->get();
                    $this->files->schedule($fileIds); // Durable job intent and deletion commit together.
                    $this->audit->record($id, $actor, 'permanently_deleted');
                    $p->forceDelete();
                    $this->images->deleteAssets($imageIds);

                    return;
                }
                $previous = $p->status;
                if ($action === 'trash') {
                    // Trash keeps the full product but leaves its group (auto-disbanding an emptied group).
                    app(VariationGroupAdministration::class)->detachForTrash($actor, $id);
                    $p->deleted_at = now();
                } elseif ($action === 'restore') {
                    if ($p->sku !== null && ProductRecord::withTrashed()->whereRaw('lower(sku) = lower(?)', [$p->sku])->whereKeyNot($p->id)->exists()) {
                        throw ValidationException::withMessages(['sku' => __('products.sku_conflict')]);
                    }
                    $p->deleted_at = null;
                    $p->status = 'disabled';
                    if (! $this->state->validCategory($p->category_id === null ? null : (string) $p->category_id)) {
                        $p->category_id = CategoryRecord::where('system_key', 'uncategorized')->sole()->id;
                    }
                } else {
                    if ($action === 'active') {
                        $this->state->assertComplete($p, true);
                        app(ProductFeatures::class)->assertRequired($p);
                        $this->assertVariationGroup($p, $p->type);
                    }
                    $p->status = $action;
                }
                $p->lock_version++;
                $p->updated_by = (int) $actor;
                $p->save();
                if ($action === 'restore') {
                    app(ProductFeatures::class)->reconcile(product: $id, bumpProduct: false);
                }
                $this->audit->record($id, $actor, $action, ['previous_status' => $previous, 'status' => $p->status]);
            });

        });
    }

    public function bulk(string $actor, array $ids, string $status): array
    {
        return app(AuditLogger::class)->operation('product', $actor, function () use ($actor, $ids, $status): array {
            $this->authorize($actor, 'products.status');
            if (! in_array($status, ['active', 'disabled', 'hidden'], true) || count($ids) > 100 || $ids === []) {
                throw ValidationException::withMessages(['targets' => __('products.invalid_action')]);
            }
            $results = [];
            foreach (array_unique($ids) as $id) {
                try {
                    DB::transaction(function () use ($actor, $id, $status): void {
                        $this->lock();
                        $p = ProductRecord::whereKey($id)->first();
                        if ($p === null) {
                            throw ValidationException::withMessages(['product' => __('products.unavailable')]);
                        }
                        $this->lifecycle($actor, $id, $p->lock_version, $status);
                    });
                    $results[] = ['id' => $id, 'success' => true, 'reason' => ''];
                } catch (ValidationException $e) {
                    $results[] = ['id' => $id, 'success' => false, 'reason' => implode(' ', array_merge(...array_values($e->errors())))];
                } catch (AuthorizationException) {
                    $results[] = ['id' => $id, 'success' => false, 'reason' => __('products.forbidden')];
                }
            }
            $this->audit->record(null, $actor, 'bulk_'.$status, ['outcomes' => $results]);
            app(AuditLogger::class)->record(new AuditEvent('product.bulk_completed', 'product', status: count(array_filter($results, fn ($r) => ! $r['success'])) > 0 ? 'failure' : 'success', metadata: ['success_count' => count(array_filter($results, fn ($r) => $r['success'])), 'failure_count' => count(array_filter($results, fn ($r) => ! $r['success']))]));

            return $results;

        });
    }
}
