<?php

namespace Tests\Feature;

use App\Catalog\Application\Command\CategoryDeletion;
use App\Catalog\Application\Command\DeleteCategoryTarget;
use App\Catalog\Application\Command\ManageCategoriesHandler;
use App\Catalog\Application\Command\SaveCategoryData;
use App\Catalog\Application\Contract\CategoryMutationParticipant;
use App\Catalog\Application\Service\CategoryMutationParticipants;
use App\Catalog\Domain\Event\CategoryCreated;
use App\Catalog\Domain\Event\CategoryDeleted;
use App\Catalog\Domain\Event\CategoryMoved;
use App\Catalog\Domain\Event\CategoryUpdated;
use App\Catalog\Infrastructure\CategoryHierarchy;
use App\Catalog\Infrastructure\CategoryQueries;
use App\Catalog\Infrastructure\Persistence\CategoryMapper;
use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Catalog\Infrastructure\Persistence\CategoryTranslationRecord;
use App\Http\Middleware\HandleInertiaRequests;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\Persistence\LocaleRecord;
use App\Media\Infrastructure\Persistence\MediaFileRecord;
use App\Storefront\Infrastructure\PublicMenus;
use App\Website\Infrastructure\MenuAdministration;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class CategoryTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private UserRecord $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // Single-language behavior remains supported; strict multi-language saves have dedicated coverage.
        LocaleRecord::where('code', '<>', 'en')->update(['is_active' => false]);
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actor = $this->staff(['admin.access', 'categories.view', 'categories.create', 'categories.update', 'categories.delete']);
        $this->actingAs($this->actor);
        Storage::fake('local');
    }

    /** @return array<string, mixed> */
    private function data(string $name = 'Technology', ?string $parent = null, int $position = 1): array
    {
        return ['parent_id' => $parent, 'position' => $position, 'status' => 'active', 'translations' => ['en' => ['name' => $name, 'slug' => '', 'description' => '', 'seo_title' => '', 'seo_description' => '', 'image_alt' => '']], 'remove_image' => false];
    }

    public function test_rich_category_descriptions_are_sanitized_on_server_requests(): void
    {
        $fields = $this->data('Safe rich description');
        $fields['translations']['en']['description'] = '<h2>Details</h2><p onclick="bad()"><strong>Safe</strong><script>bad()</script><a href="javascript:bad()">Link</a></p>';
        $this->post('/admin/categories', $fields)->assertSessionHasNoErrors();
        $description = CategoryRecord::whereNull('system_key')->sole()->translations()->sole()->description;
        self::assertStringContainsString('<strong>Safe</strong>', $description);
        self::assertStringNotContainsString('onclick', $description);
        self::assertStringNotContainsString('<script', $description);
        self::assertStringNotContainsString('javascript:', $description);
    }

    private function createCategory(string $name = 'Technology', ?string $parent = null, int $position = 1): CategoryRecord
    {
        $response = $this->post('/admin/categories', $this->data($name, $parent, $position))->assertSessionHasNoErrors();
        $category = CategoryRecord::whereHas('translations', fn ($q) => $q->where('name', $name))->sole();
        // Saving opens the category's editor.
        $response->assertRedirect('/admin/categories/'.$category->id.'/edit');

        return $category;
    }

    public function test_sitemap_exclusion_saves_with_category_without_rewriting_children(): void
    {
        $category = $this->createCategory();
        $child = $this->createCategory('Child', (string) $category->id);
        self::assertFalse($category->exclude_from_sitemap);
        $this->put('/admin/categories/'.$category->id, [...$this->editData($category), 'exclude_from_sitemap' => true])->assertSessionHasNoErrors();
        self::assertTrue($category->fresh()->exclude_from_sitemap);
        self::assertFalse($child->fresh()->exclude_from_sitemap);
        $this->get('/admin/categories/'.$category->id.'/edit')->assertInertia(fn (Assert $page) => $page->where('category.excludeFromSitemap', true));
        $this->put('/admin/categories/'.$category->id, $this->editData($category))->assertSessionHasNoErrors();
        self::assertTrue($category->fresh()->exclude_from_sitemap);
        $this->put('/admin/categories/'.$category->id, [...$this->editData($category), 'exclude_from_sitemap' => 'invalid'])->assertSessionHasErrors('exclude_from_sitemap');
        $this->put('/admin/categories/'.$category->id, [...$this->editData($category), 'exclude_from_sitemap' => false])->assertSessionHasNoErrors();
        self::assertFalse($category->fresh()->exclude_from_sitemap);
        $this->flushSession();
        $this->actingAs($this->staff(['admin.access', 'categories.view']))->put('/admin/categories/'.$category->id, [...$this->editData($category), 'exclude_from_sitemap' => true])->assertForbidden();
        self::assertFalse($category->fresh()->exclude_from_sitemap);
    }

    /** @return array<string, mixed> */
    private function editData(CategoryRecord $category): array
    {
        return ['parent_id' => $category->parent_id, 'position' => $category->position, 'status' => $category->status, 'version' => $category->fresh()->lock_version, 'translations' => [], 'remove_image' => false];
    }

    private function singleLocale(string $code): void
    {
        LocaleRecord::where('is_default', true)->update(['is_default' => false]);
        LocaleRecord::query()->update(['is_active' => false]);
        LocaleRecord::where('code', $code)->update(['is_active' => true, 'is_default' => true]);
    }

    public function test_root_child_and_arbitrary_depth_paths_and_leaf_detection(): void
    {
        $ids = [];
        $parent = null;
        for ($depth = 0; $depth < 20; $depth++) {
            $category = $this->createCategory('Level '.$depth, $parent);
            $parent = (string) $category->id;
            $ids[] = $parent;
        }
        $hierarchy = app(CategoryHierarchy::class);
        self::assertSame($ids, $hierarchy->pathIds($parent));
        self::assertEqualsCanonicalizing($ids, $hierarchy->descendantIds($ids[0]));
        self::assertFalse($hierarchy->isLeaf($ids[0]));
        self::assertTrue($hierarchy->isLeaf($parent));
        self::assertTrue(Schema::hasTable('products'));
        self::assertTrue(Schema::hasTable('product_translations'));
        $this->get('/admin/categories')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Categories/Index')->has('branch.data', 2)->where('branch.data.1.name', 'Level 0')->where('branch.data.1.childrenCount', 1));
    }

    public function test_translations_and_localized_seo_are_independent_and_exact_editor_does_not_copy_fallback(): void
    {
        $category = $this->createCategory('Gaming Chairs');
        $this->singleLocale('el');
        $this->get('/admin/categories/'.$category->id.'/edit')->assertInertia(fn (Assert $p) => $p->where('category.displayName', 'Gaming Chairs')->missing('category.translations.el')->missing('category.translations.en'));
        $this->put('/admin/categories/'.$category->id, [...$this->editData($category), 'translations' => ['el' => ['name' => 'Καρέκλες Gaming', 'slug' => 'gaming-karekles', 'seo_title' => 'Ελληνικό SEO', 'description' => 'Περιγραφή']]])->assertSessionHasNoErrors();
        $this->get('/admin/categories/'.$category->id.'/edit')->assertInertia(fn (Assert $p) => $p->where('category.displayName', 'Καρέκλες Gaming'));
        $greek = $category->translations()->whereHas('locale', fn ($q) => $q->where('code', 'el'))->sole();
        $this->singleLocale('en');
        $this->put('/admin/categories/'.$category->id, [...$this->editData($category), 'translations' => ['en' => ['name' => 'Updated English', 'slug' => 'updated-english', 'description' => '']]])->assertSessionHasNoErrors();
        self::assertSame('Καρέκλες Gaming', $greek->fresh()->name);
        self::assertSame('Ελληνικό SEO', $greek->fresh()->seo_title);
        $this->singleLocale('el');
        $this->put('/admin/categories/'.$category->id, [...$this->editData($category), 'translations' => ['el' => ['name' => 'Νέο όνομα', 'slug' => 'neo-onoma', 'description' => '']]])->assertSessionHasNoErrors();
        self::assertSame('Updated English', $category->translations()->whereHas('locale', fn ($q) => $q->where('code', 'en'))->sole()->name);
        self::assertSame('', $greek->fresh()->description);
        $this->singleLocale('en');
        $other = $this->createCategory('No Greek');
        $this->get('/admin/categories/'.$other->id.'/edit')->assertInertia(fn (Assert $p) => $p->where('category.translations.en.name', 'No Greek')->missing('category.translations.el'));
        $entity = app(CategoryMapper::class)->toDomain($category->load('translations.locale'));
        self::assertSame('Νέο όνομα', $entity->translation('el')->fields['name']);
        self::assertNull($entity->translation('de'));
    }

    public function test_slug_normalization_uniqueness_and_future_locale_expansion(): void
    {
        $category = $this->createCategory('Gaming Chairs');
        self::assertSame('gaming-chairs', $category->translations()->sole()->slug);
        $this->post('/admin/categories', $this->data('Gaming Chairs'))->assertSessionHasErrors('translations.en.slug');
        $data = $this->data('Other');
        $this->singleLocale('el');
        $data['translations'] = ['el' => ['name' => 'Άλλο', 'slug' => 'gaming-chairs']];
        $this->post('/admin/categories', $data)->assertSessionHasNoErrors();
        $this->singleLocale('fr');
        $this->put('/admin/categories/'.$category->id, [...$this->editData($category), 'translations' => ['fr' => ['name' => 'Chaises', 'slug' => ' Chaises  Neu ']]])->assertSessionHasNoErrors();
        self::assertSame('chaises-neu', $category->translations()->whereHas('locale', fn ($q) => $q->where('code', 'fr'))->sole()->slug);
    }

    public function test_invalid_missing_inactive_and_path_like_translation_inputs_are_rejected(): void
    {
        $this->post('/admin/categories', [...$this->data(), 'translations' => []])->assertSessionHasErrors('translations.en');
        $this->post('/admin/categories', [...$this->data(), 'translations' => ['en' => []]])->assertSessionHasErrors('translations.en');
        $this->post('/admin/categories', [...$this->data(), 'translations' => ['en' => ['name' => '', 'slug' => '']]])->assertSessionHasErrors('translations.en.name');
        foreach (['../el', 'fr'] as $code) {
            $data = $this->data();
            $data['translations'][$code] = ['name' => 'No', 'slug' => 'no'];
            $this->post('/admin/categories', $data)->assertSessionHasErrors('translations');
        }
        LocaleRecord::where('code', 'el')->update(['is_active' => false]);
        $data = $this->data();
        $data['translations']['el'] = ['name' => 'No', 'slug' => 'no'];
        $this->post('/admin/categories', $data)->assertSessionHasErrors('translations');
    }

    public function test_move_updates_all_descendant_paths_and_rejects_cycles_and_self_parenting(): void
    {
        $a = $this->createCategory('Technology');
        $b = $this->createCategory('Gaming', (string) $a->id);
        $c = $this->createCategory('Chairs', (string) $b->id);
        $other = $this->createCategory('Home', null, 5);
        foreach ([$a, $b, $c] as $invalid) {
            $this->put('/admin/categories/'.$a->id, [...$this->editData($a), 'parent_id' => (string) $invalid->id])->assertSessionHasErrors('category');
        }
        $this->put('/admin/categories/'.$b->id, [...$this->editData($b), 'parent_id' => (string) $other->id])->assertSessionHasNoErrors();
        self::assertSame([(string) $other->id, (string) $b->id, (string) $c->id], app(CategoryHierarchy::class)->pathIds((string) $c->id));
        $this->get('/admin/categories/'.$other->id.'/edit')->assertInertia(fn (Assert $p) => $p->where('parentOptions', fn ($options) => ! collect($options)->contains('id', (string) $b->id) && ! collect($options)->contains('id', (string) $c->id)));
    }

    public function test_numeric_positions_shift_collisions_preserve_gaps_and_reject_stale_forms(): void
    {
        $a = $this->createCategory('Zebra', null, 1);
        $b = $this->createCategory('Alpha', null, 1);
        self::assertSame(2, $a->fresh()->position);
        $this->get('/admin/categories')->assertInertia(fn (Assert $p) => $p->where('branch.data.1.name', 'Alpha')->where('branch.data.2.name', 'Zebra'));
        $this->put('/admin/categories/'.$a->id, [...$this->editData($a), 'position' => 20])->assertSessionHasNoErrors();
        self::assertSame(20, $a->fresh()->position);
        $this->put('/admin/categories/'.$a->id, [...$this->editData($a), 'version' => 1])->assertSessionHasErrors('category');
        $this->delete('/admin/categories/'.$b->id, ['version' => $b->fresh()->lock_version])->assertSessionHasNoErrors();
        self::assertSame(20, $a->fresh()->position);
        foreach ([0, -1, 1.5, 2147483648] as $position) {
            $this->post('/admin/categories', [...$this->data('Invalid'), 'position' => $position])->assertSessionHasErrors('position');
        }
    }

    public function test_move_into_occupied_sibling_position_is_atomic(): void
    {
        $root = $this->createCategory('Root');
        $child = $this->createCategory('Child', (string) $root->id);
        $other = $this->createCategory('Other root', null, 5);
        $this->put('/admin/categories/'.$other->id, [...$this->editData($other), 'parent_id' => (string) $root->id, 'position' => 1])->assertSessionHasNoErrors();
        self::assertSame(1, $other->fresh()->position);
        self::assertSame(2, $child->fresh()->position);
    }

    public function test_leaf_deletion_cascades_only_owned_translations_and_parent_deletion_is_blocked(): void
    {
        $parent = $this->createCategory('Parent');
        $child = $this->createCategory('Child', (string) $parent->id);
        $this->get('/admin/categories/'.$parent->id.'/edit')->assertInertia(fn (Assert $p) => $p->where('category.childrenCount', 1));
        $this->get('/admin/categories/'.$child->id.'/edit')->assertInertia(fn (Assert $p) => $p->where('category.childrenCount', 0));
        $this->delete('/admin/categories/'.$parent->id, ['version' => $parent->lock_version])->assertSessionHasErrors('category');
        $this->delete('/admin/categories/'.$child->id, ['version' => $child->lock_version])->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('categories', ['id' => $child->id]);
        $this->assertDatabaseMissing('category_translations', ['category_id' => $child->id]);
        $this->delete('/admin/categories/'.$parent->id, ['version' => $parent->fresh()->lock_version])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('categories', 1);
    }

    public function test_bulk_deletion_removes_selected_leaf_subcategories_and_preserves_parent(): void
    {
        $parent = $this->createCategory('Bulk parent');
        $first = $this->createCategory('First leaf', (string) $parent->id);
        $second = $this->createCategory('Second leaf', (string) $parent->id);

        $this->delete('/admin/categories/bulk', ['targets' => [
            ['id' => $first->id, 'version' => $first->fresh()->lock_version],
            ['id' => $second->id, 'version' => $second->fresh()->lock_version],
        ]])->assertSessionHasNoErrors()->assertRedirect('/admin/categories');

        $this->assertDatabaseHas('categories', ['id' => $parent->id]);
        $this->assertDatabaseMissing('categories', ['id' => $first->id]);
        $this->assertDatabaseMissing('categories', ['id' => $second->id]);
        $this->assertDatabaseMissing('category_translations', ['category_id' => $first->id]);
        $this->assertDatabaseMissing('category_translations', ['category_id' => $second->id]);
    }

    public function test_bulk_deletion_rejects_roots_parents_duplicates_and_stale_versions_without_partial_changes(): void
    {
        $root = $this->createCategory('Bulk root');
        $leaf = $this->createCategory('Safe leaf', (string) $root->id);
        $branch = $this->createCategory('Branch', (string) $root->id);
        $this->createCategory('Branch child', (string) $branch->id);
        $other = $this->createCategory('Other leaf', (string) $root->id);
        $target = ['id' => $leaf->id, 'version' => $leaf->fresh()->lock_version];

        foreach ([
            ['id' => $root->id, 'version' => $root->lock_version],
            ['id' => $branch->id, 'version' => $branch->lock_version],
            ['id' => $other->id, 'version' => 999],
            ['id' => 9999999, 'version' => 1],
            ['id' => CategoryRecord::where('system_key', 'uncategorized')->sole()->id, 'version' => 1],
        ] as $invalid) {
            $this->delete('/admin/categories/bulk', ['targets' => [$target, $invalid]])->assertSessionHasErrors('targets');
            $this->assertDatabaseHas('categories', ['id' => $leaf->id]);
        }

        $this->delete('/admin/categories/bulk', ['targets' => [$target, $target]])->assertSessionHasErrors('targets.1.id');
        $this->assertDatabaseHas('categories', ['id' => $leaf->id]);
    }

    public function test_bulk_deletion_participant_failure_rolls_back_prior_deletions(): void
    {
        $root = $this->createCategory('Veto root');
        $first = $this->createCategory('First veto leaf', (string) $root->id);
        $second = $this->createCategory('Second veto leaf', (string) $root->id);
        $participant = new class((string) $second->id) implements CategoryMutationParticipant
        {
            public function __construct(private string $vetoId) {}

            public function beforeParentUsed(string $parentId): void {}

            public function beforeDeletion(CategoryDeletion $deletion): void
            {
                if ($deletion->categoryId === $this->vetoId) {
                    throw new RuntimeException('Participant veto');
                }
            }
        };
        app(CategoryMutationParticipants::class)->add('test.bulk-veto', $participant);

        try {
            app(ManageCategoriesHandler::class)->deleteMany((string) $this->actor->id, [
                new DeleteCategoryTarget((string) $first->id, $first->fresh()->lock_version),
                new DeleteCategoryTarget((string) $second->id, $second->fresh()->lock_version),
            ]);
            self::fail('Expected participant veto');
        } catch (RuntimeException $failure) {
            self::assertSame('Participant veto', $failure->getMessage());
        }
        $this->assertDatabaseHas('categories', ['id' => $first->id]);
        $this->assertDatabaseHas('categories', ['id' => $second->id]);
    }

    public function test_uncategorized_is_localized_immutable_not_a_parent_and_not_a_normal_leaf(): void
    {
        $system = CategoryRecord::where('system_key', 'uncategorized')->sole();
        self::assertSame('disabled', $system->status);
        self::assertFalse(app(CategoryHierarchy::class)->isLeaf((string) $system->id));
        $this->get('/admin/categories/'.$system->id.'/edit')->assertForbidden();
        $this->put('/admin/categories/'.$system->id, [...$this->data('Renamed'), 'version' => 1])->assertSessionHasErrors('category');
        $this->delete('/admin/categories/'.$system->id, ['version' => 1])->assertSessionHasErrors('category');
        $this->post('/admin/categories', $this->data('Child', (string) $system->id))->assertSessionHasErrors('parent_id');
        $this->actor->preferred_locale_id = LocaleRecord::where('code', 'el')->value('id');
        $this->actor->save();
        $this->get('/admin/categories')->assertInertia(fn (Assert $p) => $p->where('branch.data.0.name', 'Uncategorized')->where('branch.data.0.system', true));
        self::assertSame(LocaleRecord::where('code', 'el')->value('id'), $this->actor->fresh()->preferred_locale_id);
        LocaleRecord::where('code', 'el')->update(['is_active' => true]);
        $this->get('/admin/categories')->assertInertia(fn (Assert $p) => $p->where('branch.data.0.name', 'Χωρίς κατηγορία')->where('branch.data.0.system', true));
    }

    public function test_search_status_filters_fallback_and_paths_use_the_admin_locale(): void
    {
        $root = $this->createCategory('Technology');
        $child = $this->createCategory('Chairs', (string) $root->id);
        $this->singleLocale('el');
        $this->put('/admin/categories/'.$child->id, [...$this->editData($child), 'status' => 'disabled', 'translations' => ['el' => ['name' => 'Καρέκλες', 'slug' => 'karekles']]])->assertSessionHasNoErrors();
        $this->actor->preferred_locale_id = LocaleRecord::where('code', 'el')->value('id');
        $this->actor->save();
        $this->get('/admin/categories?search='.urlencode('Καρέκλες').'&status=disabled')->assertInertia(fn (Assert $p) => $p->has('results.data', 1)->where('results.data.0.name', 'Καρέκλες')->where('results.data.0.path', ['Technology', 'Καρέκλες']));
        $this->get('/admin/categories?search=Chairs')->assertInertia(fn (Assert $p) => $p->has('results.data', 0));
        $this->get('/admin/categories?search=Technology')->assertInertia(fn (Assert $p) => $p->where('results.data.0.translationState', 'fallback')->where('results.data.0.resolvedLocale', 'en'));
        $this->get('/admin/categories?search=%25')->assertInertia(fn (Assert $p) => $p->has('results.data', 0));
        $this->singleLocale('en');
        $this->createCategory('0');
        $this->singleLocale('el');
        $this->get('/admin/categories?search=0')->assertInertia(fn (Assert $p) => $p->where('filtered', true)->has('results.data', 1)->where('results.data.0.name', '0'));
        $this->get('/admin/categories?status=unknown')->assertSessionHasErrors('status');
    }

    /** @return array<string, array{string|null}> */
    public static function inertiaAssetVersions(): array
    {
        return ['without frontend build' => [null], 'with frontend build' => ['category-test-assets']];
    }

    #[DataProvider('inertiaAssetVersions')]
    public function test_tree_children_are_lazy_paginated_and_queries_do_not_grow_per_row(?string $assetVersion): void
    {
        $this->partialMock(HandleInertiaRequests::class, function (MockInterface $mock) use ($assetVersion): void {
            $mock->shouldReceive('version')->andReturn($assetVersion);
        });
        $root = $this->createCategory('Root');
        for ($i = 1; $i <= 43; $i++) {
            DB::table('categories')->insert(['parent_id' => $root->id, 'position' => $i, 'status' => 'active', 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->get('/admin/categories?parent='.$root->id.'&page=2', ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $assetVersion, 'X-Inertia-Partial-Component' => 'Admin/Categories/Index', 'X-Inertia-Partial-Data' => 'branch'])->assertOk()->assertJsonCount(3, 'props.branch.data')->assertJsonPath('props.branch.lastPage', 2);
        $queries = app(CategoryQueries::class);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $queries->branch((string) $root->id);
        self::assertLessThanOrEqual(5, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    public function test_every_category_boundary_enforces_capabilities_and_csrf(): void
    {
        $category = $this->createCategory();
        $this->flushSession();
        $this->actingAs($this->staff());
        foreach (['/admin/categories', '/admin/categories/create', '/admin/categories/'.$category->id.'/edit', '/admin/categories/'.$category->id.'/image'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post('/admin/categories', $this->data())->assertForbidden();
        $this->put('/admin/categories/'.$category->id, $this->editData($category))->assertForbidden();
        $this->delete('/admin/categories/'.$category->id, ['version' => 1])->assertForbidden();
        $this->delete('/admin/categories/bulk', ['targets' => [['id' => $category->id, 'version' => 1]]])->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->staff(['admin.access', 'categories.view']));
        $this->get('/admin/categories')->assertOk();
        $this->get('/admin/categories/create')->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->actor);
        $this->app->instance('env', 'production');
        $this->post('/admin/categories', $this->data())->assertStatus(419);
        $this->put('/admin/categories/'.$category->id, $this->editData($category))->assertStatus(419);
        $this->delete('/admin/categories/'.$category->id, ['version' => 1])->assertStatus(419);
        $this->delete('/admin/categories/bulk', ['targets' => [['id' => $category->id, 'version' => 1]]])->assertStatus(419);
    }

    public function test_direct_application_use_rechecks_authority_without_http(): void
    {
        $actor = $this->staff();
        $this->expectException(AuthorizationException::class);
        app(ManageCategoriesHandler::class)->save((string) $actor->id, null, new SaveCategoryData(null, 1, 'active', []));
    }

    public function test_deletion_participant_runs_before_delete_and_failure_rolls_back_all_sql(): void
    {
        $category = $this->createCategory();
        $participant = new class implements CategoryMutationParticipant
        {
            public ?CategoryDeletion $received = null;

            public function beforeParentUsed(string $parentId): void {}

            public function beforeDeletion(CategoryDeletion $deletion): void
            {
                $this->received = $deletion;
                DB::table('category_translations')->where('category_id', $deletion->categoryId)->update(['description' => 'Must roll back']);
                throw new RuntimeException('Participant veto');
            }
        };
        app(CategoryMutationParticipants::class)->add('test.category-references', $participant);
        try {
            app(ManageCategoriesHandler::class)->delete((string) $this->actor->id, (string) $category->id, 1);
            self::fail('Expected transaction veto');
        } catch (RuntimeException $failure) {
            self::assertSame('Participant veto', $failure->getMessage());
        }
        self::assertSame((string) $category->id, $participant->received->categoryId);
        self::assertSame((string) CategoryRecord::where('system_key', 'uncategorized')->sole()->id, $participant->received->uncategorizedId);
        self::assertSame('', $category->translations()->sole()->description);
        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    public function test_meaningful_events_announce_successful_changes(): void
    {
        Event::fake([CategoryCreated::class, CategoryUpdated::class, CategoryMoved::class, CategoryDeleted::class]);
        $root = $this->createCategory('Root');
        $child = $this->createCategory('Child');
        $this->put('/admin/categories/'.$child->id, [...$this->editData($child), 'parent_id' => (string) $root->id])->assertSessionHasNoErrors();
        $this->delete('/admin/categories/'.$child->id, ['version' => $child->fresh()->lock_version])->assertSessionHasNoErrors();
        Event::assertDispatchedTimes(CategoryCreated::class, 2);
        Event::assertDispatched(CategoryUpdated::class, fn ($e) => $e->categoryId === (string) $child->id);
        Event::assertDispatched(CategoryMoved::class, fn ($e) => $e->oldParentId === null && $e->newParentId === (string) $root->id);
        Event::assertDispatched(CategoryDeleted::class, fn ($e) => $e->categoryId === (string) $child->id);
    }

    public function test_parent_participant_can_block_an_assigned_leaf_becoming_a_parent(): void
    {
        $parent = $this->createCategory('Reserved leaf');
        $participant = new class implements CategoryMutationParticipant
        {
            public function beforeParentUsed(string $parentId): void
            {
                throw new RuntimeException('Assigned leaf veto');
            }

            public function beforeDeletion(CategoryDeletion $deletion): void {}
        };
        app(CategoryMutationParticipants::class)->add('test.leaf-membership', $participant);
        $this->withoutExceptionHandling();
        try {
            $this->post('/admin/categories', $this->data('Child', (string) $parent->id));
            self::fail('Expected parent veto');
        } catch (RuntimeException $failure) {
            self::assertSame('Assigned leaf veto', $failure->getMessage());
        }
        self::assertTrue(app(CategoryHierarchy::class)->isLeaf((string) $parent->id));
        $this->assertDatabaseCount('categories', 2);
    }

    public function test_tree_lock_serializes_other_database_sessions_and_events_wait_for_outer_commit(): void
    {
        app(CategoryHierarchy::class)->lock();
        $contender = DB::build(config('database.connections.pgsql'));
        try {
            $contender->beginTransaction();
            self::assertFalse($contender->selectOne('SELECT pg_try_advisory_xact_lock(2142513) AS acquired')->acquired);
        } finally {
            $contender->rollBack();
            $contender->disconnect();
        }

        Event::fake([CategoryCreated::class]);
        DB::beginTransaction();
        $this->createCategory('Committed only');
        Event::assertNotDispatched(CategoryCreated::class);
        DB::commit();
        Event::assertDispatchedTimes(CategoryCreated::class, 1);

        Event::fake([CategoryCreated::class]);
        DB::beginTransaction();
        $this->createCategory('Rolled back');
        DB::rollBack();
        Event::assertNotDispatched(CategoryCreated::class);
        $this->assertDatabaseMissing('category_translations', ['name' => 'Rolled back']);
    }

    public function test_database_uniqueness_is_enforced_and_sql_failure_compensates_new_upload_and_positions(): void
    {
        $first = $this->createCategory('First');
        $second = $this->createCategory('Second', null, 2);
        foreach ([
            fn () => DB::table('categories')->where('id', $second->id)->update(['position' => 1]),
            fn () => DB::table('category_translations')->where('category_id', $second->id)->update(['slug' => 'first']),
        ] as $statement) {
            try {
                DB::transaction(function () use ($statement): void {
                    $statement();
                    DB::statement('SET CONSTRAINTS categories_sibling_position_unique IMMEDIATE');
                });
                self::fail('Expected database uniqueness rejection');
            } catch (QueryException $failure) {
                self::assertSame('23505', $failure->errorInfo[0]);
            }
        }

        // Fail after upload, sibling shifts and category insertion, not just before the write.
        CategoryTranslationRecord::creating(function (): never {
            throw new RuntimeException('Translation persistence failed');
        });
        $this->withoutExceptionHandling();
        try {
            $this->post('/admin/categories', [...$this->data('Rollback'), 'image' => $this->image()]);
            self::fail('Expected persistence failure');
        } catch (RuntimeException $failure) {
            self::assertSame('Translation persistence failed', $failure->getMessage());
        } finally {
            CategoryTranslationRecord::flushEventListeners();
        }
        self::assertSame(1, $first->fresh()->position);
        self::assertSame(2, $second->fresh()->position);
        self::assertSame(1, $first->fresh()->lock_version);
        $this->assertDatabaseCount('categories', 3);
        $this->assertDatabaseCount('media_files', 0);
        self::assertSame([], Storage::disk('local')->allFiles());
    }

    private function image(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('category.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aNXsAAAAASUVORK5CYII='));
    }

    public function test_private_image_upload_replacement_removal_and_authorized_delivery(): void
    {
        $this->post('/admin/categories', [...$this->data(), 'image' => $this->image()])->assertSessionHasNoErrors();
        $category = CategoryRecord::whereNull('system_key')->sole();
        $firstImage = $category->image;
        Storage::disk('local')->assertExists($firstImage->storage_key);
        $this->get('/admin/categories/'.$category->id.'/image')->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->put('/admin/categories/'.$category->id, [...$this->editData($category), 'image' => $this->image()])->assertSessionHasNoErrors();
        self::assertNotSame($firstImage->id, $category->fresh()->image_media_id);
        $this->put('/admin/categories/'.$category->id, [...$this->editData($category), 'remove_image' => true])->assertSessionHasNoErrors();
        self::assertNull($category->fresh()->image_media_id);
        $this->get('/admin/categories/'.$category->id.'/image')->assertNotFound();
        // Detached private metadata is deliberately retained pending the retention workflow.
        self::assertSame(2, MediaFileRecord::count());
    }

    public function test_block_image_is_independent_of_the_category_image(): void
    {
        $this->post('/admin/categories', [...$this->data(), 'image' => $this->image(), 'block_image' => $this->image()])->assertSessionHasNoErrors();
        $category = CategoryRecord::whereNull('system_key')->sole();
        self::assertNotNull($category->block_image_media_id);
        self::assertNotSame($category->image_media_id, $category->block_image_media_id);
        $this->get('/admin/categories/'.$category->id.'/block-image')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->put('/admin/categories/'.$category->id, [...$this->editData($category), 'remove_block_image' => true])->assertSessionHasNoErrors();
        $category = $category->fresh();
        self::assertNull($category->block_image_media_id);
        self::assertNotNull($category->image_media_id);
        $this->get('/admin/categories/'.$category->id.'/block-image')->assertNotFound();
        $this->put('/admin/categories/'.$category->id, [...$this->editData($category), 'block_image' => $this->image(), 'remove_block_image' => true])->assertSessionHasErrors('category');
    }

    public function test_a_refused_save_returns_to_the_editor_on_screen_whatever_else_the_browser_loaded(): void
    {
        $this->post('/admin/categories', [...$this->data(), 'image' => $this->image()])->assertSessionHasNoErrors();
        $this->post('/admin/categories', $this->data('Other', null, 2))->assertSessionHasNoErrors();
        $category = CategoryRecord::whereNull('system_key')->orderBy('id')->first();
        $other = CategoryRecord::whereNull('system_key')->orderBy('id')->get()->last();
        $edit = '/admin/categories/'.$category->id.'/edit';
        $inertia = ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()), 'X-Requested-With' => 'XMLHttpRequest'];
        // Browsers send no Referer (Referrer-Policy: no-referrer), so back() relies on the page the session remembers.
        // A full page load of the list, then an Inertia visit to the editor: the editor is the page on screen.
        $this->get('/admin/categories')->assertOk();
        $this->withHeaders($inertia)->get($edit)->assertOk();
        $this->flushHeaders();
        // The editor's picture, a background JSON fetch and a hover prefetch of another editor do not move it.
        $this->withHeaders(['Sec-Fetch-Dest' => 'image'])->get('/admin/categories/'.$category->id.'/image')->assertOk();
        $this->withHeaders(['Sec-Fetch-Dest' => 'empty'])->get('/admin/categories')->assertOk();
        $this->withHeaders([...$inertia, 'Purpose' => 'prefetch'])->get('/admin/categories/'.$other->id.'/edit')->assertOk();
        $this->flushHeaders();
        $this->put('/admin/categories/'.$category->id, [...$this->editData($category), 'status' => 'not-a-status'])->assertRedirect(url($edit))->assertSessionHasErrors('status');
        // A refused photo returns to the editor with its error, and the saved texts stay as they were.
        $texts = $category->translations()->first()->only(['name', 'slug', 'seo_title', 'seo_description', 'meta_keywords']);
        $this->put('/admin/categories/'.$category->id, [...$this->editData($category), 'menu_icon' => UploadedFile::fake()->createWithContent('icon.png', 'not an image')])->assertRedirect(url($edit))->assertSessionHasErrors('menu_icon');
        self::assertSame($texts, $category->translations()->first()->only(['name', 'slug', 'seo_title', 'seo_description', 'meta_keywords']));
        // A full page load still counts as the page on screen.
        $this->get('/admin/categories')->assertOk();
        $this->put('/admin/categories/'.$category->id, [...$this->editData($category), 'status' => 'not-a-status'])->assertRedirect(url('/admin/categories'));
    }

    public function test_a_category_entry_of_a_manual_menu_prefers_the_menu_icon_then_its_own_picture_then_the_category_image(): void
    {
        $this->post('/admin/categories', [...$this->data('Phones'), 'image' => $this->image(), 'menu_icon' => $this->image()])->assertSessionHasNoErrors();
        $this->post('/admin/categories', [...$this->data('Tablets', null, 2), 'image' => $this->image()])->assertSessionHasNoErrors();
        $this->post('/admin/categories', [...$this->data('Cables', null, 3), 'image' => $this->image()])->assertSessionHasNoErrors();
        $this->post('/admin/categories', [...$this->data('Chargers', null, 4)])->assertSessionHasNoErrors();
        $byName = CategoryRecord::whereNull('system_key')->get()->keyBy(fn (CategoryRecord $c) => $c->translations->firstWhere('name', '!=', '')->name);
        $admin = app(MenuAdministration::class);
        $staff = (string) $this->staff(super: true)->id;
        $menu = $admin->save($staff, null, ['status' => 'active', 'placement' => 'header', 'source' => 'manual', 'translations' => ['en' => ['name' => 'Shop']]]);
        $entry = fn (string $name, ?UploadedFile $own = null) => $admin->item($staff, $menu, null, ['version' => (int) DB::table('website_menus')->where('id', $menu)->value('lock_version'), 'parent_id' => null, 'status' => 'active', 'position' => 10, 'kind' => 'category', 'url' => null, 'category_id' => $byName[$name]->id, 'generate_children' => false, 'new_window' => false, 'translations' => ['en' => ['name' => $name, 'description' => '', 'image_alt' => '']]], $own);
        // Every entry has a picture of its own, as the demo menus do.
        foreach (['Phones', 'Tablets', 'Cables', 'Chargers'] as $name) {
            $entry($name, $this->image());
        }
        $nodes = collect(app(PublicMenus::class)->trees([$menu], 'en', null)[$menu])->keyBy('name');
        $own = fn (string $name): string => '/storefront/media/menu-item/'.DB::table('website_menu_items')->where('category_id', $byName[$name]->id)->value('id').'/'.DB::table('website_menu_items')->where('category_id', $byName[$name]->id)->value('image_media_id');
        $category = fn (string $name, string $column): string => '/storefront/media/category/'.$byName[$name]->id.'/'.$byName[$name]->$column;
        // The menu icon wins over the entry's own picture; without one the entry's picture stays.
        self::assertSame($category('Phones', 'menu_icon_media_id'), $nodes['Phones']['image']['url']);
        self::assertSame($own('Tablets'), $nodes['Tablets']['image']['url']);
        self::assertSame($own('Chargers'), $nodes['Chargers']['image']['url']);
        // An entry without a picture of its own falls back to the category image.
        DB::table('website_menu_items')->where('category_id', $byName['Cables']->id)->update(['image_media_id' => null]);
        $nodes = collect(app(PublicMenus::class)->trees([$menu], 'en', null)[$menu])->keyBy('name');
        self::assertSame($category('Cables', 'image_media_id'), $nodes['Cables']['image']['url']);
        $this->flushSession();
        $this->get($nodes['Phones']['image']['url'])->assertOk();
    }

    public function test_menu_icon_saves_replaces_and_removes_without_touching_the_category_image(): void
    {
        $this->post('/admin/categories', [...$this->data(), 'image' => $this->image()])->assertSessionHasNoErrors();
        $category = CategoryRecord::whereNull('system_key')->sole();
        // A category without an icon has none to show or serve.
        self::assertNull($category->menu_icon_media_id);
        $this->get('/admin/categories/'.$category->id.'/edit')->assertInertia(fn (Assert $page) => $page->where('category.menuIconUrl', null)->where('category.menuIconMeta', null));
        $this->get('/admin/categories/'.$category->id.'/menu-icon')->assertNotFound();
        $image = $category->image_media_id;

        // Save: the icon is its own file, loaded for editing and served to staff.
        $this->put('/admin/categories/'.$category->id, [...$this->editData($category), 'menu_icon' => $this->image()])->assertSessionHasNoErrors();
        $category = $category->fresh();
        $icon = $category->menu_icon_media_id;
        self::assertNotNull($icon);
        self::assertNotSame($image, $icon);
        self::assertSame($image, $category->image_media_id);
        $this->get('/admin/categories/'.$category->id.'/edit')->assertInertia(fn (Assert $page) => $page->where('category.menuIconUrl', fn ($url) => str_starts_with((string) $url, '/admin/categories/'.$category->id.'/menu-icon'))->where('category.menuIconMeta.width', 1));
        $this->get('/admin/categories/'.$category->id.'/menu-icon')->assertOk()->assertHeader('Content-Type', 'image/png');

        // Replace: a new file; the category image is still the first one.
        $this->put('/admin/categories/'.$category->id, [...$this->editData($category), 'menu_icon' => $this->image()])->assertSessionHasNoErrors();
        $category = $category->fresh();
        self::assertNotNull($category->menu_icon_media_id);
        self::assertNotSame($icon, $category->menu_icon_media_id);
        self::assertSame($image, $category->image_media_id);

        // A save that does not mention the icon keeps it; a new file and a removal together are refused.
        $this->put('/admin/categories/'.$category->id, $this->editData($category))->assertSessionHasNoErrors();
        self::assertNotNull($category->fresh()->menu_icon_media_id);
        $this->put('/admin/categories/'.$category->id, [...$this->editData($category), 'menu_icon' => $this->image(), 'remove_menu_icon' => true])->assertSessionHasErrors('category');

        // Remove: only the icon goes.
        $this->put('/admin/categories/'.$category->id, [...$this->editData($category), 'remove_menu_icon' => true])->assertSessionHasNoErrors();
        $category = $category->fresh();
        self::assertNull($category->menu_icon_media_id);
        self::assertSame($image, $category->image_media_id);
        $this->get('/admin/categories/'.$category->id.'/menu-icon')->assertNotFound();
        $this->get('/admin/categories/'.$category->id.'/image')->assertOk();
        // Staff without category access cannot read it.
        $this->put('/admin/categories/'.$category->id, [...$this->editData($category), 'menu_icon' => $this->image()])->assertSessionHasNoErrors();
        $this->flushSession();
        $this->actingAs($this->staff(['admin.access']))->get('/admin/categories/'.$category->id.'/menu-icon')->assertForbidden();
    }

    public function test_the_storefront_menu_shows_the_menu_icon_and_falls_back_to_the_category_image(): void
    {
        $this->post('/admin/categories', [...$this->data('Phones'), 'image' => $this->image(), 'menu_icon' => $this->image()])->assertSessionHasNoErrors();
        $this->post('/admin/categories', [...$this->data('Tablets', null, 2), 'image' => $this->image()])->assertSessionHasNoErrors();
        $this->post('/admin/categories', [...$this->data('Cables', null, 3)])->assertSessionHasNoErrors();
        $menu = app(MenuAdministration::class)->save((string) $this->staff(super: true)->id, null, ['status' => 'active', 'placement' => 'header', 'source' => 'categories', 'translations' => ['en' => ['name' => 'Shop']]]);
        $nodes = collect(app(PublicMenus::class)->trees([$menu], 'en', null)[$menu])->keyBy('name');
        $byName = CategoryRecord::whereNull('system_key')->get()->keyBy(fn (CategoryRecord $c) => $c->translations->firstWhere('name', '!=', '')->name);
        $imageOf = fn (string $name): ?string => $nodes[$name]['image']['url'] ?? null;
        self::assertSame('/storefront/media/category/'.$byName['Phones']->id.'/'.$byName['Phones']->menu_icon_media_id, $imageOf('Phones'));
        self::assertSame('/storefront/media/category/'.$byName['Tablets']->id.'/'.$byName['Tablets']->image_media_id, $imageOf('Tablets'));
        self::assertNull($imageOf('Cables'));
        // Both pictures are public through the storefront media route.
        $this->flushSession();
        $this->get($imageOf('Phones'))->assertOk();
        $this->get('/storefront/media/category/'.$byName['Phones']->id.'/'.$byName['Phones']->image_media_id)->assertOk();
    }

    public function test_banner_image_is_independent_of_the_other_images(): void
    {
        $this->post('/admin/categories', [...$this->data(), 'block_image' => $this->image(), 'banner_image' => $this->image()])->assertSessionHasNoErrors();
        $category = CategoryRecord::whereNull('system_key')->sole();
        self::assertNotNull($category->banner_image_media_id);
        self::assertNotSame($category->block_image_media_id, $category->banner_image_media_id);
        $this->get('/admin/categories/'.$category->id.'/edit')->assertInertia(fn (Assert $page) => $page->where('category.bannerImageUrl', fn ($url) => str_starts_with((string) $url, '/admin/categories/'.$category->id.'/banner-image')));
        $this->get('/admin/categories/'.$category->id.'/banner-image')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->put('/admin/categories/'.$category->id, [...$this->editData($category), 'remove_banner_image' => true])->assertSessionHasNoErrors();
        $category = $category->fresh();
        self::assertNull($category->banner_image_media_id);
        self::assertNotNull($category->block_image_media_id);
        $this->get('/admin/categories/'.$category->id.'/banner-image')->assertNotFound();
        $this->put('/admin/categories/'.$category->id, [...$this->editData($category), 'banner_image' => $this->image(), 'remove_banner_image' => true])->assertSessionHasErrors('category');
    }

    public function test_multipart_core_only_edit_preserves_all_translations_when_empty_object_is_omitted(): void
    {
        $category = $this->createCategory();
        $data = $this->editData($category);
        unset($data['translations']);
        $this->post('/admin/categories/'.$category->id, [...$data, '_method' => 'put', 'position' => 9, 'image' => $this->image()])->assertSessionHasNoErrors();
        self::assertSame(9, $category->fresh()->position);
        self::assertNotNull($category->fresh()->image_media_id);
        self::assertSame('Technology', $category->translations()->sole()->name);
        $this->assertDatabaseCount('category_translations', 1);
    }

    public function test_unsafe_images_conflicting_image_actions_and_storage_failures_do_not_change_categories(): void
    {
        foreach (['payload.svg' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'payload.png' => '<?php echo "bad";'] as $name => $contents) {
            $this->post('/admin/categories', [...$this->data(), 'image' => UploadedFile::fake()->createWithContent($name, $contents)])->assertSessionHasErrors('image');
        }
        $this->post('/admin/categories', [...$this->data(), 'image' => $this->image(), 'remove_image' => true])->assertSessionHasErrors('category');
        $this->assertDatabaseCount('categories', 1);
        $this->assertDatabaseCount('media_files', 0);
        Storage::shouldReceive('disk')->with('local')->twice()->andReturn(new class
        {
            public function put(): bool
            {
                return false;
            }

            public function delete(string $key): bool
            {
                return true;
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->post('/admin/categories', [...$this->data(), 'image' => $this->image()]);
            self::fail('Expected storage failure');
        } catch (RuntimeException $failure) {
            self::assertSame('Unable to store private image.', $failure->getMessage());
        }
        $this->assertDatabaseCount('categories', 1);
    }

    public function test_database_itself_rejects_self_parent_cycles_orphans_invalid_positions_and_system_changes(): void
    {
        $a = $this->createCategory('A');
        $b = $this->createCategory('B', (string) $a->id);
        $system = CategoryRecord::where('system_key', 'uncategorized')->sole();
        $statements = [
            fn () => DB::table('categories')->where('id', $a->id)->update(['parent_id' => $a->id]),
            fn () => DB::table('categories')->where('id', $a->id)->update(['parent_id' => $b->id]),
            fn () => DB::table('categories')->where('id', $a->id)->update(['parent_id' => 999999]),
            fn () => DB::table('categories')->where('id', $a->id)->update(['position' => 0]),
            fn () => DB::table('categories')->where('id', $a->id)->update(['parent_id' => $system->id]),
            fn () => DB::table('categories')->where('id', $system->id)->update(['status' => 'active']),
            fn () => DB::table('categories')->where('id', $system->id)->delete(),
            fn () => DB::table('categories')->where('id', $a->id)->delete(),
        ];
        foreach ($statements as $statement) {
            try {
                DB::transaction($statement);
                self::fail('Expected relational integrity rejection');
            } catch (QueryException $failure) {
                self::assertContains($failure->errorInfo[0], ['23503', '23514', '23001']);
            }
        }
        self::assertSame([(string) $a->id, (string) $b->id], app(CategoryHierarchy::class)->pathIds((string) $b->id));
    }

    public function test_category_migration_round_trip_preserves_existing_identity_and_roles(): void
    {
        $searchPath = DB::scalar('SHOW search_path');
        try {
            DB::transaction(function (): void {
                DB::statement('CREATE SCHEMA category_round_trip');
                DB::statement('SET LOCAL search_path TO category_round_trip');
                $authentication = require database_path('migrations/2026_10_08_000100_create_authentication_tables.php');
                $authorization = require database_path('migrations/2026_10_08_000200_create_authorization_and_localization_tables.php');
                $migration = require database_path('migrations/2026_10_08_000300_create_categories_and_media_tables.php');
                $authentication->up();
                $authorization->up();
                $id = DB::table('users')->insertGetId(['name' => 'Existing staff', 'email' => 'staff@example.test', 'password' => 'existing-password-hash']);
                $role = DB::table('roles')->insertGetId(['name' => 'Staff', 'slug' => 'staff']);
                $permission = DB::table('permissions')->insertGetId(['name' => 'admin.access']);
                DB::table('role_user')->insert(['role_id' => $role, 'user_id' => $id]);
                DB::table('permission_role')->insert(['permission_id' => $permission, 'role_id' => $role]);
                $tables = ['users', 'roles', 'permissions', 'role_user', 'permission_role', 'locales'];
                $before = [];
                foreach ($tables as $table) {
                    $before[$table] = DB::table($table)->get()->toArray();
                }

                $migration->up();
                $migration->down();
                foreach (['categories', 'category_translations', 'media_files'] as $table) {
                    self::assertFalse(Schema::hasTable($table));
                }
                $migration->up();
                self::assertSame('uncategorized', DB::table('categories')->sole()->system_key);
                self::assertSame(1, DB::table('categories')->count());
                foreach ($tables as $table) {
                    self::assertEquals($before[$table], DB::table($table)->get()->toArray(), $table);
                }
                $migration->down();
                $authorization->down();
                $authentication->down();
                DB::statement('DROP SCHEMA category_round_trip');
            });
        } finally {
            DB::select("SELECT set_config('search_path', ?, true)", [$searchPath]);
        }
    }

    public function test_new_metadata_and_age_settings_are_localized_independent_and_preserved_by_older_inputs(): void
    {
        $data = [...$this->data('Restricted'), 'age_verification' => true, 'age_limit' => 18];
        $data['translations']['en'] += ['meta_keywords' => 'adult, restricted', 'age_warning_message' => 'You must be 18 or older.'];
        $this->post('/admin/categories', $data)->assertSessionHasNoErrors();
        $category = CategoryRecord::whereNull('system_key')->sole();
        $this->singleLocale('el');
        $this->put('/admin/categories/'.$category->id, [...$this->editData($category), 'translations' => ['el' => ['name' => 'Περιορισμένη', 'slug' => 'periorismeni', 'meta_keywords' => 'ηλικία, περιορισμός', 'age_warning_message' => 'Απαιτείται ηλικία 18 ετών.']]])->assertSessionHasNoErrors();
        $this->get('/admin/categories/'.$category->id.'/edit')->assertInertia(fn (Assert $p) => $p
            ->where('category.ageVerification', true)->where('category.ageLimit', 18)
            ->where('category.translations.el.age_warning_message', 'Απαιτείται ηλικία 18 ετών.'));
        $this->singleLocale('en');
        $this->put('/admin/categories/'.$category->id, [...$this->editData($category), 'translations' => ['en' => ['name' => 'Restricted', 'slug' => 'restricted', 'age_warning_message' => '']]])->assertSessionHasNoErrors();
        $entity = app(CategoryMapper::class)->toDomain($category->fresh()->load('translations.locale'));
        self::assertTrue($entity->ageVerification);
        self::assertSame(18, $entity->ageLimit);
        self::assertSame('', $entity->translation('en')->fields['age_warning_message']);
        self::assertSame('adult, restricted', $entity->translation('en')->fields['meta_keywords']);
        self::assertSame('Απαιτείται ηλικία 18 ετών.', $entity->translation('el')->fields['age_warning_message']);
        self::assertSame('ηλικία, περιορισμός', $entity->translation('el')->fields['meta_keywords']);
    }

    public function test_age_settings_and_new_localized_fields_are_validated(): void
    {
        foreach ([null, 0, -1, 18.5, 'not-an-age', 2147483648] as $age) {
            $this->post('/admin/categories', [...$this->data(), 'age_verification' => true, 'age_limit' => $age])->assertSessionHasErrors('age_limit');
        }
        $this->post('/admin/categories', [...$this->data(), 'age_verification' => 'yes', 'age_limit' => 18])->assertSessionHasErrors('age_verification');
        $data = $this->data();
        $data['translations']['en']['meta_keywords'] = str_repeat('a', 1001);
        $data['translations']['en']['age_warning_message'] = str_repeat('a', 2001);
        $this->post('/admin/categories', $data)->assertSessionHasErrors(['translations.en.meta_keywords', 'translations.en.age_warning_message']);
        $this->assertDatabaseCount('categories', 1);
    }

    public function test_parent_age_values_are_derived_across_all_ancestors_and_update_after_moves(): void
    {
        $root = $this->createCategory('Root age');
        $this->put('/admin/categories/'.$root->id, [...$this->editData($root), 'age_verification' => true, 'age_limit' => 21])->assertSessionHasNoErrors();
        $middle = $this->createCategory('Middle age', (string) $root->id);
        $this->put('/admin/categories/'.$middle->id, [...$this->editData($middle), 'age_verification' => true, 'age_limit' => 18])->assertSessionHasNoErrors();
        $leaf = $this->createCategory('Leaf age', (string) $middle->id);
        $this->get('/admin/categories/'.$leaf->id.'/edit')->assertInertia(fn (Assert $p) => $p
            ->where('category.ageVerification', false)->where('category.ageLimit', null)
            ->where('parentAgeRestriction.verification', true)->where('parentAgeRestriction.ageLimit', 21)
            ->where('parentAgeRestriction.sourceCategoryId', (string) $root->id));
        $this->put('/admin/categories/'.$middle->id, [...$this->editData($middle), 'age_verification' => false, 'age_limit' => 18])->assertSessionHasNoErrors();
        self::assertSame(21, app(CategoryQueries::class)->parentAgeRestriction((string) $middle->id)['ageLimit']);
        $this->put('/admin/categories/'.$middle->id, [...$this->editData($middle), 'parent_id' => null])->assertSessionHasNoErrors();
        self::assertFalse(app(CategoryQueries::class)->parentAgeRestriction((string) $middle->id)['verification']);
        $this->get('/admin/categories/'.$leaf->id.'/edit?selected_parent='.$root->id)->assertInertia(fn (Assert $p) => $p->where('parentAgeRestriction.parentId', (string) $root->id)->where('parentAgeRestriction.ageLimit', 21));
        $this->get('/admin/categories/'.$leaf->id.'/edit?selected_parent=')->assertInertia(fn (Assert $p) => $p->where('parentAgeRestriction.parentId', null)->where('parentAgeRestriction.verification', false)->where('parentAgeRestriction.ageLimit', null));
        self::assertFalse(Schema::hasColumn('categories', 'parent_age_limit'));
        self::assertFalse(Schema::hasColumn('categories', 'parent_age_verification'));
    }

    public function test_database_rejects_invalid_age_settings_and_system_configuration(): void
    {
        $category = $this->createCategory();
        $system = CategoryRecord::whereNotNull('system_key')->sole();
        foreach ([
            fn () => DB::table('categories')->where('id', $category->id)->update(['age_verification' => true, 'age_limit' => null]),
            fn () => DB::table('categories')->where('id', $category->id)->update(['age_limit' => 0]),
            fn () => DB::table('categories')->where('id', $system->id)->update(['age_verification' => true, 'age_limit' => 18]),
            fn () => DB::table('category_translations')->where('category_id', $category->id)->update(['age_warning_message' => str_repeat('a', 2001)]),
        ] as $statement) {
            try {
                DB::transaction($statement);
                self::fail('Expected age constraint rejection');
            } catch (QueryException $failure) {
                self::assertSame('23514', $failure->errorInfo[0]);
            }
        }
        $this->put('/admin/categories/'.$system->id, [...$this->data('Renamed'), 'version' => 1, 'age_verification' => true, 'age_limit' => 18])->assertSessionHasErrors('category');
        self::assertFalse($system->fresh()->age_verification);
    }

    public function test_access_metadata_migration_is_additive_and_round_trips_without_losing_existing_category_data(): void
    {
        $category = $this->createCategory('Existing content');
        $migration = require database_path('migrations/2026_10_08_000400_add_category_access_metadata.php');
        $migration->down();
        self::assertFalse(Schema::hasColumn('categories', 'age_limit'));
        $this->assertDatabaseHas('category_translations', ['category_id' => $category->id, 'name' => 'Existing content', 'slug' => 'existing-content']);
        $migration->up();
        self::assertFalse($category->fresh()->age_verification);
        self::assertNull($category->fresh()->age_limit);
        self::assertSame('', $category->translations()->sole()->meta_keywords);
        $this->assertDatabaseHas('users', ['id' => $this->actor->id]);
        self::assertFalse(Schema::hasColumn('category_translations', 'page_title'));
        self::assertFalse(Schema::hasColumn('category_translations', 'seo_name'));
    }

    public function test_discovery_exclusion_and_customer_groups_are_saved_shown_preserved_and_cleared(): void
    {
        $groups = [];
        foreach (['Retail', 'Wholesale'] as $name) {
            $groups[] = (string) DB::table('customer_groups')->insertGetId(['status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->post('/admin/categories', [...$this->data(), 'exclude_from_discovery' => true, 'customer_groups_submitted' => true, 'customer_groups' => $groups])->assertSessionHasNoErrors();
        $category = CategoryRecord::whereNull('system_key')->sole();
        self::assertTrue($category->exclude_from_discovery);
        $this->get('/admin/categories/'.$category->id.'/edit')->assertInertia(fn (Assert $page) => $page->where('category.excludeFromDiscovery', true)->where('category.customerGroups', $groups)->has('customerGroups', 2));

        // Omitting both fields keeps them (older clients and partial saves).
        $this->put('/admin/categories/'.$category->id, [...$this->editData($category->fresh()), 'position' => 2])->assertSessionHasNoErrors();
        self::assertTrue($category->fresh()->exclude_from_discovery);
        self::assertSame(2, DB::table('category_customer_groups')->where('category_id', $category->id)->count());

        $this->put('/admin/categories/'.$category->id, [...$this->editData($category->fresh()), 'customer_groups' => ['99999']])->assertSessionHasErrors('customer_groups.0');

        // Multipart drops an empty list; the marker alone clears the restriction.
        $this->put('/admin/categories/'.$category->id, [...$this->editData($category->fresh()), 'exclude_from_discovery' => false, 'customer_groups_submitted' => true])->assertSessionHasNoErrors();
        self::assertFalse($category->fresh()->exclude_from_discovery);
        self::assertSame(0, DB::table('category_customer_groups')->where('category_id', $category->id)->count());
        self::assertTrue(DB::table('audit_logs')->where('entity_type', 'category_customer_group')->exists());
    }

    public function test_saving_returns_to_the_tab_it_was_saved_from(): void
    {
        $category = $this->createCategory();
        $this->from('/admin/categories/'.$category->id.'/edit?tab=image')->put('/admin/categories/'.$category->id, $this->editData($category))
            ->assertSessionHasNoErrors()->assertRedirect('/admin/categories/'.$category->id.'/edit?tab=image');
        $this->from('/admin/categories/'.$category->id.'/edit?tab=<x>')->put('/admin/categories/'.$category->id, $this->editData($category->fresh()))
            ->assertRedirect('/admin/categories/'.$category->id.'/edit');
    }

    public function test_the_banner_link_is_optional_safe_and_kept_until_changed(): void
    {
        $category = $this->createCategory();
        $this->put('/admin/categories/'.$category->id, [...$this->editData($category), 'banner_link' => 'javascript:alert(1)'])->assertSessionHasErrors('banner_link');
        $this->put('/admin/categories/'.$category->id, [...$this->editData($category), 'banner_link' => ' /gaming/consoles '])->assertSessionHasNoErrors();
        self::assertSame('/gaming/consoles', $category->fresh()->banner_link);
        $this->get('/admin/categories/'.$category->id.'/edit')->assertInertia(fn (Assert $page) => $page->where('category.bannerLink', '/gaming/consoles'));
        // Leaving the field out keeps it; an empty value removes it.
        $this->put('/admin/categories/'.$category->id, $this->editData($category->fresh()))->assertSessionHasNoErrors();
        self::assertSame('/gaming/consoles', $category->fresh()->banner_link);
        $this->put('/admin/categories/'.$category->id, [...$this->editData($category->fresh()), 'banner_link' => ''])->assertSessionHasNoErrors();
        self::assertNull($category->fresh()->banner_link);
    }
}
