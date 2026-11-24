<?php

namespace Tests\Feature;

use App\Catalog\Application\Command\SaveFeatureData;
use App\Catalog\Application\Command\SaveProductData;
use App\Catalog\Application\Contract\FeatureAdministration;
use App\Catalog\Application\Contract\ProductAdministration;
use App\Catalog\Infrastructure\FeatureDemoSeeder;
use App\Catalog\Infrastructure\FeatureImpactRequired;
use App\Catalog\Infrastructure\FeatureQueries;
use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Catalog\Infrastructure\ProductFeatures;
use App\Catalog\Infrastructure\ProductQueries;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\Persistence\LocaleRecord;
use App\Media\Infrastructure\Persistence\MediaFileRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class FeatureTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private UserRecord $actor;

    private FeatureAdministration $features;

    private ProductAdministration $products;

    private CategoryRecord $root;

    private CategoryRecord $leaf;

    private CategoryRecord $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        DB::table('general_settings')->where('id', 1)->update(['brand_landing_pages' => false]);
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actor = $this->staff(super: true);
        $this->actingAs($this->actor);
        $this->features = app(FeatureAdministration::class);
        $this->products = app(ProductAdministration::class);
        $this->root = CategoryRecord::create(['position' => 1, 'status' => 'active']);
        $this->leaf = CategoryRecord::create(['position' => 1, 'status' => 'active', 'parent_id' => $this->root->id]);
        $this->other = CategoryRecord::create(['position' => 2, 'status' => 'active']);
    }

    private function actor(): string
    {
        return (string) $this->actor->id;
    }

    /** @param array<string, mixed> $changes */
    public function test_definition_group_and_variant_descriptions_share_server_sanitization(): void
    {
        $dirty = '<p onclick="bad()"><strong>Safe</strong><script>bad()</script><a href="javascript:bad()">Link</a></p>';
        $feature = $this->feature('single', ['purpose' => 'landing_pages', 'translations' => ['en' => ['name' => 'Brand', 'description' => $dirty]]]);
        $option = $this->features->option($this->actor(), $feature, null, new SaveFeatureData(['feature_version' => $this->data($feature)['version'], 'action' => 'save', 'translations' => ['en' => ['label' => 'Safe brand', 'description' => $dirty]]]));
        $group = $this->features->group($this->actor(), null, new SaveFeatureData(['action' => 'save', 'translations' => ['en' => ['name' => 'Safe group', 'description' => $dirty]]]));
        foreach ([['feature_translations', 'feature_id', $feature], ['feature_option_translations', 'option_id', $option], ['feature_group_translations', 'group_id', $group]] as [$table, $key, $id]) {
            $description = DB::table($table)->where($key, $id)->value('description');
            self::assertStringContainsString('<strong>Safe</strong>', $description);
            self::assertStringNotContainsString('onclick', $description);
            self::assertStringNotContainsString('<script', $description);
            self::assertStringNotContainsString('javascript:', $description);
        }
    }

    private function feature(string $type = 'text', array $changes = []): string
    {
        return $this->features->save($this->actor(), null, new SaveFeatureData(array_replace(['type' => $type, 'required' => false, 'filterable' => false, 'comparison' => false, 'categories' => [], 'translations' => ['en' => ['name' => 'Material'], 'el' => ['name' => 'Υλικό']]], $changes)));
    }

    /** @return array<string, mixed> */
    private function data(string $id): array
    {
        $f = app(FeatureQueries::class)->definitions([$id])[0];

        return ['version' => $f['version'], 'type' => $f['type'], 'purpose' => $f['purpose'], 'group_id' => $f['group_id'], 'required' => $f['required'], 'filterable' => $f['filterable'], 'comparison' => $f['comparison'], 'unit' => $f['unit'], 'categories' => $f['categories'], 'translations' => (array) $f['translations']];
    }

    /** @param array<string, mixed> $changes */
    private function edit(string $id, array $changes): void
    {
        $this->features->save($this->actor(), $id, new SaveFeatureData(array_replace($this->data($id), $changes)));
    }

    private function option(string $feature, string $name = 'Cotton'): string
    {
        return $this->features->option($this->actor(), $feature, null, new SaveFeatureData(['feature_version' => $this->data($feature)['version'], 'action' => 'save', 'translations' => ['en' => ['label' => $name], 'el' => ['label' => 'Βαμβάκι']]]));
    }

    /** @param array<string, mixed> $extra */
    private function action(string $id, string $action, array $extra = []): void
    {
        $this->features->action($this->actor(), $id, new SaveFeatureData(['version' => $this->data($id)['version'], 'action' => $action, ...$extra]));
    }

    /** @param array<string, mixed> $extra */
    private function optionAction(string $id, string $option, string $action, array $extra = []): void
    {
        $this->features->option($this->actor(), $id, $option, new SaveFeatureData(['feature_version' => $this->data($id)['version'], 'version' => DB::table('feature_options')->where('id', $option)->value('lock_version'), 'action' => $action, 'translations' => [], ...$extra]));
    }

    /** @param callable(array<string, mixed>): void $operation */
    private function confirmed(callable $operation, ?string $impact = null, ?int $count = null): void
    {
        try {
            $operation([]);
            self::fail('Expected explicit impact confirmation');
        } catch (FeatureImpactRequired $e) {
            if ($impact !== null) {
                self::assertSame($count, $e->impact[$impact]);
            }
            $operation(['confirmed' => true, 'impact_token' => $e->token]);
        }
    }

    /** @param callable(): void $operation */
    private function invalid(callable $operation, string $field): void
    {
        try {
            $operation();
            self::fail('Expected '.$field);
        } catch (ValidationException $e) {
            self::assertArrayHasKey($field, $e->errors());
        }
    }

    /** @param array<string, mixed> $values */
    private function product(array $values = [], string $status = 'disabled'): ProductRecord
    {
        $id = $this->products->draft($this->actor());
        $this->products->save($this->actor(), $id, new SaveProductData(1, ['sku' => 'TEST-'.$id, 'price' => '10', 'type' => 'physical', 'status' => $status, 'category_id' => (string) $this->leaf->id, 'translations' => ['en' => ['name' => 'Headphones']], 'features' => $values, 'feature_catalog_version' => app(FeatureQueries::class)->revision()]));

        return ProductRecord::findOrFail($id);
    }

    /** @param array<string, mixed> $changes */
    private function saveProduct(ProductRecord $p, array $changes): void
    {
        $data = app(ProductQueries::class)->editor($p->fresh());
        $data['translations'] = (array) $data['translations'];
        $this->products->save($this->actor(), (string) $p->id, new SaveProductData($data['version'], array_replace($data, $changes)));
    }

    public function test_five_types_drafts_default_locale_completeness_codes_and_standard_purpose(): void
    {
        foreach (['text', 'single', 'number', 'boolean', 'multiple'] as $type) {
            $id = $this->feature($type, ['translations' => ['el' => ['name' => 'Μόνο ελληνικά']]]);
            self::assertTrue(app(FeatureQueries::class)->definitions([$id])[0]['incomplete']);
            self::assertSame('standard', app(FeatureQueries::class)->definitions([$id])[0]['purpose']);
            $this->invalid(fn () => $this->action($id, 'activate'), 'feature');
            $this->invalid(fn () => $this->edit($id, ['translations' => ['en' => ['name' => 'Material', 'slug' => 'material']]]), 'translations.en');
            $this->edit($id, ['translations' => ['en' => ['name' => 'Material']]]);
            if (in_array($type, ['single', 'multiple'], true)) {
                $this->option($id);
            }
            $this->action($id, 'activate');
            self::assertFalse(app(FeatureQueries::class)->definitions([$id])[0]['incomplete']);
        }
        self::assertSame(['FEATURE', 'FEATURE-2', 'FEATURE-3', 'FEATURE-4', 'FEATURE-5'], DB::table('features')->orderBy('id')->pluck('code')->all());
        self::assertSame(['standard'], DB::table('features')->distinct()->pluck('purpose')->all());
        foreach (['slug', 'seo_title', 'seo_description'] as $column) {
            self::assertFalse(Schema::hasColumn('feature_translations', $column));
        }
        $this->invalid(fn () => $this->feature('date'), 'type');
        $this->invalid(fn () => $this->feature('text', ['filterable' => true]), 'filterable');
        $this->invalid(fn () => $this->feature('boolean', ['unit' => 'g']), 'unit');
    }

    public function test_identity_immutable_archived_readonly_and_deletion(): void
    {
        $id = $this->feature('text', ['translations' => ['en' => ['name' => 'Material']]]);
        $this->invalid(fn () => $this->edit($id, ['type' => 'number']), 'type');
        $this->invalid(fn () => $this->edit($id, ['code' => 'OTHER']), 'code');
        $this->edit($id, ['translations' => ['en' => ['name' => 'Fabric']]]);
        self::assertSame('MATERIAL', DB::table('features')->where('id', $id)->value('code'));
        $this->confirmed(fn ($v) => $this->action($id, 'archive', $v));
        $this->invalid(fn () => $this->edit($id, ['unit' => 'kg']), 'unit');
        $this->invalid(fn () => $this->edit($id, ['comparison' => true]), 'status');
        $this->action($id, 'restore');
        $this->confirmed(fn ($v) => $this->action($id, 'delete', $v));
        $this->assertDatabaseHas('feature_audits', ['entity_id' => $id, 'action' => 'delete']);
    }

    public function test_all_required_types_zero_negative_false_and_independent_texts(): void
    {
        $values = [];
        foreach (['text', 'single', 'number', 'boolean', 'multiple'] as $type) {
            $id = $this->feature($type, ['required' => true]);
            $value = match ($type) {
                'text' => ['texts' => ['en' => 'Desktop compatible', 'el' => 'Συμβατό με υπολογιστή']], 'number' => ['number' => '0'], 'boolean' => ['boolean' => '0'], default => ['options' => [$this->option($id)]]
            };
            $values[$id] = ['version' => 0, ...$value];
        }
        $p = $this->product($values, 'active');
        self::assertSame([], app(FeatureQueries::class)->missingForProduct((string) $p->id));
        self::assertSame(0, app(FeatureQueries::class)->remediationCount());
        $number = (string) DB::table('features')->where('type', 'number')->value('id');
        $a = DB::table('product_feature_assignments')->where('product_id', $p->id)->where('feature_id', $number)->first();
        self::assertSame('0.000000', $a->number_value);
        $this->saveProduct($p, ['features' => [$number => ['version' => $a->lock_version, 'number' => '-12.345678']], 'feature_catalog_version' => app(FeatureQueries::class)->revision()]);
        $this->assertDatabaseHas('product_feature_assignments', ['id' => $a->id, 'number_value' => '-12.345678']);
        $this->assertDatabaseHas('product_feature_texts', ['value' => 'Συμβατό με υπολογιστή']);
        $this->invalid(fn () => $this->product([], 'active'), 'features.'.array_key_first($values));
    }

    public function test_unset_empty_choices_malformed_numbers_and_wrong_options_rollback(): void
    {
        $number = $this->feature('number', ['required' => true]);
        $boolean = $this->feature('boolean', ['required' => true]);
        $multi = $this->feature('multiple', ['required' => true]);
        $option = $this->option($multi);
        foreach (['1e2', 'NaN', '1,5', '0.1234567', '123456789012345'] as $bad) {
            $this->invalid(fn () => $this->product([$number => ['version' => 0, 'number' => $bad]]), 'features.'.$number.'.number');
        }
        $this->invalid(fn () => $this->product([$number => ['version' => 0, 'number' => '0'], $boolean => ['version' => 0, 'boolean' => ''], $multi => ['version' => 0, 'options' => []]], 'active'), 'features.'.$boolean);
        $single = $this->feature('single');
        $this->option($single);
        $this->invalid(fn () => $this->product([$single => ['version' => 0, 'options' => [$option]]]), 'features.'.$single);
        $this->assertDatabaseCount('product_feature_assignments', 0);
    }

    public function test_required_change_preview_existing_active_status_and_remediation_clear(): void
    {
        $id = $this->feature('boolean');
        $p = $this->product([], 'active');
        $this->confirmed(fn ($v) => $this->edit($id, ['required' => true, ...$v]), 'missing_products', 1);
        self::assertSame('active', $p->fresh()->status);
        self::assertSame(1, app(FeatureQueries::class)->remediationCount());
        $this->get('/admin/features/remediation')->assertInertia(fn (Assert $page) => $page->has('products.data', 1));
        $this->invalid(fn () => $this->products->lifecycle($this->actor(), (string) $p->id, $p->fresh()->lock_version, 'active'), 'features.'.$id);
        $this->saveProduct($p, ['features' => [$id => ['version' => 0, 'boolean' => '0']], 'feature_catalog_version' => app(FeatureQueries::class)->revision()]);
        self::assertSame(0, app(FeatureQueries::class)->remediationCount());
        $p2 = $this->product();
        DB::table('products')->where('id', $p2->id)->update(['status' => 'active']);
        self::assertSame(1, app(FeatureQueries::class)->remediationCount());
        $this->edit($id, ['required' => false]);
        self::assertSame(0, app(FeatureQueries::class)->remediationCount());
    }

    public function test_descendants_overlap_history_reactivation_and_category_confirmation(): void
    {
        $id = $this->feature('text', ['categories' => [(string) $this->root->id, (string) $this->leaf->id]]);
        $p = $this->product([$id => ['version' => 0, 'texts' => ['en' => 'Saved']]]);
        self::assertCount(1, app(ProductFeatures::class)->editor($p)['definitions']);
        $assignment = DB::table('product_feature_assignments')->sole()->id;
        $this->confirmed(fn ($v) => $this->edit($id, ['categories' => [(string) $this->other->id], ...$v]), 'inactive_products', 1);
        $this->assertDatabaseHas('product_feature_assignments', ['id' => $assignment, 'is_applicable' => false]);
        $this->edit($id, ['categories' => [(string) $this->root->id]]);
        $this->assertDatabaseHas('product_feature_assignments', ['id' => $assignment, 'is_applicable' => true]);
        $this->confirmed(fn ($v) => $this->saveProduct($p, ['category_id' => (string) $this->other->id, 'feature_confirmed' => $v['confirmed'] ?? false, 'feature_impact_token' => $v['impact_token'] ?? null]), 'inactive_values', 1);
        $this->assertDatabaseHas('product_feature_assignments', ['id' => $assignment, 'is_applicable' => false]);
        $this->saveProduct($p, ['category_id' => (string) $this->leaf->id]);
        $this->assertDatabaseHas('product_feature_assignments', ['id' => $assignment, 'is_applicable' => true]);
        $this->assertDatabaseHas('product_feature_texts', ['assignment_id' => $assignment, 'value' => 'Saved']);
    }

    public function test_unit_change_confirmation_does_not_convert_numbers(): void
    {
        $id = $this->feature('number', ['unit' => 'g']);
        $this->product([$id => ['version' => 0, 'number' => '-125.25']]);
        $this->confirmed(fn ($v) => $this->edit($id, ['unit' => 'kg', ...$v]), 'unit_products', 1);
        $this->assertDatabaseHas('product_feature_assignments', ['feature_id' => $id, 'number_value' => '-125.250000']);
    }

    public function test_archive_restore_option_readonly_existing_selection_and_delete_guards(): void
    {
        $id = $this->feature('single', ['required' => true, 'filterable' => true]);
        $option = $this->option($id);
        $this->option($id, 'Linen');
        $p = $this->product([$id => ['version' => 0, 'options' => [$option]]], 'active');
        $this->confirmed(fn ($v) => $this->optionAction($id, $option, 'archive', $v), 'assigned_products', 1);
        self::assertSame([], app(FeatureQueries::class)->missingForProduct((string) $p->id));
        $this->invalid(fn () => $this->optionAction($id, $option, 'save', ['translations' => ['en' => ['label' => 'Changed']]]), 'status');
        $this->invalid(fn () => $this->optionAction($id, $option, 'delete'), 'option');
        $this->invalid(fn () => $this->product([$id => ['version' => 0, 'options' => [$option]]]), 'features.'.$id);
        $this->confirmed(fn ($v) => $this->action($id, 'archive', $v), 'assigned_products', 1);
        self::assertTrue(app(ProductFeatures::class)->editor($p)['definitions'][0]['readonly']);
        $this->invalid(fn () => $this->optionAction($id, $option, 'restore'), 'status');
        $this->invalid(fn () => $this->action($id, 'delete'), 'feature');
        $this->confirmed(fn ($v) => $this->action($id, 'restore', $v), 'assigned_products', 1);
        self::assertSame('archived', DB::table('feature_options')->where('id', $option)->value('status'));
        $this->confirmed(fn ($v) => $this->optionAction($id, $option, 'restore', $v), 'assigned_products', 1);
        self::assertSame('active', DB::table('feature_options')->where('id', $option)->value('status'));
        self::assertTrue(DB::table('features')->where('id', $id)->value('filterable'));
    }

    public function test_option_restore_blocked_by_incomplete_parent_and_missing_label(): void
    {
        $id = $this->feature('single');
        $option = $this->option($id);
        $this->optionAction($id, $option, 'archive');
        $this->invalid(fn () => $this->optionAction($id, $option, 'restore'), 'option');
        $this->option($id, 'New active option');
        $this->optionAction($id, $option, 'restore');
        $bad = $this->option($id, '');
        $this->optionAction($id, $bad, 'archive');
        $this->invalid(fn () => $this->optionAction($id, $bad, 'restore'), 'option');
        $this->confirmed(fn ($v) => $this->optionAction($id, $bad, 'delete', $v));
    }

    public function test_stale_definitions_options_groups_product_and_catalog_rejected(): void
    {
        $id = $this->feature('single');
        $option = $this->option($id);
        $old = $this->data($id);
        $this->edit($id, ['comparison' => true]);
        $this->invalid(fn () => $this->features->save($this->actor(), $id, new SaveFeatureData($old)), 'version');
        $this->optionAction($id, $option, 'save', ['translations' => ['en' => ['label' => 'New label']]]);
        $this->invalid(fn () => $this->optionAction($id, $option, 'save', ['version' => 1]), 'version');
        $p = $this->product([$id => ['version' => 0, 'options' => [$option]]]);
        $revision = app(FeatureQueries::class)->revision();
        $this->edit($id, ['comparison' => false]);
        $this->invalid(fn () => $this->saveProduct($p, ['features' => [$id => ['version' => 1, 'options' => [$option]]], 'feature_catalog_version' => $revision]), 'version');
        $this->saveProduct($p, ['features' => [$id => ['version' => 1, 'options' => [$option]]], 'feature_catalog_version' => app(FeatureQueries::class)->revision()]);
        $this->invalid(fn () => $this->saveProduct($p, ['features' => [$id => ['version' => 1, 'options' => [$option]]], 'feature_catalog_version' => app(FeatureQueries::class)->revision()]), 'version');
    }

    public function test_group_move_ordering_complete_snapshot_and_general_protection(): void
    {
        $g = $this->features->group($this->actor(), null, new SaveFeatureData(['action' => 'save', 'translations' => ['en' => ['name' => 'Physical']]]));
        $a = $this->feature('text', ['group_id' => $g]);
        $b = $this->feature('number', ['group_id' => $g]);
        $general = (string) DB::table('feature_groups')->where('system_key', 'general')->value('id');
        $order = ['catalog_version' => app(FeatureQueries::class)->revision(), 'groups' => [['id' => $g, 'features' => [$b, $a]], ['id' => $general, 'features' => []]]];
        $this->features->reorder($this->actor(), new SaveFeatureData($order));
        self::assertSame([$b, $a], array_column(app(FeatureQueries::class)->definitions([$a, $b]), 'id'));
        $this->invalid(fn () => $this->features->reorder($this->actor(), new SaveFeatureData($order)), 'version');
        $this->invalid(fn () => $this->features->reorder($this->actor(), new SaveFeatureData(['catalog_version' => app(FeatureQueries::class)->revision(), 'groups' => [['id' => $g, 'features' => [$a]]]])), 'groups');
        $this->confirmed(fn ($v) => $this->features->group($this->actor(), $g, new SaveFeatureData(['version' => DB::table('feature_groups')->where('id', $g)->value('lock_version'), 'action' => 'delete', 'translations' => [], ...$v])), 'moved_features', 2);
        self::assertSame([$b, $a], DB::table('features')->where('group_id', $general)->orderBy('position')->pluck('id')->map(strval(...))->all());
        $this->invalid(fn () => $this->features->group($this->actor(), $general, new SaveFeatureData(['version' => DB::table('feature_groups')->where('id', $general)->value('lock_version'), 'action' => 'save', 'translations' => ['en' => ['name' => 'Rename']]])), 'group');
    }

    public function test_duplication_copies_translations_and_archived_options_without_reactivation(): void
    {
        $text = $this->feature();
        $choice = $this->feature('single');
        $option = $this->option($choice);
        $this->option($choice, 'Alternative');
        $restricted = $this->feature('number', ['categories' => [(string) $this->leaf->id]]);
        $p = $this->product([$text => ['version' => 0, 'texts' => ['en' => 'Text', 'el' => 'Κείμενο']], $choice => ['version' => 0, 'options' => [$option]], $restricted => ['version' => 0, 'number' => '10']]);
        $this->confirmed(fn ($v) => $this->optionAction($choice, $option, 'archive', $v));
        $this->confirmed(fn ($v) => $this->action($text, 'archive', $v));
        $this->confirmed(fn ($v) => $this->edit($restricted, ['categories' => [(string) $this->other->id], ...$v]));
        $copy = $this->products->duplicate($this->actor(), (string) $p->id, $p->fresh()->lock_version);
        $values = app(ProductFeatures::class)->values($copy);
        self::assertSame('disabled', ProductRecord::findOrFail($copy)->status);
        self::assertSame('Κείμενο', ((array) $values[$text]['texts'])['el']);
        self::assertSame([$option], $values[$choice]['options']);
        self::assertArrayNotHasKey($restricted, $values);
        self::assertSame('archived', DB::table('features')->where('id', $text)->value('status'));
    }

    public function test_search_filters_pagination_and_permissions(): void
    {
        $id = $this->feature('number', ['required' => true, 'filterable' => true, 'categories' => [(string) $this->root->id]]);
        for ($i = 0; $i < 26; $i++) {
            $this->feature('text', ['translations' => ['en' => ['name' => 'Feature '.$i]]]);
        }
        $this->get('/admin/features?search='.urlencode('Υλικό').'&type=number&required=1&filterable=1&category_id='.$this->leaf->id)->assertInertia(fn (Assert $page) => $page->has('features.data', 1)->where('features.data.0.id', $id));
        $this->get('/admin/features')->assertInertia(fn (Assert $page) => $page->has('features.data', 25)->where('features.total', 27));
        $viewer = $this->staff(['admin.access', 'features.view']);
        $this->flushSession();
        $this->actingAs($viewer);
        $this->get('/admin/features')->assertOk();
        foreach (['/admin/features/create', '/admin/features/'.$id.'/edit', '/admin/features/groups', '/admin/features/ordering', '/admin/features/remediation'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post('/admin/features/'.$id, $this->data($id))->assertForbidden();
        try {
            $this->features->save((string) $viewer->id, $id, new SaveFeatureData($this->data($id)));
            self::fail('Expected forbidden');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }
    }

    public function test_postgresql_enforces_identity_choice_ownership_scalar_and_single_cardinality(): void
    {
        $single = $this->feature('single');
        $one = $this->option($single);
        $two = $this->option($single, 'Two');
        $other = $this->feature('single');
        $foreign = $this->option($other);
        $p = $this->product([$single => ['version' => 0, 'options' => [$one]]]);
        $assignment = DB::table('product_feature_assignments')->where('product_id', $p->id)->sole()->id;
        foreach ([fn () => DB::table('features')->where('id', $single)->update(['type' => 'number']), fn () => DB::table('features')->where('id', $single)->update(['code' => 'NEW']), fn () => DB::table('product_feature_assignments')->where('id', $assignment)->update(['number_value' => '1']), fn () => DB::table('product_feature_choices')->insert(['assignment_id' => $assignment, 'feature_id' => $single, 'type' => 'single', 'option_id' => $two]), fn () => DB::table('product_feature_choices')->insert(['assignment_id' => $assignment, 'feature_id' => $single, 'type' => 'single', 'option_id' => $foreign]), fn () => DB::table('product_feature_texts')->insert(['assignment_id' => $assignment, 'locale_id' => LocaleRecord::where('code', 'en')->value('id'), 'value' => 'Wrong'])] as $operation) {
            try {
                DB::transaction($operation);
                self::fail('Expected database integrity failure');
            } catch (QueryException $e) {
                self::assertContains($e->getCode(), ['23514', '23505', '23503']);
            }
        }
    }

    public function test_demo_exactly_ten_products_all_types_localized_idempotent_and_preserves_edits(): void
    {
        $unrelated = $this->product();
        $seeder = app(FeatureDemoSeeder::class);
        self::assertSame(10, $seeder->run($this->actor()));
        $ids = DB::table('feature_demo_records')->where('entity', 'products')->pluck('entity_id');
        self::assertCount(10, $ids);
        self::assertSame(11, DB::table('products')->count());
        self::assertSame(5, DB::table('product_feature_assignments')->whereIn('product_id', $ids)->distinct()->count('type'));
        foreach ($ids as $id) {
            self::assertSame([], app(FeatureQueries::class)->missingForProduct((string) $id));
            self::assertGreaterThanOrEqual(6, DB::table('product_feature_assignments')->where('product_id', $id)->count());
        }
        self::assertSame(10, DB::table('product_feature_texts')->where('locale_id', LocaleRecord::where('code', 'el')->value('id'))->count());
        $before = DB::table('product_feature_assignments')->count();
        DB::table('products')->where('id', $ids[0])->update(['price' => '777']);
        self::assertSame(0, $seeder->run($this->actor()));
        self::assertSame($before, DB::table('product_feature_assignments')->count());
        self::assertSame('777.000000', DB::table('products')->where('id', $ids[0])->value('price'));
        self::assertNotNull($unrelated->fresh());
    }

    public function test_http_confirmation_flow_and_changed_impact_requires_review_again(): void
    {
        $id = $this->feature('number', ['unit' => 'g']);
        $this->product([$id => ['version' => 0, 'number' => '3']]);
        $data = [...$this->data($id), 'unit' => 'kg'];
        $this->from('/admin/features/'.$id.'/edit')->post('/admin/features/'.$id, $data)->assertRedirect('/admin/features/'.$id.'/edit')->assertSessionHas('feature_impact')->assertSessionHasErrors('confirmation');
        $preview = session('feature_impact');
        $this->assertDatabaseHas('features', ['id' => $id, 'unit' => 'g']);
        $this->product([$id => ['version' => 0, 'number' => '4']]);
        $this->post('/admin/features/'.$id, [...$data, 'confirmed' => true, 'impact_token' => $preview['token']])->assertSessionHasErrors('confirmation');
        $updated = session('feature_impact');
        self::assertSame(2, $updated['impact']['unit_products']);
        self::assertNotSame($preview['token'], $updated['token']);
        $this->post('/admin/features/'.$id, [...$data, 'confirmed' => true, 'impact_token' => $updated['token']])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('features', ['id' => $id, 'unit' => 'kg']);
    }

    public function test_filterability_comparison_and_optional_settings_do_not_change_values(): void
    {
        $id = $this->feature('boolean', ['required' => true, 'filterable' => true, 'comparison' => true]);
        $p = $this->product([$id => ['version' => 0, 'boolean' => '1']], 'active');
        $this->edit($id, ['filterable' => false]);
        self::assertTrue($this->data($id)['required']);
        self::assertTrue($this->data($id)['comparison']);
        self::assertSame([], app(FeatureQueries::class)->missingForProduct((string) $p->id));
        $this->assertDatabaseHas('product_feature_assignments', ['product_id' => $p->id, 'boolean_value' => true]);
        $this->confirmed(fn ($v) => $this->action($id, 'archive', $v));
        $this->product([], 'active'); // Archived definitions never participate in activation.
        $draft = $this->feature('single', ['required' => true]);
        $this->product([], 'active'); // Incomplete choice definitions are excluded independently.
        self::assertTrue(app(FeatureQueries::class)->definitions([$draft])[0]['incomplete']);
    }

    public function test_global_features_apply_to_future_categories_and_remediation_respects_deletion(): void
    {
        $id = $this->feature('text', ['required' => true]);
        $future = CategoryRecord::create(['position' => 3, 'status' => 'active']);
        $p = $this->product();
        $this->saveProduct($p, ['category_id' => (string) $future->id]);
        self::assertSame([$id], array_column(app(ProductFeatures::class)->editor($p->fresh())['definitions'], 'id'));
        DB::table('products')->where('id', $p->id)->update(['status' => 'active']);
        self::assertSame(1, app(FeatureQueries::class)->remediationCount());
        $this->products->lifecycle($this->actor(), (string) $p->id, $p->fresh()->lock_version, 'trash');
        self::assertSame(0, app(FeatureQueries::class)->remediationCount());
    }

    public function test_reconciliation_handles_more_than_one_chunk_without_losing_history(): void
    {
        $id = $this->feature('number');
        for ($i = 0; $i < 205; $i++) {
            $p = ProductRecord::create(['category_id' => $this->leaf->id]);
            DB::table('product_feature_assignments')->insert(['product_id' => $p->id, 'feature_id' => $id, 'type' => 'number', 'number_value' => '0']);
        }
        $this->confirmed(fn ($v) => $this->edit($id, ['categories' => [(string) $this->other->id], ...$v]), 'inactive_products', 205);
        self::assertSame(205, DB::table('product_feature_assignments')->where('is_applicable', false)->count());
        $this->edit($id, ['categories' => []]);
        self::assertSame(205, DB::table('product_feature_assignments')->where('is_applicable', true)->count());
    }

    public function test_group_versions_and_product_update_permissions_cannot_be_bypassed(): void
    {
        $group = $this->features->group($this->actor(), null, new SaveFeatureData(['action' => 'save', 'translations' => ['en' => ['name' => 'One']]]));
        $this->features->group($this->actor(), $group, new SaveFeatureData(['version' => 1, 'action' => 'save', 'translations' => ['en' => ['name' => 'Two']]]));
        $this->invalid(fn () => $this->features->group($this->actor(), $group, new SaveFeatureData(['version' => 1, 'action' => 'delete', 'translations' => []])), 'version');
        $f = $this->feature('boolean');
        $p = $this->product();
        $viewer = $this->staff(['admin.access', 'features.view', 'features.update', 'products.view']);
        $this->flushSession();
        $this->actingAs($viewer);
        $this->post('/admin/products/'.$p->id, ['version' => $p->lock_version, 'features' => [$f => ['version' => 0, 'boolean' => '1']]])->assertForbidden();
        $this->post('/admin/features/'.$f.'/action', ['version' => $this->data($f)['version'], 'action' => 'archive'])->assertForbidden();
        $this->post('/admin/features/groups', ['action' => 'save', 'translations' => ['en' => ['name' => 'Unauthorized']]])->assertForbidden();
        $this->assertDatabaseCount('product_feature_assignments', 0);
    }

    public function test_demo_rolls_back_on_sku_collision_and_is_forbidden_in_production(): void
    {
        $p = $this->product();
        DB::table('products')->where('id', $p->id)->update(['sku' => 'DEMO-P5-001']);
        $this->invalid(fn () => app(FeatureDemoSeeder::class)->run($this->actor()), 'sku');
        $this->assertDatabaseCount('feature_demo_records', 0);
        $this->assertDatabaseCount('features', 0);
        $this->assertDatabaseCount('products', 1);
        app()->detectEnvironment(fn () => 'production');
        try {
            app(FeatureDemoSeeder::class)->run($this->actor());
            self::fail('Production seeding must fail');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('restricted', $e->getMessage());
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
    }

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aN1sAAAAASUVORK5CYII=';

    /** @param array<string, mixed> $extra */
    private function variant(string $feature, string $option, array $extra): void
    {
        $this->features->option($this->actor(), $feature, $option, new SaveFeatureData(['feature_version' => $this->data($feature)['version'], 'version' => DB::table('feature_options')->where('id', $option)->value('lock_version'), 'action' => 'save', ...$extra]));
    }

    public function test_only_single_choice_features_can_use_landing_pages_including_direct_requests(): void
    {
        foreach (['text', 'number', 'boolean', 'multiple'] as $type) {
            $this->invalid(fn () => $this->feature($type, ['purpose' => 'landing_pages']), 'purpose');
            $id = $this->feature($type);
            $this->invalid(fn () => $this->edit($id, ['purpose' => 'landing_pages']), 'purpose');
            $this->from('/admin/features/'.$id.'/edit')->post('/admin/features/'.$id, [...$this->data($id), 'purpose' => 'landing_pages'])->assertSessionHasErrors('purpose');
            self::assertSame('standard', DB::table('features')->where('id', $id)->value('purpose'));
        }
        $this->invalid(fn () => $this->feature('single', ['purpose' => 'brand']), 'purpose');
        $brand = $this->feature('single', ['purpose' => 'landing_pages', 'translations' => ['en' => ['name' => 'Brand']]]);
        self::assertSame('landing_pages', $this->data($brand)['purpose']);
        $multiple = (string) DB::table('features')->where('type', 'multiple')->value('id');
        try {
            DB::transaction(fn () => DB::table('features')->where('id', $multiple)->update(['purpose' => 'landing_pages']));
            self::fail('Expected purpose constraint');
        } catch (QueryException $e) {
            self::assertSame('23514', $e->errorInfo[0]);
        }
        // Omitting purpose on update keeps the configured purpose; names never imply it.
        $this->features->save($this->actor(), $brand, new SaveFeatureData(array_diff_key($this->data($brand), ['purpose' => true])));
        self::assertSame('landing_pages', $this->data($brand)['purpose']);
        self::assertSame('standard', $this->data($this->feature('single', ['translations' => ['en' => ['name' => 'Manufacturer']]]))['purpose']);
        $this->assertDatabaseMissing('feature_audits', ['entity_id' => $brand, 'action' => 'purpose_changed']);
    }

    public function test_variant_landing_metadata_is_localized_normalized_unique_and_leaves_assignments_unchanged(): void
    {
        $brand = $this->feature('single', ['purpose' => 'landing_pages', 'required' => true, 'filterable' => true, 'comparison' => true, 'categories' => [(string) $this->root->id], 'translations' => ['en' => ['name' => 'Brand'], 'el' => ['name' => 'Μάρκα']]]);
        $apple = $this->option($brand, 'Apple');
        $samsung = $this->option($brand, 'Samsung');
        $this->action($brand, 'activate');
        $p = $this->product([$brand => ['version' => 0, 'options' => [$apple]]], 'active');
        $assignment = DB::table('product_feature_assignments')->where('product_id', $p->id)->first();
        $settings = DB::table('features')->where('id', $brand)->first(['required', 'filterable', 'comparison', 'group_id', 'position', 'status']);
        $productVersion = $p->fresh()->lock_version;

        $this->variant($brand, $apple, ['translations' => ['en' => ['label' => 'Apple', 'description' => 'Apple products', 'seo_title' => 'Apple', 'seo_description' => 'Shop Apple', 'meta_keywords' => 'apple, iphone', 'slug' => ' Apple Store! '], 'el' => ['label' => 'Apple', 'description' => 'Προϊόντα Apple', 'slug' => 'apple-store']]]);
        $row = fn (string $option, string $locale) => DB::table('feature_option_translations')->where('option_id', $option)->where('locale_id', LocaleRecord::where('code', $locale)->value('id'))->first();
        self::assertSame('apple-store', $row($apple, 'en')->slug);
        self::assertSame('apple-store', $row($apple, 'el')->slug);
        self::assertSame('Shop Apple', $row($apple, 'en')->seo_description);
        self::assertSame('', $row($apple, 'el')->seo_description);
        self::assertSame('Προϊόντα Apple', $row($apple, 'el')->description);
        self::assertSame('Brand', $this->data($brand)['translations']['en']['name']);
        // A slug is unique per language; suggestion is collision-safe and independent of the label.
        $this->invalid(fn () => $this->variant($brand, $samsung, ['translations' => ['en' => ['label' => 'Samsung', 'slug' => 'apple-store']]]), 'translations.en.slug');
        $this->variant($brand, $samsung, ['translations' => ['en' => ['label' => 'Apple Store', 'suggest_slug' => true]]]);
        self::assertSame('apple-store-2', $row($samsung, 'en')->slug);
        $this->variant($brand, $samsung, ['translations' => ['en' => ['label' => 'Samsung', 'slug' => 'galaxy']]]);
        self::assertSame(['Samsung', 'galaxy'], [$row($samsung, 'en')->label, $row($samsung, 'en')->slug]);
        $this->invalid(fn () => $this->variant($brand, $samsung, ['translations' => ['en' => ['label' => 'Samsung', 'slug' => '!!!']]]), 'translations.en.slug');
        $this->variant($brand, $samsung, ['translations' => ['en' => ['label' => 'Samsung', 'slug' => '']]]);
        self::assertNull($row($samsung, 'en')->slug);

        $definition = app(FeatureQueries::class)->definitions([$brand])[0];
        self::assertSame('apple-store', $definition['options'][0]['translations']->en['slug']);
        self::assertTrue($definition['options'][0]['landing_eligible']);
        self::assertNull($definition['options'][0]['image_url']);
        // Variant metadata never alters product assignments or unrelated settings.
        self::assertEquals($assignment, DB::table('product_feature_assignments')->where('product_id', $p->id)->first());
        self::assertSame([$apple], DB::table('product_feature_choices')->where('assignment_id', $assignment->id)->pluck('option_id')->map(strval(...))->all());
        self::assertEquals($settings, DB::table('features')->where('id', $brand)->first(['required', 'filterable', 'comparison', 'group_id', 'position', 'status']));
        self::assertSame([(string) $this->root->id], $this->data($brand)['categories']);
        self::assertSame($productVersion, $p->fresh()->lock_version);
        $audit = DB::table('feature_audits')->where('entity', 'option')->where('entity_id', $apple)->where('action', 'save')->orderByDesc('id')->value('context');
        self::assertSame(['description', 'seo_title', 'seo_description', 'meta_keywords', 'slug'], json_decode($audit, true)['changed']['en']);
    }

    public function test_standard_attribute_options_reject_landing_metadata_and_images(): void
    {
        Storage::fake('local');
        $id = $this->feature('single');
        $option = $this->option($id);
        $this->invalid(fn () => $this->variant($id, $option, ['translations' => ['en' => ['label' => 'Cotton', 'slug' => 'cotton']]]), 'translations.en');
        $this->invalid(fn () => $this->variant($id, $option, ['translations' => ['en' => ['label' => 'Cotton', 'seo_title' => 'Cotton']]]), 'translations.en');
        $this->invalid(fn () => $this->variant($id, $option, ['translations' => [], 'remove_image' => true]), 'remove_image');
        $this->from('/admin/features/'.$id.'/edit')->post('/admin/features/'.$id.'/options/'.$option, ['feature_version' => $this->data($id)['version'], 'version' => 1, 'action' => 'save', 'translations' => ['en' => ['label' => 'Cotton']], 'image' => UploadedFile::fake()->createWithContent('a.png', base64_decode(self::PNG))])->assertSessionHasErrors('image');
        self::assertSame(0, DB::table('media_files')->count());
        self::assertSame([], Storage::disk('local')->allFiles());
        $options = app(FeatureQueries::class)->definitions([$id])[0]['options'];
        self::assertSame(['label'], array_keys($options[0]['translations']->en));
        self::assertArrayNotHasKey('image_url', $options[0]);
        self::assertArrayNotHasKey('landing_eligible', $options[0]);
        $this->get('/admin/features/'.$id.'/edit')->assertInertia(fn (Assert $page) => $page->where('feature.purpose', 'standard')->missing('feature.options.0.image_url'));
    }

    public function test_variant_image_upload_replace_remove_and_shared_media_protection(): void
    {
        Storage::fake('local');
        $brand = $this->feature('single', ['purpose' => 'landing_pages']);
        $apple = $this->option($brand, 'Apple');
        $upload = fn () => UploadedFile::fake()->createWithContent('logo.png', base64_decode(self::PNG));
        $post = fn (array $extra) => $this->from('/admin/features/'.$brand.'/edit')->post('/admin/features/'.$brand.'/options/'.$apple, ['feature_version' => $this->data($brand)['version'], 'version' => DB::table('feature_options')->where('id', $apple)->value('lock_version'), 'action' => 'save', 'translations' => ['en' => ['label' => 'Apple']], ...$extra]);
        $post(['image' => $upload()])->assertSessionHasNoErrors();
        $first = (string) DB::table('feature_options')->where('id', $apple)->value('landing_image_media_id');
        self::assertNotSame('', $first);
        $this->get('/admin/features/'.$brand.'/options/'.$apple.'/image')->assertOk();
        $this->get('/admin/features/'.$brand.'/options/'.$this->option($brand, 'Other').'/image')->assertNotFound();
        self::assertStringContainsString('/admin/features/'.$brand.'/options/'.$apple.'/image', app(FeatureQueries::class)->definitions([$brand])[0]['options'][0]['image_url']);
        $post(['image' => $upload(), 'remove_image' => true])->assertSessionHasErrors('image');
        $post(['image' => $upload()])->assertSessionHasNoErrors();
        $second = (string) DB::table('feature_options')->where('id', $apple)->value('landing_image_media_id');
        self::assertNotSame($first, $second);
        $this->assertDatabaseMissing('media_files', ['id' => $first]);
        self::assertCount(1, Storage::disk('local')->allFiles());
        try {
            MediaFileRecord::findOrFail($second)->delete();
            self::fail('Expected shared media protection');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('media', $e->errors());
        }
        // A media file still referenced by another owner is detached but never deleted.
        DB::table('feature_options')->where('id', $apple)->update(['landing_image_media_id' => null]);
        DB::table('categories')->where('id', $this->other->id)->update(['image_media_id' => $second]);
        DB::table('feature_options')->where('id', $apple)->update(['landing_image_media_id' => DB::table('media_files')->insertGetId(['disk' => 'local', 'storage_key' => 'images/shared-check.png', 'mime_type' => 'image/png', 'byte_size' => 1, 'width' => 1, 'height' => 1])]);
        $shared = (string) DB::table('feature_options')->where('id', $apple)->value('landing_image_media_id');
        DB::table('categories')->where('id', $this->other->id)->update(['image_media_id' => null]);
        DB::table('product_images')->insert(['product_id' => $this->product()->id, 'media_file_id' => $shared, 'position' => 1, 'is_primary' => true]);
        $post(['remove_image' => true])->assertSessionHasNoErrors();
        self::assertNull(DB::table('feature_options')->where('id', $apple)->value('landing_image_media_id'));
        $this->assertDatabaseHas('media_files', ['id' => $shared]);
        $this->assertDatabaseHas('media_files', ['id' => $second]);
        self::assertSame(['added', 'replaced', 'removed'], DB::table('feature_audits')->where('entity', 'option')->where('entity_id', $apple)->where('action', 'save')->orderBy('id')->pluck('context')->map(fn ($c) => json_decode($c, true)['image'] ?? null)->filter()->values()->all());
    }

    public function test_purpose_downgrade_requires_confirmation_and_preserves_hidden_metadata(): void
    {
        Storage::fake('local');
        $brand = $this->feature('single', ['purpose' => 'landing_pages']);
        $apple = $this->option($brand, 'Apple');
        $plain = $this->option($brand, 'Plain');
        $p = $this->product([$brand => ['version' => 0, 'options' => [$apple]]]);
        $this->variant($brand, $apple, ['translations' => ['en' => ['label' => 'Apple', 'slug' => 'apple', 'seo_title' => 'Apple']]]);
        $this->confirmed(fn ($v) => $this->edit($brand, ['purpose' => 'standard', ...$v]), 'landing_variants', 1);
        self::assertSame('standard', $this->data($brand)['purpose']);
        $this->assertDatabaseHas('feature_option_translations', ['option_id' => $apple, 'slug' => 'apple', 'seo_title' => 'Apple', 'label' => 'Apple']);
        self::assertSame(['label'], array_keys(app(FeatureQueries::class)->definitions([$brand])[0]['options'][0]['translations']->en));
        $context = json_decode(DB::table('feature_audits')->where('entity_id', $brand)->where('action', 'purpose_changed')->value('context'), true);
        self::assertEquals(['from' => 'landing_pages', 'to' => 'standard', 'landing_variants' => 1], $context);
        // Label-only saves while hidden never erase preserved metadata; options and assignments remain.
        $this->variant($brand, $apple, ['translations' => ['en' => ['label' => 'Apple Inc.']]]);
        $this->assertDatabaseHas('feature_option_translations', ['option_id' => $apple, 'slug' => 'apple', 'label' => 'Apple Inc.']);
        self::assertSame(2, DB::table('feature_options')->where('feature_id', $brand)->where('status', 'active')->count());
        self::assertSame(1, DB::table('product_feature_choices')->where('option_id', $apple)->count());
        $this->edit($brand, ['purpose' => 'landing_pages']);
        self::assertSame('apple', app(FeatureQueries::class)->definitions([$brand])[0]['options'][0]['translations']->en['slug']);
        $other = $this->feature('single', ['purpose' => 'landing_pages']);
        $this->option($other, 'No metadata');
        $this->edit($other, ['purpose' => 'standard']);
        self::assertSame('standard', $this->data($other)['purpose']);
        $this->assertDatabaseHas('feature_audits', ['entity_id' => $other, 'action' => 'purpose_changed']);
        self::assertSame((string) $p->id, (string) DB::table('product_feature_assignments')->where('feature_id', $brand)->value('product_id'));
        self::assertNotSame('', $plain);
    }

    public function test_archived_feature_and_variant_metadata_readonly_restore_and_eligibility(): void
    {
        $brand = $this->feature('single', ['purpose' => 'landing_pages']);
        $apple = $this->option($brand, 'Apple');
        $legacy = $this->option($brand, 'Legacy');
        $eligible = fn (): array => array_column(app(FeatureQueries::class)->definitions([$brand])[0]['options'], 'landing_eligible', 'id');
        self::assertSame([$apple => true, $legacy => true], $eligible());
        $this->variant($brand, $legacy, ['translations' => ['en' => ['label' => 'Legacy', 'slug' => 'legacy']]]);
        $this->optionAction($brand, $legacy, 'archive');
        self::assertSame([$apple => true, $legacy => false], $eligible());
        $this->invalid(fn () => $this->variant($brand, $legacy, ['translations' => ['en' => ['label' => 'Legacy', 'slug' => 'legacy-2']]]), 'status');
        $this->confirmed(fn ($v) => $this->action($brand, 'archive', $v));
        self::assertSame([$apple => false, $legacy => false], $eligible());
        $this->invalid(fn () => $this->edit($brand, ['purpose' => 'standard']), 'status');
        $this->invalid(fn () => $this->variant($brand, $apple, ['translations' => ['en' => ['label' => 'Apple', 'seo_title' => 'Changed']]]), 'status');
        $this->invalid(fn () => $this->optionAction($brand, $legacy, 'restore'), 'status');
        $this->action($brand, 'restore');
        self::assertSame('landing_pages', $this->data($brand)['purpose']);
        self::assertSame('archived', DB::table('feature_options')->where('id', $legacy)->value('status'));
        $this->optionAction($brand, $legacy, 'restore');
        $this->variant($brand, $legacy, ['translations' => ['en' => ['label' => 'Legacy', 'seo_title' => 'Legacy']]]);
        $this->assertDatabaseHas('feature_option_translations', ['option_id' => $legacy, 'slug' => 'legacy', 'seo_title' => 'Legacy']);
        self::assertSame([$apple => true, $legacy => true], $eligible());
        // Incomplete special-purpose features never expose eligible targets.
        $draft = $this->feature('single', ['purpose' => 'landing_pages', 'translations' => ['el' => ['name' => 'Μάρκα']]]);
        $this->option($draft, 'Draft');
        self::assertFalse(app(FeatureQueries::class)->definitions([$draft])[0]['options'][0]['landing_eligible']);
    }

    public function test_legacy_landings_reuse_the_storefront_route_without_creating_page_records(): void
    {
        $brand = $this->feature('single', ['purpose' => 'landing_pages']);
        $apple = $this->option($brand, 'Apple');
        $this->variant($brand, $apple, ['translations' => ['en' => ['label' => 'Apple', 'slug' => 'apple']]]);
        foreach (Route::getRoutes() as $route) {
            if (str_contains((string) $route->getActionName(), 'FeatureController')) {
                self::assertStringStartsWith('admin/', $route->uri());
                self::assertContains('can:access-admin', $route->gatherMiddleware());
            }
        }
        $this->get('/apple')->assertOk();
        // Feature-option landings reuse the fallback; they do not create Website page records.
        self::assertSame(0, DB::table('pages')->count());
        foreach (['cms_pages', 'landing_pages', 'feature_landing_pages'] as $table) {
            self::assertFalse(Schema::hasTable($table));
        }
    }

    public function test_purpose_migration_retains_feature_seo_and_rolls_back(): void
    {
        $migration = require database_path('migrations/2026_10_10_001000_add_feature_purpose_and_variant_pages.php');
        $id = $this->feature('single', ['translations' => ['en' => ['name' => 'Brand']]]);
        $migration->down();
        DB::table('feature_translations')->where('feature_id', $id)->where('locale_id', LocaleRecord::where('code', 'en')->value('id'))->update(['slug' => 'brand', 'seo_title' => 'Brands']);
        $migration->up();
        self::assertSame('standard', DB::table('features')->where('id', $id)->value('purpose'));
        self::assertFalse(Schema::hasColumn('feature_translations', 'slug'));
        $retained = json_decode(DB::table('feature_audits')->where('entity_id', $id)->where('action', 'seo_retired')->value('context'), true);
        self::assertEquals(['locale' => 'en', 'slug' => 'brand', 'seo_title' => 'Brands', 'seo_description' => ''], $retained);
        self::assertSame(1, DB::table('feature_audits')->where('action', 'seo_retired')->count());
        $migration->down();
        $this->assertDatabaseHas('feature_translations', ['feature_id' => $id, 'slug' => 'brand', 'seo_title' => 'Brands']);
        $this->assertDatabaseMissing('feature_audits', ['action' => 'seo_retired']);
        $migration->up();
    }

    public function test_the_options_table_saves_edits_and_new_options_atomically_and_reports_product_counts(): void
    {
        $id = $this->feature('single');
        $cotton = $this->option($id, 'Cotton');
        $wool = $this->option($id, 'Wool');
        $version = fn (string $option): int => (int) DB::table('feature_options')->where('id', $option)->value('lock_version');
        $edit = fn (string $option, string $label): array => ['id' => (int) $option, 'version' => $version($option), 'translations' => ['en' => ['label' => $label], 'el' => ['label' => 'Βαμβάκι']]];

        $this->post('/admin/features/'.$id.'/options/batch', ['feature_version' => $this->data($id)['version'], 'changes' => [
            $edit($cotton, 'Organic cotton'), $edit($wool, 'Merino wool'), ['translations' => ['en' => ['label' => 'Linen'], 'el' => ['label' => 'Λινό']]],
        ]])->assertSessionHasNoErrors()->assertRedirect('/admin/features/'.$id.'/edit?tab=options');
        $labels = DB::table('feature_option_translations')->join('locales', 'locales.id', '=', 'locale_id')->where('locales.code', 'en')->pluck('label')->sort()->values()->all();
        self::assertSame(['Linen', 'Merino wool', 'Organic cotton'], $labels);
        self::assertSame(3, DB::table('feature_options')->where('feature_id', $id)->count());

        // A failing row (stale option version) rolls the whole batch back and is reported against its row.
        $this->post('/admin/features/'.$id.'/options/batch', ['feature_version' => $this->data($id)['version'], 'changes' => [
            $edit($cotton, 'Should not persist'), ['translations' => ['en' => ['label' => 'Silk']]], ['id' => (int) $wool, 'version' => 1, 'translations' => ['en' => ['label' => 'Stale']]],
        ]])->assertSessionHasErrors('changes.2.version');
        self::assertSame('Organic cotton', DB::table('feature_option_translations')->where('option_id', $cotton)->value('label'));
        self::assertSame(3, DB::table('feature_options')->where('feature_id', $id)->count());

        // Stale option versions and a stale feature version are refused.
        $this->post('/admin/features/'.$id.'/options/batch', ['feature_version' => $this->data($id)['version'], 'changes' => [['id' => (int) $cotton, 'version' => 1, 'translations' => ['en' => ['label' => 'Stale']]]]])->assertSessionHasErrors();
        $this->post('/admin/features/'.$id.'/options/batch', ['feature_version' => 1, 'changes' => [['translations' => ['en' => ['label' => 'Late']]]]])->assertSessionHasErrors();
        self::assertSame(3, DB::table('feature_options')->where('feature_id', $id)->count());

        $this->get('/admin/features/'.$id.'/edit?tab=options')->assertInertia(fn (Assert $page) => $page->where('feature.options.0.products', 0)->has('feature.options', 3));
    }

    public function test_the_options_batch_requires_the_update_capability(): void
    {
        $id = $this->feature('single');
        $this->flushSession();
        $this->actingAs($this->staff(['admin.access', 'features.view']));
        $this->post('/admin/features/'.$id.'/options/batch', ['feature_version' => 1, 'changes' => [['translations' => ['en' => ['label' => 'Nope']]]]])->assertForbidden();
        self::assertSame(0, DB::table('feature_options')->count());
    }
}
