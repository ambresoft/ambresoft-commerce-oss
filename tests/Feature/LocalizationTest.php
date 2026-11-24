<?php

namespace Tests\Feature;

use App\Localization\Infrastructure\Persistence\LocaleRecord;
use App\Localization\Infrastructure\UiTranslations;
use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Inertia\Testing\AssertableInertia as Assert;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class LocalizationTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
    }

    public function test_english_is_default_and_greek_is_available_without_a_two_language_schema(): void
    {
        $this->get('/login')->assertOk()->assertInertia(fn (Assert $p) => $p->where('i18n.locale', 'en')->where('i18n.strings', fn ($v) => $v['auth.login_title'] === 'Welcome back')->has('i18n.locales', 2));
        LocaleRecord::create(['code' => 'de', 'name' => 'German', 'native_name' => 'Deutsch']);
        $this->assertDatabaseCount('locales', 7);
    }

    public function test_preference_persists_and_current_admin_page_uses_greek_after_refresh(): void
    {
        $user = $this->staff(['admin.access', 'users.view']);
        $this->actingAs($user)->patch('/admin/locale', ['locale' => 'el', 'return_to' => '/admin/users?status=active'])->assertRedirect('/admin/users?status=active');
        self::assertSame('el', $user->fresh()->preferredLocale());
        $this->get('/admin')->assertOk()->assertInertia(fn (Assert $p) => $p->where('i18n.locale', 'el')->where('i18n.strings', fn ($v) => $v['admin.dashboard.title'] === 'Πίνακας ελέγχου')->where('navigation.0.label', 'Πίνακας ελέγχου'));
        $this->get('/admin/users')->assertOk()->assertInertia(fn (Assert $p) => $p->where('i18n.strings', fn ($v) => $v['users.title'] === 'Χρήστες διαχείρισης'));
        $this->patch('/admin/locale', ['locale' => 'en', 'return_to' => '/admin'])->assertRedirect('/admin');
        $this->get('/admin')->assertInertia(fn (Assert $p) => $p->where('i18n.locale', 'en'));
    }

    public function test_guests_use_the_persisted_system_default_not_a_staff_preference(): void
    {
        LocaleRecord::where('is_default', true)->update(['is_default' => false]);
        LocaleRecord::where('code', 'el')->update(['is_default' => true]);
        $this->get('/login')->assertInertia(fn (Assert $p) => $p->where('i18n.locale', 'el'));
    }

    public function test_unsupported_path_like_locales_and_external_redirects_are_rejected(): void
    {
        $this->actingAs($this->staff());
        foreach (['../en', 'EN', 'en_US', 'de', 'el::auth'] as $code) {
            $this->patch('/admin/locale', ['locale' => $code, 'return_to' => '/admin'])->assertSessionHasErrors('locale');
        }
        LocaleRecord::where('code', 'el')->update(['is_active' => false]);
        $this->patch('/admin/locale', ['locale' => 'el', 'return_to' => '/admin'])->assertSessionHasErrors('locale');
        foreach (['https://attacker.test', '//attacker.test', '/administrator', '/admin\\evil'] as $url) {
            $this->patch('/admin/locale', ['locale' => 'en', 'return_to' => $url])->assertSessionHasErrors('return_to');
        }
    }

    public function test_catalog_deactivation_preserves_ui_preference_and_locale_does_not_leak_between_requests(): void
    {
        $user = $this->staff();
        $user->preferred_locale_id = LocaleRecord::where('code', 'el')->value('id');
        $user->save();
        $this->actingAs($user)->get('/admin')->assertInertia(fn (Assert $p) => $p->where('i18n.locale', 'el'));
        LocaleRecord::where('code', 'el')->update(['is_active' => false]);
        $this->get('/admin')->assertInertia(fn (Assert $p) => $p->where('i18n.locale', 'en')->has('i18n.locales', 1));
        self::assertSame(LocaleRecord::where('code', 'el')->value('id'), $user->fresh()->preferred_locale_id);
        LocaleRecord::where('code', 'el')->update(['is_active' => true]);
        $this->get('/admin')->assertInertia(fn (Assert $p) => $p->where('i18n.locale', 'el'));
        $this->post('/logout');
        $this->get('/login')->assertInertia(fn (Assert $p) => $p->where('i18n.locale', 'en'));
    }

    public function test_validation_and_authenticated_notifications_are_localized(): void
    {
        $user = $this->staff(super: true);
        $user->preferred_locale_id = LocaleRecord::where('code', 'el')->value('id');
        $user->save();
        $this->actingAs($user)->post('/admin/roles', ['name' => '', 'slug' => '', 'permissions' => []])->assertSessionHasErrors(['name' => 'Το πεδίο όνομα είναι υποχρεωτικό.']);
        $this->post('/admin/roles', ['name' => 'Δοκιμή', 'slug' => 'test-greek', 'permissions' => ['admin.access']])->assertSessionHas('status', 'Ο ρόλος αποθηκεύτηκε.');
    }

    public function test_missing_ui_keys_fall_back_and_real_dictionaries_have_matching_complete_keys(): void
    {
        app('translator')->addLines(['admin.only_english' => 'English fallback'], 'en');
        self::assertSame('English fallback', app(UiTranslations::class)->forLocale('el')['admin.only_english']);
        foreach (['common', 'admin', 'navigation', 'auth', 'users', 'roles', 'permissions', 'authorization', 'categories', 'category_import', 'currencies', 'variations', 'media', 'settings', 'taxes', 'customers'] as $group) {
            $english = Arr::dot(require lang_path('en/'.$group.'.php'));
            $greek = Arr::dot(require lang_path('el/'.$group.'.php'));
            self::assertEqualsCanonicalizing(array_keys($english), array_keys($greek), $group);
            foreach ($greek as $key => $text) {
                self::assertIsString($text);
                self::assertNotSame('', $text);
                self::assertNotSame($group.'.'.$key, $text);
            }
        }
    }

    public function test_enabled_module_provider_loads_namespaced_language_files_without_editing_core(): void
    {
        $this->app->register(TestTranslationProvider::class);
        $user = $this->staff();
        $user->preferred_locale_id = LocaleRecord::where('code', 'el')->value('id');
        $user->save();
        $this->actingAs($user)->get('/admin')->assertInertia(fn (Assert $p) => $p->where('i18n.strings', fn ($v) => $v['test-addon::ui.title'] === 'Πρόσθετο δοκιμής'));
        self::assertSame('Πρόσθετο δοκιμής', __('test-addon::ui.title'));
    }

    public function test_react_literal_translation_keys_exist_in_the_server_dictionary(): void
    {
        $dictionary = app(UiTranslations::class)->forLocale('el');
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js'), FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (str_contains($file->getPathname(), '/tests/') || ! in_array($file->getExtension(), ['tsx', 'ts'], true)) {
                continue;
            }
            preg_match_all("/\\bt\\('([a-z][a-z0-9_.:-]+)'/", file_get_contents($file->getPathname()), $matches);
            foreach ($matches[1] as $key) {
                if (str_ends_with($key, '.')) {
                    continue;
                }
                self::assertArrayHasKey($key, $dictionary, $file->getPathname());
            }
        }
    }

    public function test_phase_two_migration_round_trip_preserves_existing_identity_data(): void
    {
        $searchPath = DB::scalar('SHOW search_path');
        try {
            // Exercise Phase 2 against its actual predecessor, independent of later context tables.
            DB::transaction(function (): void {
                DB::statement('CREATE SCHEMA localization_round_trip');
                DB::statement('SET LOCAL search_path TO localization_round_trip');
                $authentication = require database_path('migrations/2026_10_08_000100_create_authentication_tables.php');
                $migration = require database_path('migrations/2026_10_08_000200_create_authorization_and_localization_tables.php');
                $authentication->up();
                $id = DB::table('users')->insertGetId(['name' => 'Existing identity', 'email' => 'existing@example.test', 'password' => 'existing-password-hash']);
                $before = (array) DB::table('users')->where('id', $id)->first();

                $migration->up();
                $role = DB::table('roles')->insertGetId(['name' => 'Staff', 'slug' => 'staff']);
                DB::table('role_user')->insert(['role_id' => $role, 'user_id' => $id]);
                DB::table('users')->where('id', $id)->update(['preferred_locale_id' => DB::table('locales')->where('code', 'el')->value('id')]);
                $migration->down();
                self::assertFalse(Schema::hasTable('roles'));
                self::assertFalse(Schema::hasTable('locales'));
                self::assertSame($before, (array) DB::table('users')->where('id', $id)->first());

                $migration->up();
                self::assertTrue(Schema::hasTable('roles'));
                self::assertSame(['en', 'el'], DB::table('locales')->orderBy('sort_order')->pluck('code')->all());
                $after = (array) DB::table('users')->where('id', $id)->first();
                self::assertSame($before, array_intersect_key($after, $before));
                self::assertTrue($after['is_active']);
                self::assertNull($after['preferred_locale_id']);
                $migration->down();
                $authentication->down();
                DB::statement('DROP SCHEMA localization_round_trip');
            });
        } finally {
            DB::select("SELECT set_config('search_path', ?, true)", [$searchPath]);
        }
    }
}

final class TestTranslationProvider extends ServiceProvider
{
    public function boot(UiTranslations $ui): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../Fixtures/ModuleTranslations', 'test-addon');
        $ui->addGroup('test-addon::ui');
    }
}
