<?php

namespace Tests\Feature;

use App\Audit\Infrastructure\AuditTables;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Media\Infrastructure\SharedMediaDeletion;
use App\Settings\Infrastructure\CompanySettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class CompanySettingsTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private UserRecord $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actor = $this->staff(['admin.access', 'settings.manage']);
        $this->actingAs($this->actor);
    }

    public function test_empty_company_settings_are_projected_with_localized_navigation_and_countries(): void
    {
        self::assertSame(1, DB::table('company_settings')->count());
        $this->get('/admin/settings/company')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Admin/Settings/Company')->where('settings.version', 1)->where('settings.name', null)
            ->where('countries', fn ($items) => collect($items)->contains('code', 'GR'))
            ->where('i18n.strings', fn ($strings) => $strings['company.title'] === 'Company data')
            ->where('navigation', fn ($items) => str_contains(json_encode($items, JSON_UNESCAPED_SLASHES), '/admin/settings/company')));
    }

    public function test_complete_business_details_save_atomically_with_meaningful_audit_changes(): void
    {
        $data = ['version' => 1, 'name' => ' Demo Company ', 'address' => '7 Example Street', 'city' => 'Athens', 'region' => 'Attica', 'country_code' => 'gr', 'postal_code' => '00123', 'phone' => '+30 210 1234567', 'mobile' => '+30 690 1234567', 'website' => 'https://example.com', 'opening_year' => 2021];
        foreach (['customers', 'admin', 'orders', 'support', 'newsletter'] as $purpose) {
            $data['email_'.$purpose] = $purpose.'@example.com';
        }
        $this->put('/admin/settings/company', $data)->assertSessionHasNoErrors()->assertSessionHas('status');
        $settings = app(CompanySettings::class)->all();
        self::assertSame('Demo Company', $settings['name']);
        self::assertSame('GR', $settings['country_code']);
        self::assertSame('00123', $settings['postal_code']);
        self::assertSame(2021, $settings['opening_year']);
        self::assertSame(2, $settings['version']);
        $events = DB::table('audit_logs')->where('entity_type', 'company_settings')->where('status', 'success')->get();
        self::assertCount(1, $events);
        self::assertSame((string) $this->actor->id, (string) $events[0]->actor_id);
        $changes = json_decode($events[0]->changes, true, flags: JSON_THROW_ON_ERROR);
        self::assertEquals(['before' => null, 'after' => 'Demo Company'], $changes['name']);
        self::assertArrayNotHasKey('lock_version', $changes);
        self::assertSame(1, (int) DB::table('general_settings')->value('lock_version'));
        self::assertArrayHasKey('company_settings', AuditTables::inventory());
    }

    public function test_invalid_contact_and_year_values_never_mutate_settings_or_log_success(): void
    {
        foreach (['email_orders' => 'invalid', 'website' => 'javascript:alert(1)', 'country_code' => 'XX', 'opening_year' => now()->year + 1, 'phone' => "bad\nphone", 'name' => str_repeat('a', 201)] as $field => $value) {
            $this->put('/admin/settings/company', ['version' => 1, 'name' => 'Must roll back', $field => $value])->assertSessionHasErrors($field);
        }
        self::assertNull(DB::table('company_settings')->value('name'));
        self::assertFalse(DB::table('audit_logs')->where('entity_type', 'company_settings')->where('status', 'success')->exists());
    }

    public function test_stale_saves_require_refresh_and_cannot_overwrite_a_newer_edit(): void
    {
        $this->put('/admin/settings/company', ['version' => 1, 'name' => 'First edit'])->assertSessionHasNoErrors();
        $this->put('/admin/settings/company', ['version' => 1, 'name' => 'Stale edit', 'city' => 'Athens'])->assertSessionHasErrors('version');
        self::assertSame('First edit', DB::table('company_settings')->value('name'));
        self::assertNull(DB::table('company_settings')->value('city'));
        self::assertSame(1, DB::table('audit_logs')->where('entity_type', 'company_settings')->where('status', 'success')->count());
    }

    public function test_optional_values_can_be_cleared_and_omitted_fields_are_preserved(): void
    {
        $this->put('/admin/settings/company', ['version' => 1, 'name' => 'Company', 'email_orders' => 'orders@example.com'])->assertSessionHasNoErrors();
        $this->put('/admin/settings/company', ['version' => 2, 'email_orders' => '  '])->assertSessionHasNoErrors();
        self::assertSame('Company', DB::table('company_settings')->value('name'));
        self::assertNull(DB::table('company_settings')->value('email_orders'));
        $this->put('/admin/settings/company', ['version' => 3, 'name' => 'Company'])->assertSessionHasNoErrors();
        self::assertSame(3, app(CompanySettings::class)->all()['version']);
        self::assertSame(2, DB::table('audit_logs')->where('entity_type', 'company_settings')->where('status', 'success')->count());
    }

    public function test_read_write_navigation_and_direct_service_access_require_settings_permission(): void
    {
        $unauthorized = $this->staff();
        $this->actingAs($unauthorized);
        $this->get('/admin/settings/company')->assertForbidden();
        $this->put('/admin/settings/company', ['version' => 1, 'name' => 'Forbidden'])->assertForbidden();
        $this->get('/admin')->assertInertia(fn (Assert $p) => $p->where('navigation', fn ($items) => ! str_contains(json_encode($items, JSON_UNESCAPED_SLASHES), '/admin/settings/company')));
        try {
            app(CompanySettings::class)->save((string) $unauthorized->id, ['version' => 1, 'name' => 'Bypass']);
            self::fail('Direct service must authorize');
        } catch (AuthorizationException) {
            self::assertNull(DB::table('company_settings')->value('name'));
        }
        self::assertTrue(DB::table('audit_logs')->where('entity_type', 'company_settings')->where('status', 'denied')->exists());
    }

    public function test_a_failed_outer_transaction_rolls_back_the_success_audit_and_company_change(): void
    {
        DB::beginTransaction();
        app(CompanySettings::class)->save((string) $this->actor->id, ['version' => 1, 'name' => 'Rollback']);
        DB::rollBack();
        self::assertNull(DB::table('company_settings')->value('name'));
        self::assertFalse(DB::table('audit_logs')->where('entity_type', 'company_settings')->where('status', 'success')->exists());
    }

    public function test_the_database_rejects_additional_rows_and_invalid_versions(): void
    {
        foreach ([['id' => 2], ['lock_version' => 0]] as $data) {
            DB::beginTransaction();
            try {
                isset($data['id']) ? DB::table('company_settings')->insert($data) : DB::table('company_settings')->where('id', 1)->update($data);
                self::fail('Expected database constraint');
            } catch (QueryException $error) {
                self::assertSame('23514', $error->errorInfo[0]);
            } finally {
                DB::rollBack();
            }
        }
    }

    private function png(string $name = 'logo.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aN1sAAAAASUVORK5CYII='));
    }

    public function test_favicon_defaults_to_the_platform_icon_until_the_merchant_uploads_one(): void
    {
        $this->withoutVite();
        $this->get('/storefront/favicon')->assertRedirect('/favicon-32x32.png');
        $this->get('/')->assertSee('/favicon-32x32.png', false)->assertDontSee('/storefront/favicon', false);
        $this->post('/admin/settings/company/logos', ['version' => 1, 'favicon' => $this->png('icon.png')])->assertSessionHasNoErrors();
        $media = DB::table('company_settings')->value('favicon_media_id');
        self::assertNotNull($media);
        $this->get('/storefront/favicon')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get('/')->assertSee('/storefront/favicon?v='.$media, false);
        // The administration keeps the platform icon, and the file cannot be deleted while it is in use.
        $this->get('/admin/settings/company')->assertSee('/favicon-32x32.png', false);
        $this->post('/admin/settings/company/logos', ['version' => 2, 'favicon' => UploadedFile::fake()->create('x.svg', 1, 'image/svg+xml')])->assertSessionHasErrors('favicon');
        $this->post('/admin/settings/company/logos', ['version' => 2, 'remove_favicon' => '1'])->assertSessionHasNoErrors();
        self::assertNull(DB::table('company_settings')->value('favicon_media_id'));
        $this->get('/storefront/favicon')->assertRedirect('/favicon-32x32.png');
    }

    public function test_payment_icons_are_added_removed_and_limited(): void
    {
        $this->post('/admin/settings/company/payment-icons', ['icon' => $this->png('visa.png'), 'name' => 'Visa'])->assertSessionHasNoErrors();
        $this->post('/admin/settings/company/payment-icons', ['icon' => $this->png('mc.png'), 'name' => 'Mastercard'])->assertSessionHasNoErrors();
        $icons = app(CompanySettings::class)->paymentIcons();
        self::assertSame(['Visa', 'Mastercard'], array_column($icons, 'name'));
        $this->get('/admin/settings/company')->assertInertia(fn ($page) => $page->has('settings.payment_icons', 2));
        $this->get('/admin/settings/company/payment-icons/'.$icons[0]['id'].'/image')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->post('/admin/settings/company/payment-icons', ['icon' => UploadedFile::fake()->create('x.svg', 1, 'image/svg+xml')])->assertSessionHasErrors('icon');
        $this->delete('/admin/settings/company/payment-icons/'.$icons[0]['id'])->assertSessionHasNoErrors();
        self::assertSame(['Mastercard'], array_column(app(CompanySettings::class)->paymentIcons(), 'name'));
        for ($i = 0; $i < CompanySettings::MAX_PAYMENT_ICONS; $i++) {
            $this->post('/admin/settings/company/payment-icons', ['icon' => $this->png($i.'.png')]);
        }
        self::assertSame(CompanySettings::MAX_PAYMENT_ICONS, DB::table('company_payment_icons')->count());
        $this->post('/admin/settings/company/payment-icons', ['icon' => $this->png('over.png')])->assertSessionHasErrors('icon');
    }

    public function test_social_and_app_links_are_added_edited_reordered_and_removed(): void
    {
        Storage::fake('local');
        $links = fn (string $kind): array => array_values(array_filter(app(CompanySettings::class)->links(), fn (array $l): bool => $l['kind'] === $kind));
        $this->post('/admin/settings/company/links', ['kind' => 'social', 'preset' => 'facebook', 'url' => 'https://facebook.com/acme'])->assertSessionHasNoErrors();
        $this->post('/admin/settings/company/links', ['kind' => 'social', 'name' => 'Threads', 'url' => 'https://threads.net/@acme', 'image' => $this->png('threads.png')])->assertSessionHasNoErrors();
        // A second link of the same network is allowed (e.g. two stores).
        $this->post('/admin/settings/company/links', ['kind' => 'social', 'preset' => 'facebook', 'name' => 'Acme Outlet', 'url' => 'https://facebook.com/acme-outlet'])->assertSessionHasNoErrors();
        $this->post('/admin/settings/company/links', ['kind' => 'app', 'preset' => 'app_store', 'url' => 'https://apps.apple.com/app/acme', 'image' => $this->png('badge.png')])->assertSessionHasNoErrors();
        self::assertSame(['Facebook', 'Threads', 'Acme Outlet'], array_column($links('social'), 'name'));
        [$facebook, $threads, $outlet] = array_column($links('social'), 'id');
        $app = $links('app')[0];
        self::assertSame(['App Store', 'app_store'], [$app['name'], $app['preset']]);
        $this->get('/admin/settings/company')->assertInertia(fn (Assert $p) => $p->has('settings.links', 4));
        $this->get($app['image'])->assertOk()->assertHeader('Content-Type', 'image/png');
        $media = DB::table('company_links')->where('id', $threads)->value('media_file_id');
        self::assertTrue(app(SharedMediaDeletion::class)->referenced((string) $media));

        $this->post('/admin/settings/company/links/'.$facebook, ['preset' => 'instagram', 'url' => 'https://instagram.com/acme'])->assertSessionHasNoErrors();
        self::assertSame(['Instagram', 'https://instagram.com/acme'], [$links('social')[0]['name'], $links('social')[0]['url']]);
        $this->post('/admin/settings/company/links/'.$app['id'], ['preset' => 'app_store', 'url' => 'https://apps.apple.com/app/acme', 'remove_image' => true])->assertSessionHasNoErrors();
        self::assertNull($links('app')[0]['image']);
        // Lists are reordered one kind at a time, with the whole current list.
        $this->post('/admin/settings/company/links/order', ['kind' => 'social', 'ids' => [$outlet, $facebook, $threads]])->assertSessionHasNoErrors();
        self::assertSame([$outlet, $facebook, $threads], array_column($links('social'), 'id'));
        $this->post('/admin/settings/company/links/order', ['kind' => 'social', 'ids' => [$outlet, $facebook]])->assertSessionHasErrors('ids');
        $this->post('/admin/settings/company/links/order', ['kind' => 'app', 'ids' => [$outlet]])->assertSessionHasErrors('ids');
        $this->delete('/admin/settings/company/links/'.$outlet)->assertSessionHasNoErrors();
        self::assertSame([$facebook, $threads], array_column($links('social'), 'id'));
        $this->delete('/admin/settings/company/links/'.$outlet)->assertNotFound();
        self::assertTrue(DB::table('audit_logs')->where('entity_type', 'company_link')->where('status', 'success')->exists());
    }

    public function test_link_validation_and_permissions_never_partially_change_links_or_files(): void
    {
        Storage::fake('local');
        foreach ([
            ['kind' => 'social', 'preset' => 'facebook', 'url' => 'javascript:alert(1)', 'image' => $this->png()],
            ['kind' => 'social', 'preset' => 'facebook', 'url' => 'https://bad url.test'],
            ['kind' => 'social', 'preset' => 'app_store', 'url' => 'https://x.test'],
            ['kind' => 'other', 'preset' => 'facebook', 'url' => 'https://x.test'],
            // A custom link needs its own name and picture.
            ['kind' => 'social', 'url' => 'https://x.test', 'image' => $this->png()],
            ['kind' => 'social', 'name' => 'Custom', 'url' => 'https://x.test'],
            ['kind' => 'app', 'preset' => 'google_play', 'url' => 'https://x.test', 'image' => UploadedFile::fake()->create('bad.svg', 1, 'image/svg+xml')],
        ] as $bad) {
            $this->post('/admin/settings/company/links', $bad)->assertSessionHasErrors();
            self::assertSame([], app(CompanySettings::class)->links());
            self::assertSame([], Storage::disk('local')->allFiles());
        }
        for ($i = 0; $i < CompanySettings::MAX_LINKS; $i++) {
            DB::table('company_links')->insert(['kind' => 'app', 'preset' => 'google_play', 'name' => 'Google Play', 'url' => 'https://play.google.com/'.$i, 'position' => $i + 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->post('/admin/settings/company/links', ['kind' => 'app', 'preset' => 'google_play', 'url' => 'https://play.google.com/over'])->assertSessionHasErrors('url');
        $this->post('/admin/settings/company/links', ['kind' => 'social', 'preset' => 'x', 'url' => 'https://x.com/acme'])->assertSessionHasNoErrors();
        $id = (string) DB::table('company_links')->where('kind', 'app')->value('id');
        $this->flushSession();
        $this->actingAs($denied = $this->staff(['admin.access']));
        $this->post('/admin/settings/company/links', ['kind' => 'social', 'preset' => 'x', 'url' => 'https://x.com/acme'])->assertForbidden();
        $this->delete('/admin/settings/company/links/'.$id)->assertForbidden();
        $this->get('/admin/settings/company/links/'.$id.'/image')->assertForbidden();
        $this->expectException(AuthorizationException::class);
        app(CompanySettings::class)->removeLink((string) $denied->id, $id);
    }

    public function test_links_migration_moves_existing_social_and_app_columns_into_ordered_links(): void
    {
        Storage::fake('local');
        $this->post('/admin/settings/company/links', ['kind' => 'social', 'preset' => 'instagram', 'url' => 'https://instagram.com/acme', 'image' => $this->png()])->assertSessionHasNoErrors();
        $this->post('/admin/settings/company/links', ['kind' => 'social', 'preset' => 'facebook', 'url' => 'https://facebook.com/acme'])->assertSessionHasNoErrors();
        $this->post('/admin/settings/company/links', ['kind' => 'app', 'preset' => 'google_play', 'url' => 'https://play.google.com/acme'])->assertSessionHasNoErrors();
        $media = DB::table('company_links')->where('preset', 'instagram')->value('media_file_id');
        $migration = require database_path('migrations/2026_12_07_000200_create_company_links.php');
        $migration->down();
        self::assertSame(['https://instagram.com/acme', 'https://facebook.com/acme', 'https://play.google.com/acme'], array_values((array) DB::table('company_settings')->first(['social_instagram', 'social_facebook', 'google_play_url'])));
        $migration->up();
        $links = app(CompanySettings::class)->links();
        // Columns had no order: the old fixed network order is used.
        self::assertSame([['app', 'google_play'], ['social', 'facebook'], ['social', 'instagram']], array_map(fn (array $l): array => [$l['kind'], $l['preset']], $links));
        self::assertEquals($media, DB::table('company_links')->where('preset', 'instagram')->value('media_file_id'));
        self::assertFalse(Schema::hasColumn('company_settings', 'social_facebook'));
    }

    public function test_payment_icon_order_is_saved_and_rejects_stale_or_unauthorized_lists(): void
    {
        Storage::fake('local');
        foreach (['Visa', 'Mastercard', 'Bank transfer'] as $name) {
            $this->post('/admin/settings/company/payment-icons', ['icon' => $this->png(), 'name' => $name])->assertSessionHasNoErrors();
        }
        $icons = app(CompanySettings::class)->paymentIcons();
        $ids = array_reverse(array_column($icons, 'id'));
        $this->post('/admin/settings/company/payment-icons/order', ['ids' => $ids])->assertSessionHasNoErrors();
        self::assertSame(['Bank transfer', 'Mastercard', 'Visa'], array_column(app(CompanySettings::class)->paymentIcons(), 'name'));
        $this->get('/admin/settings/company?tab=payments')->assertInertia(fn (Assert $p) => $p->where('settings.payment_icons.0.name', 'Bank transfer'));
        foreach ([[$ids[0], $ids[0], $ids[1]], [$ids[0]], [...$ids, '999999']] as $invalid) {
            $this->post('/admin/settings/company/payment-icons/order', ['ids' => $invalid])->assertSessionHasErrors();
            self::assertSame($ids, array_column(app(CompanySettings::class)->paymentIcons(), 'id'));
        }
        $this->flushSession();
        $this->actingAs($this->staff(['admin.access']));
        $this->post('/admin/settings/company/payment-icons/order', ['ids' => array_reverse($ids)])->assertForbidden();
        self::assertSame($ids, array_column(app(CompanySettings::class)->paymentIcons(), 'id'));
    }

    public function test_store_logos_are_uploaded_served_privately_and_the_mobile_logo_is_optional(): void
    {
        Storage::fake('local');
        $this->get('/admin/settings/company')->assertInertia(fn (Assert $p) => $p->where('settings.logo', null)->where('settings.logo_mobile', null));
        $this->get('/admin/settings/company/logo/desktop')->assertNotFound();
        // A mobile logo alone is refused: it is an alternative to the store logo.
        $this->post('/admin/settings/company/logos', ['version' => 1, 'logo_mobile' => $this->png('m.png')])->assertSessionHasErrors('logo_mobile');
        self::assertNull(DB::table('company_settings')->value('logo_mobile_media_id'));
        self::assertSame(0, DB::table('media_files')->count());

        $this->post('/admin/settings/company/logos', ['version' => 1, 'logo' => $this->png()])->assertSessionHasNoErrors();
        $row = DB::table('company_settings')->first();
        self::assertNotNull($row->logo_media_id);
        self::assertNull($row->logo_mobile_media_id);
        self::assertSame(2, (int) $row->lock_version);
        $this->get('/admin/settings/company')->assertInertia(fn (Assert $p) => $p->where('settings.logo.meta.width', 1)->where('settings.logo_mobile', null)
            ->where('settings.logo.url', fn ($url) => str_starts_with($url, '/admin/settings/company/logo/desktop')));
        $this->get('/admin/settings/company/logo/desktop')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/admin/settings/company/logo/mobile')->assertNotFound();
        $this->get('/admin/settings/company/logo/other')->assertNotFound();

        $this->post('/admin/settings/company/logos', ['version' => 2, 'logo_mobile' => $this->png('m.png')])->assertSessionHasNoErrors();
        $row = DB::table('company_settings')->first();
        self::assertNotNull($row->logo_mobile_media_id);
        self::assertTrue(app(SharedMediaDeletion::class)->referenced((string) $row->logo_media_id));
        $this->get('/admin/settings/company/logo/mobile')->assertOk();

        // Stale versions and disguised files are refused without leaving files behind.
        $files = count(Storage::disk('local')->allFiles());
        $this->post('/admin/settings/company/logos', ['version' => 1, 'logo' => $this->png()])->assertSessionHasErrors('version');
        $this->post('/admin/settings/company/logos', ['version' => 3, 'logo' => UploadedFile::fake()->create('x.svg', 1, 'image/svg+xml')])->assertSessionHasErrors('logo');
        self::assertSame($files, count(Storage::disk('local')->allFiles()));

        // Removing the mobile logo keeps the main one; removing the main logo removes both.
        $this->post('/admin/settings/company/logos', ['version' => 3, 'remove_logo_mobile' => '1'])->assertSessionHasNoErrors();
        self::assertNull(DB::table('company_settings')->value('logo_mobile_media_id'));
        self::assertNotNull(DB::table('company_settings')->value('logo_media_id'));
        $this->post('/admin/settings/company/logos', ['version' => 4, 'logo_mobile' => $this->png('m2.png')])->assertSessionHasNoErrors();
        $this->post('/admin/settings/company/logos', ['version' => 5, 'remove_logo' => '1'])->assertSessionHasNoErrors();
        self::assertNull(DB::table('company_settings')->value('logo_media_id'));
        self::assertNull(DB::table('company_settings')->value('logo_mobile_media_id'));
        // Stored files stay for the retention policy.
        self::assertSame(3, DB::table('media_files')->count());
        self::assertTrue(DB::table('audit_logs')->where('entity_type', 'company_settings')->where('status', 'success')->exists());
    }

    public function test_email_logo_is_independent_private_audited_and_removable_with_store_fallback(): void
    {
        Storage::fake('local');
        $settings = app(CompanySettings::class);
        self::assertNull($settings->emailLogo());
        $this->post('/admin/settings/company/logos', ['version' => 1, 'logo' => $this->png(), 'logo_email' => $this->png('email.png')])->assertSessionHasNoErrors();
        $row = DB::table('company_settings')->first();
        self::assertNotEquals($row->logo_media_id, $row->logo_email_media_id);
        self::assertEquals($row->logo_email_media_id, $settings->emailLogo()->id);
        $this->get('/admin/settings/company?tab=logo')->assertInertia(fn (Assert $p) => $p->where('settings.logo_email.url', '/admin/settings/company/logo/email?v='.$row->logo_email_media_id));
        $this->get('/admin/settings/company/logo/email')->assertOk()->assertHeader('Content-Type', 'image/png');
        self::assertTrue(app(SharedMediaDeletion::class)->referenced((string) $row->logo_email_media_id));
        DB::beginTransaction();
        try {
            DB::table('media_files')->where('id', $row->logo_email_media_id)->delete();
            self::fail('The active email logo must be protected by the database');
        } catch (QueryException $error) {
            self::assertContains($error->errorInfo[0], ['23001', '23503']);
        } finally {
            DB::rollBack();
        }
        $changes = json_decode(DB::table('audit_logs')->where('entity_type', 'company_settings')->where('status', 'success')->sole()->changes, true);
        self::assertEquals(['before' => null, 'after' => $row->logo_email_media_id], $changes['logo_email_media_id']);

        $this->post('/admin/settings/company/logos', ['version' => 1, 'logo_email' => $this->png()])->assertSessionHasErrors('version');
        $this->post('/admin/settings/company/logos', ['version' => 2, 'logo_email' => UploadedFile::fake()->create('x.svg', 1, 'image/svg+xml')])->assertSessionHasErrors('logo_email');
        self::assertSame(2, DB::table('media_files')->count());
        $this->post('/admin/settings/company/logos', ['version' => 2, 'remove_logo_email' => '1'])->assertSessionHasNoErrors();
        self::assertNull(DB::table('company_settings')->value('logo_email_media_id'));
        self::assertEquals($row->logo_media_id, $settings->emailLogo()->id);
        $this->post('/admin/settings/company/logos', ['version' => 3, 'remove_logo' => '1', 'logo_email' => $this->png('email-only.png')])->assertSessionHasNoErrors();
        self::assertNull(DB::table('company_settings')->value('logo_media_id'));
        self::assertNotNull($settings->emailLogo());
        $this->flushSession();
        $this->actingAs($this->staff(['admin.access']));
        $this->get('/admin/settings/company/logo/email')->assertForbidden();
        $this->post('/admin/settings/company/logos', ['version' => 4, 'remove_logo_email' => '1'])->assertForbidden();
    }

    public function test_email_logo_migration_rolls_back_without_changing_the_store_logo(): void
    {
        Storage::fake('local');
        $this->post('/admin/settings/company/logos', ['version' => 1, 'logo' => $this->png(), 'logo_email' => $this->png('email.png')])->assertSessionHasNoErrors();
        $main = DB::table('company_settings')->value('logo_media_id');
        $migration = require database_path('migrations/2026_10_22_000300_add_company_email_logo.php');
        $migration->down();
        self::assertEquals($main, DB::table('company_settings')->value('logo_media_id'));
        $migration->up();
        self::assertNull(DB::table('company_settings')->value('logo_email_media_id'));
        self::assertEquals($main, app(CompanySettings::class)->emailLogo()->id);
    }

    public function test_logo_changes_require_the_settings_capability_and_the_database_keeps_the_fallback_chain(): void
    {
        Storage::fake('local');
        $this->flushSession();
        $this->actingAs($this->staff(['admin.access']));
        $this->post('/admin/settings/company/logos', ['version' => 1, 'logo' => $this->png()])->assertForbidden();
        $this->get('/admin/settings/company/logo/desktop')->assertForbidden();
        self::assertNull(DB::table('company_settings')->value('logo_media_id'));
        $media = DB::table('media_files')->insertGetId(['disk' => 'local', 'storage_key' => 'images/x.png', 'mime_type' => 'image/png', 'byte_size' => 10, 'width' => 1, 'height' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->expectException(QueryException::class);
        DB::table('company_settings')->where('id', 1)->update(['logo_mobile_media_id' => $media]);
    }
}
