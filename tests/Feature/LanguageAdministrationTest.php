<?php

namespace Tests\Feature;

use App\Catalog\Application\Command\ManageCategoriesHandler;
use App\Catalog\Application\Command\SaveCategoryData;
use App\Catalog\Infrastructure\Import\CategoryImportFields;
use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Localization\Application\Command\ManageLanguagesHandler;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Localization\Infrastructure\Persistence\LocaleRecord;
use App\Localization\Infrastructure\TranslationDictionary;
use App\Localization\Infrastructure\UiTranslations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class LanguageAdministrationTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        Storage::fake('local');
    }

    /** @return array<string, mixed> */
    private function category(): array
    {
        $translations = [];
        foreach (app(LocaleCatalog::class)->active() as $locale) {
            $translations[$locale->code] = array_fill_keys(CategoryImportFields::TRANSLATED, $locale->code === 'el' ? 'Ελληνικό περιεχόμενο' : 'English content');
            $translations[$locale->code]['slug'] = 'example-'.$locale->code;
        }

        return ['position' => 1, 'parent_id' => null, 'status' => 'active', 'remove_image' => false, 'translations' => $translations];
    }

    public function test_category_image_upload_does_not_require_optional_description_or_age_warning_in_multiple_languages(): void
    {
        $actor = $this->staff(super: true);
        $data = $this->category();
        foreach ($data['translations'] as &$text) {
            unset($text['age_warning_message'], $text['description']);
        }
        unset($text);
        $this->actingAs($actor)->post('/admin/categories', [...$data, 'age_verification' => false, 'age_limit' => null])->assertSessionHasNoErrors();
        $category = CategoryRecord::whereNull('system_key')->sole();
        $before = $category->translations()->orderBy('locale_id')->get()->toArray();
        $image = fn (): UploadedFile => UploadedFile::fake()->createWithContent('category.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aNXsAAAAASUVORK5CYII='));
        // Multipart image-only saves omit an empty translations object, as the editor does.
        $this->post('/admin/categories/'.$category->id, ['_method' => 'put', 'parent_id' => null, 'position' => 1, 'status' => 'active', 'version' => $category->lock_version, 'remove_image' => false, 'age_verification' => false, 'age_limit' => '', 'image' => $image()])->assertSessionHasNoErrors();
        $category->refresh();
        self::assertNotNull($category->image_media_id);
        self::assertFalse($category->age_verification);
        Storage::disk('local')->assertExists($category->image->storage_key);
        self::assertSame($before, $category->translations()->orderBy('locale_id')->get()->toArray());
        // Optional warning text also stays optional when the actual age restriction is enabled.
        $this->post('/admin/categories/'.$category->id, ['_method' => 'put', 'parent_id' => null, 'position' => 1, 'status' => 'active', 'version' => $category->lock_version, 'remove_image' => false, 'age_verification' => true, 'age_limit' => 18, 'image' => $image()])->assertSessionHasNoErrors();
        self::assertTrue($category->fresh()->age_verification);
        self::assertSame(18, $category->fresh()->age_limit);
        self::assertSame($before, $category->translations()->orderBy('locale_id')->get()->toArray());
    }

    public function test_category_descriptions_can_be_cleared_without_copying_other_languages(): void
    {
        $actor = $this->staff(super: true);
        $data = $this->category();
        $this->actingAs($actor)->post('/admin/categories', $data)->assertSessionHasNoErrors();
        $category = CategoryRecord::whereNull('system_key')->sole();
        $greek = $category->translations()->where('locale_id', 2)->sole()->getAttributes();
        $english = $data['translations']['en'];
        $english['description'] = '';
        $this->put('/admin/categories/'.$category->id, [...$data, 'version' => $category->lock_version, 'translations' => ['en' => $english]])->assertSessionHasNoErrors();
        self::assertSame('', $category->translations()->where('locale_id', 1)->sole()->description);
        self::assertSame($greek, $category->translations()->where('locale_id', 2)->sole()->getAttributes());
    }

    public function test_language_pool_permissions_and_settings_are_server_enforced(): void
    {
        $editor = $this->staff(['admin.access', 'categories.create']);
        $this->actingAs($editor)->get('/admin/settings/languages')->assertForbidden();
        $this->put('/admin/settings/languages', ['active' => ['el'], 'default' => 'el'])->assertForbidden();
        $this->get('/admin/settings/languages/translations')->assertForbidden();
        $this->put('/admin/settings/languages/translations', ['locale' => 'en', 'key' => 'common.save', 'value' => 'Publish'])->assertForbidden();
        $manager = $this->staff(['admin.access', 'languages.manage']);
        $this->actingAs($manager)->withSession(['password_hash_web' => $manager->getAuthPassword()])->get('/admin/settings/languages')->assertOk()->assertInertia(fn (Assert $p) => $p->has('languages', 6)->where('languages.0.code', 'en')->where('languages.0.default', true));
        $this->put('/admin/settings/languages', ['active' => ['el'], 'default' => 'el'])->assertSessionHasNoErrors();
        self::assertSame(['el'], app(LocaleCatalog::class)->active()->pluck('code')->all());
        self::assertSame('el', app(LocaleCatalog::class)->defaultCode());
        foreach ([['active' => [], 'default' => 'en'], ['active' => ['de'], 'default' => 'de'], ['active' => ['en'], 'default' => 'el'], ['active' => ['en', 'en'], 'default' => 'en']] as $data) {
            $this->put('/admin/settings/languages', $data)->assertSessionHasErrors();
        }
        self::assertSame('el', app(LocaleCatalog::class)->defaultCode());
        $this->assertDatabaseCount('locales', 6);
        $this->app->instance('env', 'production');
        try {
            $this->put('/admin/settings/languages', ['active' => ['en'], 'default' => 'en'])->assertStatus(419);
            $this->put('/admin/settings/languages/translations', ['locale' => 'en', 'key' => 'common.save', 'value' => 'Publish'])->assertStatus(419);
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public function test_personal_language_uses_active_choices_and_saved_preference_survives_deactivation(): void
    {
        $manager = $this->staff(['admin.access', 'languages.manage']);
        $other = $this->staff();
        $this->actingAs($other)->patch('/admin/locale', ['locale' => 'fr', 'return_to' => '/admin'])->assertSessionHasErrors('locale');
        $this->patch('/admin/locale', ['locale' => 'el', 'return_to' => '/admin'])->assertSessionHasNoErrors()->assertRedirect('/admin');
        $this->get('/admin')->assertInertia(fn (Assert $p) => $p->where('i18n.locale', 'el')->has('i18n.locales', 2)->where('i18n.strings', fn ($s) => $s['navigation.dashboard'] === 'Πίνακας ελέγχου'));
        self::assertSame('el', $other->fresh()->preferredLocale());
        self::assertNull($manager->fresh()->preferred_locale_id);
        app(ManageLanguagesHandler::class)->configure((string) $manager->id, ['en'], 'en');
        $this->get('/admin')->assertInertia(fn (Assert $p) => $p->where('i18n.locale', 'en')->has('i18n.locales', 1));
        self::assertSame('en', $other->fresh()->preferredLocale());
        self::assertSame(LocaleRecord::where('code', 'el')->value('id'), $other->fresh()->preferred_locale_id);
        $this->patch('/admin/locale', ['locale' => 'el', 'return_to' => '/admin'])->assertSessionHasErrors('locale');
        app(ManageLanguagesHandler::class)->configure((string) $manager->id, ['en', 'el'], 'en');
        $this->get('/admin')->assertInertia(fn (Assert $p) => $p->where('i18n.locale', 'el')->has('i18n.locales', 2));
        $this->patch('/admin/locale', ['locale' => '../fr', 'return_to' => '/admin'])->assertSessionHasErrors('locale');
        $this->patch('/admin/locale', ['locale' => 'de', 'return_to' => '/admin'])->assertSessionHasErrors('locale');
    }

    public function test_general_localized_fields_are_required_in_every_active_language_and_updates_are_independent(): void
    {
        $actor = $this->staff(['admin.access', 'categories.create', 'categories.update']);
        $this->actingAs($actor);
        $data = $this->category();
        foreach (['en', 'el'] as $code) {
            foreach (array_diff(CategoryImportFields::TRANSLATED, ['slug', 'description', 'age_warning_message']) as $field) {
                $invalid = $data;
                $invalid['translations'][$code][$field] = '  ';
                $this->post('/admin/categories', $invalid)->assertSessionHasErrors('translations.'.$code.'.'.$field);
            }
        }
        $this->post('/admin/categories', [...$data, 'translations' => ['en' => $data['translations']['en']]])->assertSessionHasErrors('translations.el.name');
        $this->assertDatabaseCount('categories', 1);
        $this->post('/admin/categories', $data)->assertSessionHasNoErrors();
        $record = CategoryRecord::whereNull('system_key')->sole();
        $greek = $record->translations()->where('locale_id', 2)->sole()->getAttributes();
        $patch = $data['translations']['en'];
        $patch['description'] = 'Changed English';
        $this->put('/admin/categories/'.$record->id, [...$data, 'version' => $record->lock_version, 'translations' => ['en' => $patch]])->assertSessionHasNoErrors();
        self::assertSame($greek, $record->translations()->where('locale_id', 2)->sole()->getAttributes());
        try {
            app(ManageCategoriesHandler::class)->save((string) $actor->id, null, new SaveCategoryData(null, 2, 'active', ['en' => ['name' => 'Incomplete', 'slug' => 'incomplete']]));
            self::fail('Application entry points must enforce completeness too.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('translations.el.name', $e->errors());
        }
    }

    public function test_single_greek_catalog_exposes_only_greek_and_preserves_inactive_content(): void
    {
        $actor = $this->staff(super: true);
        $this->actingAs($actor)->post('/admin/categories', $this->category())->assertSessionHasNoErrors();
        $record = CategoryRecord::whereNull('system_key')->sole();
        $english = $record->translations()->where('locale_id', 1)->sole()->getAttributes();
        app(ManageLanguagesHandler::class)->configure((string) $actor->id, ['el'], 'el');
        $this->get('/admin/categories/'.$record->id.'/edit')->assertInertia(fn (Assert $p) => $p->has('i18n.locales', 1)->where('i18n.locales.0.code', 'el')->missing('category.translations.en'));
        $data = $this->category();
        $data['translations']['el']['name'] = 'Νέα κατηγορία';
        $this->put('/admin/categories/'.$record->id, [...$data, 'version' => $record->lock_version])->assertSessionHasNoErrors();
        self::assertSame($english, $record->translations()->where('locale_id', 1)->sole()->getAttributes());
        $data['translations']['en'] = ['name' => 'Forbidden inactive', 'slug' => 'forbidden'];
        $this->put('/admin/categories/'.$record->id, [...$data, 'version' => $record->fresh()->lock_version])->assertSessionHasErrors('translations');
    }

    public function test_activation_preserves_existing_categories_but_blocks_incomplete_saves(): void
    {
        $actor = $this->staff(super: true);
        app(ManageLanguagesHandler::class)->configure((string) $actor->id, ['en'], 'en');
        $data = $this->category();
        $this->actingAs($actor)->post('/admin/categories', $data)->assertSessionHasNoErrors();
        $record = CategoryRecord::whereNull('system_key')->sole();
        app(ManageLanguagesHandler::class)->configure((string) $actor->id, ['en', 'es'], 'en');
        $this->assertDatabaseCount('category_translations', 1);
        $this->put('/admin/categories/'.$record->id, [...$data, 'version' => $record->lock_version, 'translations' => []])->assertSessionHasErrors('translations.es.name');
        self::assertSame(1, $record->fresh()->lock_version);
    }

    public function test_dictionary_search_overrides_server_and_inertia_strings_and_restores_files(): void
    {
        $actor = $this->staff(['admin.access', 'languages.manage']);
        $this->actingAs($actor)->get('/admin/settings/languages/translations?locale=el&q=common.save')->assertInertia(fn (Assert $p) => $p->has('languages', 2)->where('languages.0.code', 'en')->where('languages.1.code', 'el')->has('rows', 1)->where('rows.0.key', 'common.save')->where('rows.0.overridden', false));
        $this->get('/admin/settings/languages/translations?locale=fr')->assertSessionHasErrors('locale');
        $this->put('/admin/settings/languages/translations', ['locale' => 'el', 'key' => 'common.save', 'value' => 'Αποθήκευση τώρα'])->assertSessionHasNoErrors();
        self::assertSame('Αποθήκευση τώρα', __('common.save', [], 'el'));
        self::assertSame('Αποθήκευση τώρα', app(UiTranslations::class)->forLocale('el')['common.save']);
        self::assertSame('Save changes', __('common.save', [], 'en'));
        foreach ([['locale' => '../en', 'key' => 'common.save', 'value' => 'Bad'], ['locale' => 'en', 'key' => 'unknown::bad.key', 'value' => 'Bad'], ['locale' => 'en', 'key' => 'common.save', 'value' => ' '], ['locale' => 'en', 'key' => 'admin.dashboard.welcome', 'value' => 'Missing placeholder']] as $bad) {
            $this->put('/admin/settings/languages/translations', $bad)->assertSessionHasErrors();
        }
        $this->put('/admin/settings/languages/translations', ['locale' => 'el', 'key' => 'common.save', 'restore' => true])->assertSessionHasNoErrors();
        self::assertSame('Αποθήκευση αλλαγών', __('common.save', [], 'el'));
        $this->assertDatabaseCount('ui_translation_overrides', 0);
    }

    public function test_all_six_dictionaries_cover_current_ui_with_preserved_placeholders(): void
    {
        $dictionary = app(TranslationDictionary::class);
        $base = $dictionary->originals('en');
        foreach (['en', 'el', 'es', 'fr', 'it', 'nl'] as $code) {
            $actual = $dictionary->originals($code);
            foreach ($base as $key => $value) {
                if (str_starts_with($key, 'validation.')) {
                    continue;
                } // Vendor rule catalog has a documented English fallback.
                self::assertArrayHasKey($key, $actual, $code.' '.$key);
                self::assertNotSame('', trim($actual[$key]));
                preg_match_all('/:[a-zA-Z_][a-zA-Z0-9_]*/', $value, $expected);
                preg_match_all('/:[a-zA-Z_][a-zA-Z0-9_]*/', $actual[$key], $found);
                sort($expected[0]);
                sort($found[0]);
                self::assertSame($expected[0], $found[0], $code.' '.$key);
            }
        }
    }

    public function test_csv_accepts_partial_translations_and_detects_language_changes(): void
    {
        $actor = $this->staff(super: true);
        $this->actingAs($actor);
        $preview = function (string $csv): string {
            $response = $this->post('/admin/categories/import/preview', ['file' => UploadedFile::fake()->createWithContent('categories.csv', $csv), 'delimiter' => ',', 'path_separator' => '///'])->assertSessionHasNoErrors();
            parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

            return $query['token'];
        };
        $partial = $preview("name.en\nIncomplete\n");
        self::assertSame(0, Cache::get('category-import:'.$partial)['report']['errors']);
        $template = $this->get('/admin/categories/import/template')->streamedContent();
        $valid = $preview($template);
        self::assertSame(0, Cache::get('category-import:'.$valid)['report']['errors']);
        app(ManageLanguagesHandler::class)->configure((string) $actor->id, ['en'], 'en');
        $this->post('/admin/categories/import/'.$valid.'/confirm', ['confirmed' => true])->assertRedirect();
        self::assertSame('failed', Cache::get('category-import:'.$valid)['report']['status']);
        $this->assertDatabaseCount('categories', 1);
    }

    public function test_language_migration_round_trip_retains_preferences_and_catalog_data(): void
    {
        $actor = $this->staff(super: true);
        $actor->preferred_locale_id = LocaleRecord::where('code', 'fr')->value('id');
        $actor->save();
        $migration = require database_path('migrations/2026_10_08_000500_add_language_administration.php');
        $migration->down();
        $migration->up();
        $this->assertDatabaseCount('locales', 6);
        self::assertSame('en', $actor->fresh()->preferredLocale());
        self::assertSame(LocaleRecord::where('code', 'fr')->value('id'), $actor->fresh()->preferred_locale_id);
        self::assertSame('en', app(LocaleCatalog::class)->defaultCode());
        $this->assertDatabaseHas('categories', ['system_key' => 'uncategorized']);
        $this->assertDatabaseCount('ui_translation_overrides', 0);
    }

    public function test_module_overrides_and_validation_wildcard_attributes_use_the_same_loader(): void
    {
        $this->app->register(LanguageReviewModuleProvider::class);
        $actor = $this->staff(super: true);
        $handler = app(ManageLanguagesHandler::class);
        $handler->override((string) $actor->id, 'el', 'test-addon::ui.title', 'Προσαρμοσμένο πρόσθετο');
        self::assertSame('Προσαρμοσμένο πρόσθετο', __('test-addon::ui.title', [], 'el'));
        self::assertSame('Προσαρμοσμένο πρόσθετο', app(UiTranslations::class)->forLocale('el')['test-addon::ui.title']);
        $handler->override((string) $actor->id, 'en', 'validation.attributes.role_ids.*', 'team role');
        self::assertSame('team role', app('translator')->get('validation.attributes', [], 'en')['role_ids.*']);
        $handler->override((string) $actor->id, 'en', 'validation.required', 'Please complete :attribute.');
        self::assertSame('Please complete Name.', __('validation.required', ['attribute' => 'Name'], 'en'));
    }
}

final class LanguageReviewModuleProvider extends ServiceProvider
{
    public function boot(UiTranslations $ui): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../Fixtures/ModuleTranslations', 'test-addon');
        $ui->addGroup('test-addon::ui');
    }
}
