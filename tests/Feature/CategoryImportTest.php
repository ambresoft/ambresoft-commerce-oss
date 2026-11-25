<?php

namespace Tests\Feature;

use App\Catalog\Application\Command\CategoryDeletion;
use App\Catalog\Application\Command\ManageCategoriesHandler;
use App\Catalog\Application\Command\SaveCategoryData;
use App\Catalog\Application\Contract\CategoryMutationParticipant;
use App\Catalog\Application\Service\CategoryMutationParticipants;
use App\Catalog\Domain\Event\CategoryCreated;
use App\Catalog\Domain\Exception\InvalidCategoryChange;
use App\Catalog\Infrastructure\CategoryHierarchy;
use App\Catalog\Infrastructure\Import\CategoryImportFields;
use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Catalog\Infrastructure\Persistence\CategoryTranslationRecord;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\Persistence\LocaleRecord;
use App\Shared\Application\Command\ImageUpload;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class CategoryImportTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private UserRecord $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // Parser/hierarchy/large-file cases use the supported single-language catalog.
        LocaleRecord::where('code', '<>', 'en')->update(['is_active' => false]);
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actor = $this->staff(['admin.access', 'categories.view', 'categories.import', 'categories.create', 'categories.update']);
        $this->actingAs($this->actor);
        Storage::fake('local');
    }

    private function preview(string $csv, string $delimiter = ',', string $separator = '///'): string
    {
        // Tests switch identities explicitly; keep AuthenticateSession's fingerprint in sync.
        $this->withSession(['password_hash_web' => auth()->user()->getAuthPassword()]);
        $response = $this->post('/admin/categories/import/preview', ['file' => UploadedFile::fake()->createWithContent('categories.csv', $csv), 'delimiter' => $delimiter, 'path_separator' => $separator]);
        $response->assertSessionHasNoErrors()->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

        return $query['token'];
    }

    /** @return array<string, mixed> */
    private function report(string $token): array
    {
        return Cache::get('category-import:'.$token)['report'];
    }

    private function singleLocale(string $code): void
    {
        LocaleRecord::where('is_default', true)->update(['is_default' => false]);
        LocaleRecord::query()->update(['is_active' => false]);
        LocaleRecord::where('code', $code)->update(['is_active' => true, 'is_default' => true]);
    }

    private function confirm(string $token): array
    {
        $this->post('/admin/categories/import/'.$token.'/confirm', ['confirmed' => true])->assertSessionHasNoErrors()->assertRedirect();

        return $this->report($token);
    }

    private function create(string $name = 'Original', ?string $parent = null): CategoryRecord
    {
        $id = app(ManageCategoriesHandler::class)->save((string) $this->actor->id, null, new SaveCategoryData($parent, 1, 'active', ['en' => ['name' => $name, 'slug' => '', 'description' => 'Keep description']]));
        // A retained inactive translation is legitimate historical content, not a selectable import locale.
        CategoryRecord::findOrFail($id)->translations()->create(['locale_id' => 2, 'name' => 'Ελληνικό '.$name, 'slug' => 'el-'.$id, 'description' => 'Διατήρηση']);

        return CategoryRecord::findOrFail($id);
    }

    public function test_preview_is_read_only_including_sequences_events_and_existing_content(): void
    {
        $category = $this->create();
        Event::fake([CategoryCreated::class]);
        $sequence = DB::selectOne('SELECT last_value FROM categories_id_seq')->last_value;
        $token = $this->preview("category_id,category_path,name.en,status\n,Technology///Gaming///Chairs,Chairs,active\n");
        self::assertSame(0, $this->report($token)['errors']);
        self::assertSame(3, $this->report($token)['created']);
        self::assertSame(2, $this->report($token)['parents']);
        self::assertSame($sequence, DB::selectOne('SELECT last_value FROM categories_id_seq')->last_value);
        $this->assertDatabaseCount('categories', 2);
        self::assertSame('Original', $category->translations()->where('locale_id', 1)->sole()->name);
        Event::assertNotDispatched(CategoryCreated::class);
        $this->get('/admin/categories/import?token='.$token)->assertInertia(fn (Assert $p) => $p->component('Admin/Categories/Import')->where('preview.status', 'preview')->where('preview.created', 3));
    }

    public function test_partial_imports_allow_one_nondefault_active_language_and_later_translation_completion(): void
    {
        LocaleRecord::whereIn('code', ['el', 'fr'])->update(['is_active' => true]);
        $token = $this->preview("name.el,description.el,name.en\nΚαρέκλες,Ελληνική περιγραφή,\n");
        self::assertSame(0, $this->report($token)['errors']);
        self::assertSame('Καρέκλες', $this->report($token)['sample'][0]['name']);
        $this->assertDatabaseCount('categories', 1);
        self::assertSame('completed', $this->confirm($token)['status']);
        $category = CategoryRecord::whereNull('system_key')->sole();
        self::assertSame(['el'], $category->translations()->with('locale')->get()->pluck('locale.code')->all());
        self::assertSame('Ελληνική περιγραφή', $category->translations()->sole()->description);

        $token = $this->preview("category_id,name.en,description.en\n$category->id,Chairs,English description\n");
        self::assertSame(0, $this->report($token)['errors']);
        self::assertSame('completed', $this->confirm($token)['status']);
        self::assertSame('Καρέκλες', $category->translations()->where('locale_id', 2)->sole()->name);
        $token = $this->preview("category_id,name.fr\n$category->id,Chaises\n");
        self::assertSame('completed', $this->confirm($token)['status']);
        self::assertSame(3, $category->translations()->count());
        self::assertSame('English description', $category->translations()->where('locale_id', 1)->sole()->description);
        $token = $this->preview("category_id,description.en\n$category->id,\n");
        self::assertSame('completed', $this->confirm($token)['status']);
        self::assertSame('', $category->translations()->where('locale_id', 1)->sole()->description);
        self::assertSame('Ελληνική περιγραφή', $category->translations()->where('locale_id', 2)->sole()->description);

        // Completing imported content through the editor still uses strict final-result validation.
        $data = ['version' => $category->fresh()->lock_version, 'position' => $category->position, 'status' => $category->status, 'parent_id' => null, 'remove_image' => false, 'translations' => []];
        $this->put('/admin/categories/'.$category->id, $data)->assertSessionHasErrors('translations.en.seo_title');
        foreach (['en', 'el', 'fr'] as $code) {
            $data['translations'][$code] = array_fill_keys(CategoryImportFields::TRANSLATED, 'Complete '.$code);
            $data['translations'][$code]['slug'] = 'complete-'.$code;
        }
        $this->put('/admin/categories/'.$category->id, $data)->assertSessionHasNoErrors();
        self::assertSame('Complete el', $category->translations()->where('locale_id', 2)->sole()->description);
    }

    public function test_multilingual_import_creates_missing_parents_without_fabricating_other_languages(): void
    {
        LocaleRecord::whereIn('code', ['el', 'fr'])->update(['is_active' => true]);
        $token = $this->preview("category_path,status\nTechnology///Gaming///Chairs,active\n");
        self::assertSame(0, $this->report($token)['errors']);
        self::assertSame(2, $this->report($token)['parents']);
        self::assertSame('completed', $this->confirm($token)['status']);
        $this->assertDatabaseCount('category_translations', 3);
        self::assertSame(0, CategoryTranslationRecord::where('locale_id', '<>', 1)->count());
        self::assertSame(2, CategoryRecord::whereNull('system_key')->where('status', 'disabled')->count());
    }

    public function test_inactive_csv_language_columns_are_rejected_even_with_empty_cells(): void
    {
        foreach (["name.en,name.fr\nChairs,Chaises\n", "name.en,description.fr\nChairs,\n"] as $csv) {
            $token = $this->preview($csv);
            $report = $this->report($token);
            self::assertGreaterThan(0, $report['errors']);
            self::assertSame(1, $report['issues'][0]['row']);
            self::assertStringEndsWith('.fr', $report['issues'][0]['column']);
            self::assertStringContainsString('fr', $report['issues'][0]['reason']);
        }
        $this->assertDatabaseCount('categories', 1);
    }

    public function test_import_save_cannot_bypass_import_authority_or_active_locale_validation(): void
    {
        $actor = $this->staff(['admin.access', 'categories.create']);
        try {
            app(ManageCategoriesHandler::class)->saveImported((string) $actor->id, null, new SaveCategoryData(null, 1, 'disabled', ['en' => ['name' => 'Denied', 'slug' => 'denied']]));
            self::fail('Expected import permission rejection');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('categories', 1);
        }
        try {
            app(ManageCategoriesHandler::class)->saveImported((string) $this->actor->id, null, new SaveCategoryData(null, 1, 'disabled', ['fr' => ['name' => 'Inactive', 'slug' => 'inactive']]));
            self::fail('Expected inactive locale rejection');
        } catch (ValidationException) {
            $this->assertDatabaseCount('categories', 1);
        }
    }

    public function test_create_hierarchy_and_all_current_translated_and_age_fields(): void
    {
        LocaleRecord::where('code', 'el')->update(['is_active' => true]);
        $headers = app(CategoryImportFields::class)->columns();
        $values = array_fill_keys($headers, '');
        $values = [...$values, 'category_path' => 'Technology///Gaming///Chairs', 'position' => '3', 'status' => 'active', 'age_verification' => 'true', 'age_limit' => '18', 'name.en' => 'Chairs', 'name.el' => 'Καρέκλες', 'remove_image' => 'false'];
        foreach (['en', 'el'] as $code) {
            foreach (array_diff(CategoryImportFields::TRANSLATED, ['name', 'slug']) as $field) {
                $values[$field.'.'.$code] = $field.' '.$code;
            }
        }
        $csv = implode(',', $headers)."\n";
        foreach (['Technology', 'Technology///Gaming'] as $path) {
            $parts = explode('///', $path);
            $name = end($parts);
            $parent = [...$values, 'category_path' => $path, 'name.en' => $name, 'name.el' => 'Ελληνικό '.$name, 'position' => '1', 'status' => 'disabled', 'age_verification' => 'false', 'age_limit' => ''];
            $csv .= implode(',', $parent)."\n";
        }
        $csv .= implode(',', $values)."\n";
        $token = $this->preview($csv);
        self::assertSame([], $this->report($token)['issues']);
        self::assertSame('completed', $this->confirm($token)['status']);
        $leaf = CategoryRecord::whereHas('translations', fn ($q) => $q->where('name', 'Chairs'))->sole();
        self::assertTrue($leaf->age_verification);
        self::assertSame(18, $leaf->age_limit);
        self::assertSame(3, $leaf->position);
        self::assertCount(3, app(CategoryHierarchy::class)->pathIds((string) $leaf->id));
        foreach ($leaf->translations()->with('locale')->get() as $translation) {
            foreach (array_diff(CategoryImportFields::TRANSLATED, ['name', 'slug']) as $field) {
                self::assertSame($field.' '.$translation->locale->code, $translation->$field);
            }
        }
        self::assertSame(2, CategoryRecord::whereNull('system_key')->where('status', 'disabled')->count());
        $this->post('/admin/categories/import/'.$token.'/confirm', ['confirmed' => true])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('categories', 4);
    }

    public function test_partial_updates_preserve_omitted_fields_and_languages_and_clear_explicit_text(): void
    {
        $category = $this->create();
        $category->update(['exclude_from_sitemap' => true]);
        $id = $category->id;
        $this->singleLocale('el');
        $token = $this->preview("category_id,name.el,meta_keywords.el,age_warning_message.el,age_verification,age_limit,status,position\n$id,Νέα κατηγορία,λέξεις,Απαγορεύεται,true,21,disabled,4\n");
        self::assertSame(0, $this->report($token)['errors']);
        self::assertSame(1, $this->confirm($token)['updated']);
        $category->refresh();
        self::assertTrue($category->exclude_from_sitemap);
        $en = $category->translations()->whereHas('locale', fn ($q) => $q->where('code', 'en'))->sole();
        $el = $category->translations()->whereHas('locale', fn ($q) => $q->where('code', 'el'))->sole();
        self::assertSame('Original', $en->name);
        self::assertSame('Keep description', $en->description);
        self::assertSame('Διατήρηση', $el->description);
        self::assertSame('Απαγορεύεται', $el->age_warning_message);
        self::assertSame('λέξεις', $el->meta_keywords);
        self::assertSame(21, $category->age_limit);
        self::assertSame(4, $category->position);
        self::assertSame('disabled', $category->status);
        $this->singleLocale('en');
        $token = $this->preview("category_id,description.en,age_verification,age_limit\n$id,,false,\n");
        $this->confirm($token);
        self::assertSame('', $en->fresh()->description);
        self::assertSame('Διατήρηση', $el->fresh()->description);
        self::assertNull($category->fresh()->age_limit);
        $token = $this->preview("category_id,status\n$id,disabled\n");
        self::assertSame(1, $this->confirm($token)['skipped']);
    }

    public function test_utf8_bom_semicolon_quoted_commas_quotes_and_multiline_values(): void
    {
        $this->singleLocale('el');
        $token = $this->preview("\xEF\xBB\xBFname.el;description.el\r\n\"Καρέκλες, Gaming\";\"Γραμμή 1\nΓραμμή 2, με \"\"εισαγωγικά\"\"\"\r\n", ';');
        self::assertSame(0, $this->report($token)['errors']);
        $this->confirm($token);
        self::assertSame("Γραμμή 1\nΓραμμή 2, με \"εισαγωγικά\"", html_entity_decode(DB::table('category_translations')->where('name', 'Καρέκλες, Gaming')->value('description'), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    public function test_id_is_authoritative_and_updates_move_rename_and_preserve_translations(): void
    {
        $root = $this->create('Root');
        $child = $this->create('Child', (string) $root->id);
        $token = $this->preview("category_id,category_path,status\n{$child->id},New parent///Renamed,active\n");
        self::assertSame(0, $this->report($token)['errors']);
        $this->confirm($token);
        self::assertSame('Renamed', DB::table('category_translations')->where('category_id', $child->id)->where('locale_id', 1)->value('name'));
        self::assertSame('Ελληνικό Child', DB::table('category_translations')->where('category_id', $child->id)->where('locale_id', 2)->value('name'));
        self::assertNotSame($root->id, $child->fresh()->parent_id);
        self::assertSame('Root', DB::table('category_translations')->where('category_id', $root->id)->where('locale_id', 1)->value('name'));
        $token = $this->preview("category_id,category_path\n{$child->id},Renamed\n");
        $this->confirm($token);
        self::assertNull($child->fresh()->parent_id);
    }

    public function test_numeric_position_collision_uses_existing_deterministic_shift_rules(): void
    {
        $original = $this->create('Existing');
        $token = $this->preview("name.en,position\nFirst,1\nSecond,1\nThird,\n");
        self::assertSame(0, $this->report($token)['errors']);
        $this->confirm($token);
        self::assertSame(3, $original->fresh()->position);
        self::assertSame(4, CategoryRecord::whereHas('translations', fn ($q) => $q->where('name', 'Third'))->sole()->position);
    }

    public function test_invalid_headers_rows_values_slugs_and_translations_are_reported_before_writes(): void
    {
        $badFiles = [
            "name_en\nNo\n", "name.en,name.en\nOne,Two\n", "system_key,name.en\nuncategorized,No\n",
            "name.fr\nNon\n", "name.en,status\nExample,archived\n", "name.en,position\nExample,-1\n",
            "name.en,position\nExample,1.5\n", "name.en,age_verification\nExample,true\n",
            "name.en,age_limit\nExample,0\n", "name.en,age_verification\nExample,yes\n",
            "name.en,slug.en\nExample,###\n", "name.el\nΜόνο ελληνικά\n",
            "name.en,name.el\nExample,\"\"\"\n", "name.en\nOne,Two\n", "name.en\n\"Unclosed\n",
            "name.en\nBad\"quote\n", "name.en\n\xFF\n", "name.en\n", "name.en,category_path\nLeaf,A//////Leaf\n",
            "name.en,category_path\nDifferent,A///Leaf\n", "name.en,meta_keywords.en\nExample,".str_repeat('x', 1001)."\n",
            "name.en,age_warning_message.el\nExample,".str_repeat('x', 2001)."\n",
        ];
        foreach ($badFiles as $csv) {
            $token = $this->preview($csv);
            self::assertGreaterThan(0, $this->report($token)['errors'], $csv);
            self::assertArrayHasKey('column', $this->report($token)['issues'][0]);
            $this->post('/admin/categories/import/'.$token.'/confirm', ['confirmed' => true])->assertSessionHasErrors('file');
            session()->forget('errors');
        }
        $this->assertDatabaseCount('categories', 1);
    }

    public function test_duplicate_ids_creation_paths_and_unknown_ids_are_rejected(): void
    {
        $category = $this->create();
        foreach (["category_id,status\n{$category->id},disabled\n{$category->id},active\n", "name.en,category_path\nSame,Same\nSame,Same\n", "category_id\n999999\n", "name.en,category_path\nOriginal,Original\n"] as $csv) {
            $token = $this->preview($csv);
            self::assertGreaterThan(0, $this->report($token)['errors']);
        }
        self::assertSame('active', $category->fresh()->status);
    }

    public function test_cycles_self_parenting_ambiguous_paths_and_uncategorized_are_rejected(): void
    {
        $root = $this->create('Root');
        $child = $this->create('Child', (string) $root->id);
        $system = CategoryRecord::whereNotNull('system_key')->sole();
        foreach (["category_id,category_path\n{$root->id},Root///Root\n", "category_id,category_path\n{$root->id},Root///Child///Root\n", "category_id,name.en\n{$system->id},Changed\n"] as $csv) {
            $token = $this->preview($csv);
            self::assertGreaterThan(0, $this->report($token)['errors']);
        }
        app(ManageCategoriesHandler::class)->save((string) $this->actor->id, null, new SaveCategoryData(null, 2, 'active', ['en' => ['name' => 'Root', 'slug' => 'root-duplicate']]));
        $token = $this->preview("category_path\nRoot///New\n");
        self::assertStringContainsString('multiple siblings', $this->report($token)['issues'][0]['reason']);
        self::assertSame($root->id, $child->fresh()->parent_id);
    }

    public function test_import_requires_dedicated_and_operation_capabilities_and_token_ownership(): void
    {
        $this->actingAs($this->staff(['admin.access', 'categories.view']))->get('/admin/categories/import')->assertForbidden();
        $this->post('/admin/categories/import/preview')->assertForbidden();
        $this->actingAs($this->staff(['admin.access', 'categories.import']));
        $token = $this->preview("name.en\nDenied\n");
        self::assertSame(1, $this->report($token)['errors']);
        $this->actingAs($this->actor);
        $token = $this->preview("name.en\nAllowed\n");
        $other = $this->staff(['admin.access', 'categories.import', 'categories.create']);
        $this->actingAs($other)->withSession(['password_hash_web' => $other->getAuthPassword()]);
        $this->get('/admin/categories/import?token='.$token)->assertNotFound();
        $this->post('/admin/categories/import/'.$token.'/confirm', ['confirmed' => true])->assertNotFound();
        $this->actingAs($this->actor);
        $this->withSession(['password_hash_web' => $this->actor->getAuthPassword()]);
        $this->post('/admin/categories/import/'.$token.'/confirm')->assertSessionHasErrors('confirmed');
    }

    public function test_stale_catalog_file_and_revoked_capabilities_block_confirmation(): void
    {
        $token = $this->preview("name.en\nNew\n");
        $this->create('Other');
        self::assertSame('failed', $this->confirm($token)['status']);
        $this->assertDatabaseCount('categories', 2);
        $token = $this->preview("name.en\nNew\n");
        $entry = Cache::get('category-import:'.$token);
        Storage::disk('local')->put($entry['path'], "name.en\nTampered\n");
        self::assertSame('failed', $this->confirm($token)['status']);
        $token = $this->preview("name.en\nNew\n");
        $this->actor->roles()->sole()->permissions()->detach(DB::table('permissions')->where('name', 'categories.create')->value('id'));
        self::assertSame('failed', $this->confirm($token)['status']);
        $this->assertDatabaseCount('categories', 2);
    }

    public function test_business_rule_veto_rolls_back_entire_import_and_no_events_escape(): void
    {
        $existing = $this->create('Existing');
        $token = $this->preview("category_path\nNew root\nExisting///Vetoed child\n");
        self::assertSame(0, $this->report($token)['errors']);
        app(CategoryMutationParticipants::class)->add('test.veto', new class implements CategoryMutationParticipant
        {
            public function beforeParentUsed(string $parentCategoryId): void
            {
                throw new InvalidCategoryChange('categories.errors.parent');
            }

            public function beforeDeletion(CategoryDeletion $deletion): void {}
        });
        Event::fake([CategoryCreated::class]);
        $report = $this->confirm($token);
        self::assertSame('failed', $report['status']);
        self::assertSame(0, $report['created']);
        self::assertStringContainsString('Row 3', $report['issues'][0]['reason']);
        self::assertSame('Existing', $existing->translations()->where('locale_id', 1)->sole()->name);
        $this->assertDatabaseCount('categories', 2);
        Event::assertNotDispatched(CategoryCreated::class);
    }

    public function test_template_uses_current_canonical_fields_and_errors_download_is_formula_safe(): void
    {
        self::assertEqualsCanonicalizing(CategoryImportFields::TRANSLATED, array_values(array_diff((new CategoryTranslationRecord)->getFillable(), ['locale_id'])));
        // Sitemap exclusions are editor preferences; CSV updates preserve them without changing the existing template.
        self::assertEqualsCanonicalizing(['position', 'status', 'age_verification', 'age_limit', 'exclude_from_discovery'], array_values(array_diff((new CategoryRecord)->getFillable(), ['parent_id', 'exclude_from_sitemap'])));
        $response = $this->get('/admin/categories/import/template')->assertOk();
        $csv = $response->streamedContent();
        $header = str_getcsv(explode("\n", substr($csv, 3))[0], ',', '"', '');
        self::assertSame(app(CategoryImportFields::class)->columns(), $header);
        self::assertNotContains('exclude_from_sitemap', $header);
        self::assertContains('meta_keywords.en', $header);
        self::assertContains('age_warning_message.en', $header);
        $token = $this->preview("category_id\n=HYPERLINK(1)\n");
        $errors = $this->get('/admin/categories/import/'.$token.'/errors')->assertOk()->streamedContent();
        self::assertStringContainsString("'=HYPERLINK(1)", $errors);
        self::assertStringContainsString('category_id', $errors);
    }

    public function test_default_locale_path_is_independent_of_admin_language_and_future_locales_work(): void
    {
        $this->actor->preferred_locale_id = LocaleRecord::where('code', 'el')->value('id');
        $this->actor->save();
        $token = $this->preview("category_path\nEnglish root///English leaf\n");
        self::assertSame(0, $this->report($token)['errors']);
        $this->confirm($token);
        self::assertSame(1, DB::table('category_translations')->where('name', 'English leaf')->count());
        $this->singleLocale('fr');
        $token = $this->preview("category_path,name.fr\nRacine française///Feuille française,Feuille française\n");
        self::assertSame(0, $this->report($token)['errors']);
        $this->confirm($token);
        self::assertSame(1, DB::table('category_translations')->where('name', 'Feuille française')->count());
        $this->get('/admin/categories/import')->assertInertia(fn (Assert $p) => $p->where('i18n.locale', 'fr')->where('i18n.strings', fn ($strings) => $strings['category_import.title'] === 'Importer des catégories'));
    }

    public function test_limits_bounded_preview_and_expired_private_file_cleanup(): void
    {
        config(['category-import.max_rows' => 2]);
        $token = $this->preview("name.en\nOne\nTwo\nThree\n");
        self::assertGreaterThan(0, $this->report($token)['errors']);
        config(['category-import.max_rows' => 10000]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->preview("name.en\nSmall one\nSmall two\n");
        $smallQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        $csv = "name.en\n";
        for ($i = 1; $i <= 1000; $i++) {
            $csv .= 'Category '.$i."\n";
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $token = $this->preview($csv);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        self::assertSame(1000, $this->report($token)['created']);
        self::assertCount(30, $this->report($token)['sample']);
        self::assertLessThanOrEqual($smallQueryCount + 10, $queryCount, 'Preview queries must stay bounded when growing from 2 to 1000 rows.');
        $result = $this->confirm($token);
        self::assertSame('completed', $result['status']);
        self::assertSame(1000, $result['created']);
        $this->assertDatabaseCount('categories', 1001);
        $entry = Cache::get('category-import:'.$token);
        $this->delete('/admin/categories/import/'.$token)->assertRedirect();
        Storage::disk('local')->assertMissing($entry['path']);
        Storage::disk('local')->put('category-imports/expired.csv', 'private');
        touch(Storage::disk('local')->path('category-imports/expired.csv'), time() - 5000);
        $this->artisan('cms:category-import-prune')->assertSuccessful();
        Storage::disk('local')->assertMissing('category-imports/expired.csv');
    }

    public function test_import_can_remove_existing_private_image_without_deleting_its_original(): void
    {
        $this->post('/admin/categories', ['parent_id' => null, 'position' => 1, 'status' => 'active', 'remove_image' => false, 'translations' => ['en' => ['name' => 'With image', 'slug' => '']], 'image' => UploadedFile::fake()->createWithContent('category.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aNXsAAAAASUVORK5CYII='))])->assertSessionHasNoErrors();
        $category = CategoryRecord::whereNull('system_key')->sole();
        $key = $category->image->storage_key;
        $token = $this->preview("category_id,remove_image,image_alt.en\n{$category->id},true,Updated alternative text\n");
        self::assertSame(0, $this->report($token)['errors']);
        self::assertNotNull($category->fresh()->image_media_id);
        $this->confirm($token);
        self::assertNull($category->fresh()->image_media_id);
        self::assertSame('Updated alternative text', $category->translations()->sole()->image_alt);
        Storage::disk('local')->assertExists($key);
        foreach (['image_media_id', 'image_path', 'parent_age_limit', 'lock_version', 'created_at'] as $column) {
            $token = $this->preview($column.",category_id\nanything,{$category->id}\n");
            self::assertGreaterThan(0, $this->report($token)['errors']);
        }
    }

    private function remoteImages(): string
    {
        Http::preventStrayRequests();

        // Public IP literal avoids external DNS in deterministic HTTP fakes.
        return 'https://93.184.215.14/category.png';
    }

    private function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aNXsAAAAASUVORK5CYII=');
    }

    public function test_image_url_preview_is_read_only_and_confirm_downloads_once_and_updates_private_media(): void
    {
        $url = $this->remoteImages();
        $existing = $this->create();
        $upload = UploadedFile::fake()->createWithContent('old.png', $this->png());
        app(ManageCategoriesHandler::class)->saveImported((string) $this->actor->id, (string) $existing->id, new SaveCategoryData(null, 1, 'active', [], $existing->lock_version, image: new ImageUpload($upload->getPathname())));
        $oldImage = $existing->fresh()->image;
        Http::fake([$url => Http::response($this->png(), 200, ['Content-Type' => 'image/png'])]);
        $token = $this->preview("category_id,name.en,image_url,image_alt.en\n$existing->id,Changed,$url,Category photo\n,New category,$url,New photo\n");
        self::assertSame(0, $this->report($token)['errors']);
        self::assertSame(2, $this->report($token)['images']);
        self::assertSame($oldImage->id, $existing->fresh()->image_media_id);
        Http::assertNothingSent();
        self::assertSame('completed', $this->confirm($token)['status']);
        Http::assertSentCount(1);
        $image = $existing->fresh()->image;
        self::assertSame('image/png', $image->mime_type);
        self::assertSame($this->png(), Storage::disk('local')->get($image->storage_key));
        self::assertSame('Category photo', $existing->translations()->where('locale_id', 1)->sole()->image_alt);
        $this->assertDatabaseCount('media_files', 3);
        self::assertNotSame($oldImage->id, $image->id);
        Storage::disk('local')->assertExists($oldImage->storage_key);
        $token = $this->preview("category_id,image_url\n$existing->id,\n");
        self::assertSame(1, $this->report($token)['skipped']);
        self::assertSame('completed', $this->confirm($token)['status']);
        self::assertSame($image->id, $existing->fresh()->image_media_id);
        Http::assertSentCount(1);
    }

    public function test_stale_image_import_does_not_download_before_catalog_validation(): void
    {
        $url = $this->remoteImages();
        $token = $this->preview("name.en,image_url\nNew,$url\n");
        $this->create('Intervening edit');
        self::assertSame('failed', $this->confirm($token)['status']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('media_files', 0);
    }

    public function test_bad_image_urls_and_remove_conflicts_are_row_errors_without_downloads(): void
    {
        Http::preventStrayRequests();
        foreach (['file:///tmp/photo.png', 'http://127.0.0.1/image.png', 'https://[::1]/image.png', 'http://169.254.169.254/latest', 'https://user:pass@example.com/a.png', 'https://example.com:8443/a.png', 'https://example.com/a.png#fragment', 'not-a-url'] as $url) {
            $token = $this->preview("name.en,image_url\nExample,$url\n");
            self::assertSame('image_url', $this->report($token)['issues'][0]['column']);
            self::assertSame(2, $this->report($token)['issues'][0]['row']);
        }
        $url = $this->remoteImages();
        $token = $this->preview("name.en,image_url,remove_image\nExample,$url,true\n");
        self::assertSame('image_url', $this->report($token)['issues'][0]['column']);
        config(['category-import.max_image_urls' => 1]);
        $token = $this->preview("name.en,image_url\nFirst,$url\nSecond,$url\n");
        self::assertSame(3, $this->report($token)['issues'][0]['row']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('categories', 1);
    }

    public function test_remote_failure_invalid_content_and_download_limits_leave_all_categories_unchanged(): void
    {
        $url = $this->remoteImages();
        $existing = $this->create();
        Http::fakeSequence($url)
            ->push('', 302, ['Location' => 'http://127.0.0.1/private'])
            ->push('', 404)
            ->push('<html>Not an image</html>')
            ->push($this->png().str_repeat('x', 5242880))
            ->push($this->png());
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $token = $this->preview("category_id,name.en,image_url\n$existing->id,Changed,\n,New category,$url\n");
            $result = $this->confirm($token);
            self::assertSame('failed', $result['status']);
            self::assertSame(3, $result['issues'][0]['row']);
            self::assertSame('image_url', $result['issues'][0]['column']);
            self::assertSame('Original', $existing->translations()->where('locale_id', 1)->sole()->name);
            $this->assertDatabaseCount('categories', 2);
            $this->assertDatabaseCount('media_files', 0);
            self::assertSame([], Storage::disk('local')->allFiles('images'));
        }
        config(['category-import.max_image_bytes' => 1]);
        $token = $this->preview("name.en,image_url\nNew,$url\n");
        self::assertSame('failed', $this->confirm($token)['status']);
        $this->assertDatabaseCount('categories', 2);
        Http::assertSentCount(5);
    }

    public function test_image_download_rechecks_catalog_after_network_and_cleans_files_after_outer_rollback(): void
    {
        $url = $this->remoteImages();
        $existing = $this->create('Existing');
        $requests = 0;
        Http::fake([$url => function () use ($existing, &$requests) {
            if (++$requests === 1) {
                CategoryRecord::whereKey($existing->id)->increment('lock_version');
            }

            return Http::response($this->png());
        }]);
        $token = $this->preview("name.en,image_url\nNew,$url\n");
        self::assertSame('failed', $this->confirm($token)['status']);
        $this->assertDatabaseCount('media_files', 0);
        $token = $this->preview("category_path,image_url\nNew root,$url\nExisting///Vetoed child,\n");
        app(CategoryMutationParticipants::class)->add('test.veto', new class implements CategoryMutationParticipant
        {
            public function beforeParentUsed(string $parentCategoryId): void
            {
                throw new InvalidCategoryChange('categories.errors.parent');
            }

            public function beforeDeletion(CategoryDeletion $deletion): void {}
        });
        $result = $this->confirm($token);
        self::assertSame('failed', $result['status']);
        self::assertStringContainsString('Row 3', $result['issues'][0]['reason']);
        $this->assertDatabaseCount('categories', 2);
        $this->assertDatabaseCount('media_files', 0);
        self::assertSame([], Storage::disk('local')->allFiles('images'));
        Http::assertSentCount(2);
    }

    public function test_update_only_importer_cannot_create_parents_or_bypass_csrf_and_upload_limits(): void
    {
        $category = $this->create();
        $user = $this->staff(['admin.access', 'categories.import', 'categories.update']);
        $this->actingAs($user);
        $token = $this->preview("category_id,status\n{$category->id},disabled\n");
        self::assertSame('completed', $this->confirm($token)['status']);
        $token = $this->preview("category_id,category_path\n{$category->id},Missing parent///Original\n");
        self::assertGreaterThan(0, $this->report($token)['errors']);
        $this->post('/admin/categories/import/preview', ['file' => UploadedFile::fake()->create('huge.csv', 20481), 'delimiter' => ',', 'path_separator' => '///'])->assertSessionHasErrors('file');
        $this->post('/admin/categories/import/preview', ['file' => UploadedFile::fake()->createWithContent('bad.csv', 'name.en'), 'delimiter' => '|', 'path_separator' => '../en'])->assertSessionHasErrors(['delimiter', 'path_separator']);
        $this->app['env'] = 'production';
        $this->post('/admin/categories/import/preview')->assertStatus(419);
        $this->app['env'] = 'testing';
    }

    public function test_import_events_wait_for_outer_commit_and_listener_failure_does_not_report_rollback(): void
    {
        $token = $this->preview("name.en\nEvent category\n");
        $events = [];
        Event::listen(CategoryCreated::class, function (CategoryCreated $event) use (&$events): void {
            $events[] = $event;
            throw new \RuntimeException('Optional listener unavailable');
        });
        DB::beginTransaction();
        try {
            $this->confirm($token);
            self::assertSame([], $events);
            DB::commit();
        } finally {
            if (DB::transactionLevel() > 1) {
                DB::rollBack();
            }
        }
        self::assertCount(1, $events);
        self::assertSame('completed', $this->report($token)['status']);
        $this->assertDatabaseCount('categories', 2);
    }

    public function test_documented_csv_example_matches_the_template_and_can_be_imported(): void
    {
        LocaleRecord::where('code', 'el')->update(['is_active' => true]);
        $csv = file_get_contents(base_path('docs/examples/category-import.csv'));
        $header = str_getcsv(explode("\n", $csv)[0], ',', '"', '');
        self::assertSame(app(CategoryImportFields::class)->columns(), $header);
        $token = $this->preview($csv);
        self::assertSame(0, $this->report($token)['errors']);
        self::assertSame('completed', $this->confirm($token)['status']);
    }

    public function test_customer_groups_and_discovery_exclusion_are_imported_preserved_and_cleared(): void
    {
        $groups = [];
        foreach (['Retail', 'Wholesale'] as $name) {
            $groups[] = (string) DB::table('customer_groups')->insertGetId(['status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        }
        $category = $this->create();
        $token = $this->preview("category_id,exclude_from_discovery,customer_groups\n{$category->id},true,\"{$groups[1]}, {$groups[0]}\"\n");
        self::assertSame(0, $this->report($token)['errors']);
        $this->confirm($token);
        self::assertTrue($category->fresh()->exclude_from_discovery);
        self::assertSame($groups, DB::table('category_customer_groups')->where('category_id', $category->id)->orderBy('group_id')->pluck('group_id')->map(strval(...))->all());

        // Empty cells keep the current values; re-importing the same set is skipped, not rewritten.
        $token = $this->preview("category_id,exclude_from_discovery,customer_groups\n{$category->id},,{$groups[0]};{$groups[1]}\n");
        self::assertSame(1, $this->report($token)['skipped']);
        $this->confirm($token);
        self::assertTrue($category->fresh()->exclude_from_discovery);
        self::assertSame(2, DB::table('category_customer_groups')->where('category_id', $category->id)->count());

        $token = $this->preview("category_id,exclude_from_discovery,customer_groups\n{$category->id},false,all\n");
        $this->confirm($token);
        self::assertFalse($category->fresh()->exclude_from_discovery);
        self::assertSame(0, DB::table('category_customer_groups')->where('category_id', $category->id)->count());

        foreach (['99999', 'retail', $groups[0].',0'] as $invalid) {
            $token = $this->preview("category_id,customer_groups\n{$category->id},\"{$invalid}\"\n");
            self::assertSame('customer_groups', $this->report($token)['issues'][0]['column'], $invalid);
        }
        $token = $this->preview("category_id,exclude_from_discovery\n{$category->id},maybe\n");
        self::assertSame('exclude_from_discovery', $this->report($token)['issues'][0]['column']);
    }
}
