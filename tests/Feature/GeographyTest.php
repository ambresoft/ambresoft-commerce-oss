<?php

namespace Tests\Feature;

use App\Geography\Application\Contract\GeographyAdministration;
use App\Geography\Application\Contract\ShippingGeography;
use App\Geography\Infrastructure\GreekRegions;
use App\Identity\Infrastructure\Persistence\UserRecord;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class GeographyTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private UserRecord $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actor = $this->staff(super: true);
        $this->actingAs($this->actor);
    }

    private function id(): string
    {
        return (string) $this->actor->id;
    }

    /** @param callable(): mixed $operation */
    private function invalid(callable $operation, string $field): void
    {
        try {
            $operation();
            self::fail('Expected validation error '.$field);
        } catch (ValidationException $e) {
            self::assertArrayHasKey($field, $e->errors());
        }
    }

    public function test_countries_are_chosen_for_shipping_and_regions_need_a_shipping_country(): void
    {
        $admin = app(GeographyAdministration::class);
        $port = app(ShippingGeography::class);
        self::assertSame([], $port->countries());
        $this->invalid(fn () => $admin->createRegion($this->id(), 'GR', 'ATT', ['en' => 'Attica'], 'active'), 'country');
        $this->invalid(fn () => $admin->setShipping($this->id(), 'ZZ', true), 'country');
        $admin->setShipping($this->id(), 'GR', true);
        $admin->setShipping($this->id(), 'GR', true);
        self::assertSame(['GR'], $port->countries());
        self::assertTrue($port->ships('GR'));
        self::assertFalse($port->ships('DE'));
        $id = $admin->createRegion($this->id(), 'GR', ' att ', ['en' => 'Attica', 'el' => 'Αττική'], 'active');
        $this->invalid(fn () => $admin->createRegion($this->id(), 'GR', 'ATT', ['en' => 'Again'], 'active'), 'code');
        $this->invalid(fn () => $admin->createRegion($this->id(), 'GR', 'bad code', ['en' => 'Bad'], 'active'), 'code');
        $this->invalid(fn () => $admin->createRegion($this->id(), 'GR', 'XXX', ['el' => 'Μόνο ελληνικά'], 'active'), 'names.en');
        $this->invalid(fn () => $admin->createRegion($this->id(), 'GR', 'XXX', ['en' => 'X', 'fr' => 'Inactive'], 'active'), 'names');
        self::assertSame('ATT', DB::table('regions')->where('id', $id)->value('code'));
        // Names resolve in the requested language with fallback to the default.
        self::assertSame([['code' => 'ATT', 'name' => 'Αττική']], $port->regions('GR', 'el'));
        self::assertSame([['code' => 'ATT', 'name' => 'Attica']], $port->regions('GR', 'en'));

        // Disabling a country keeps its regions but hides them from checkout; re-enabling restores them.
        $admin->setShipping($this->id(), 'GR', false);
        self::assertSame([], $port->regions('GR', 'en'));
        self::assertSame(1, DB::table('regions')->count());
        $admin->setShipping($this->id(), 'GR', true);
        self::assertCount(1, $port->regions('GR', 'en'));
    }

    public function test_region_updates_use_versions_status_hides_regions_and_delete_works(): void
    {
        $admin = app(GeographyAdministration::class);
        $port = app(ShippingGeography::class);
        $admin->setShipping($this->id(), 'GR', true);
        $id = $admin->createRegion($this->id(), 'GR', 'EVV', ['en' => 'Euboea'], 'active');
        $admin->updateRegion($this->id(), $id, 1, 'EVV', ['en' => 'Evia'], 'disabled');
        self::assertSame([], $port->regions('GR', 'en'));
        $this->invalid(fn () => $admin->updateRegion($this->id(), $id, 1, 'EVV', ['en' => 'Stale'], 'active'), 'version');
        self::assertSame(2, (int) DB::table('regions')->where('id', $id)->value('lock_version'));
        self::assertSame(1, $admin->setRegionStatus($this->id(), [$id], 'active'));
        self::assertSame(0, $admin->setRegionStatus($this->id(), [$id], 'active'));
        self::assertSame([['code' => 'EVV', 'name' => 'Evia']], $port->regions('GR', 'en'));
        $this->invalid(fn () => $admin->setRegionStatus($this->id(), [$id], 'archived'), 'status');
        $admin->deleteRegion($this->id(), $id, 3);
        self::assertSame(0, DB::table('regions')->count());
        // The database rejects malformed rows regardless of the application.
        $this->expectException(QueryException::class);
        DB::table('regions')->insert(['country_code' => 'gr', 'code' => 'x', 'status' => 'active']);
    }

    public function test_greek_nomoi_command_is_idempotent_and_keeps_edits(): void
    {
        $this->artisan('cms:geography-greece', ['actor' => $this->id()])->assertSuccessful();
        self::assertSame(51, DB::table('regions')->where('country_code', 'GR')->count());
        self::assertSame(51, count(GreekRegions::REGIONS));
        $id = DB::table('regions')->where('code', 'ATT')->value('id');
        app(GeographyAdministration::class)->updateRegion($this->id(), (string) $id, 1, 'ATT', ['en' => 'Greater Attica', 'el' => 'Αττική'], 'active');
        $this->artisan('cms:geography-greece', ['actor' => $this->id()])->assertSuccessful();
        self::assertSame(51, DB::table('regions')->count());
        self::assertSame('Greater Attica', DB::table('region_translations')->where('region_translations.region_id', $id)->join('locales', 'locales.id', '=', 'region_translations.locale_id')->where('locales.code', 'en')->value('region_translations.name'));
        self::assertSame(['GR'], app(ShippingGeography::class)->countries());
    }

    public function test_admin_pages_filters_permissions_and_audit(): void
    {
        $this->get('/admin/countries')->assertInertia(fn (Assert $p) => $p->component('Admin/Countries/Index')->where('shippingTotal', 0)->where('countries.total', fn ($t) => $t > 200));
        $this->get('/admin/countries?search=greece')->assertInertia(fn (Assert $p) => $p->where('countries.total', 1)->where('countries.data.0.code', 'GR')->where('countries.data.0.shipping', false));
        $this->put('/admin/countries/GR', ['shipping' => true])->assertRedirect();
        $this->put('/admin/countries/ZZ', ['shipping' => true])->assertSessionHasErrors('country');
        $this->get('/admin/countries?shipping=yes')->assertInertia(fn (Assert $p) => $p->where('countries.total', 1)->where('shippingTotal', 1));
        $this->post('/admin/regions', ['country_code' => 'GR', 'code' => 'ATT', 'names' => ['en' => 'Attica'], 'status' => 'active'])->assertSessionHasNoErrors();
        $this->post('/admin/regions', ['country_code' => 'GR', 'code' => 'ATT', 'names' => ['en' => 'Attica'], 'status' => 'active'])->assertSessionHasErrors('code');
        $this->get('/admin/regions')->assertInertia(fn (Assert $p) => $p->component('Admin/Regions/Index')->where('filters.country', 'GR')->where('regions.total', 1)->where('regions.data.0.name', 'Attica')->has('countries', 1));
        $this->get('/admin/regions?country=DE')->assertSessionHasErrors('country');
        $region = (string) DB::table('regions')->value('id');
        $this->put('/admin/regions/'.$region, ['version' => 1, 'code' => 'ATT', 'names' => ['en' => 'Attica region'], 'status' => 'active'])->assertSessionHasNoErrors();
        $this->post('/admin/regions/status', ['ids' => [$region], 'status' => 'disabled'])->assertSessionHasNoErrors();
        $this->get('/admin/regions?status=disabled&search=attica')->assertInertia(fn (Assert $p) => $p->where('regions.total', 1));
        $this->delete('/admin/regions/'.$region, ['version' => 3])->assertSessionHasNoErrors();
        self::assertTrue(DB::table('audit_logs')->where('entity_type', 'region')->exists());
        self::assertTrue(DB::table('audit_logs')->where('entity_type', 'shipping_country')->exists());

        // View-only staff see the pages but cannot change anything.
        $viewer = $this->staff(['admin.access', 'countries.view', 'regions.view']);
        $this->flushSession();
        $this->actingAs($viewer);
        $this->get('/admin/countries')->assertOk();
        $this->get('/admin/regions')->assertOk();
        $this->put('/admin/countries/DE', ['shipping' => true])->assertForbidden();
        $this->post('/admin/regions', ['country_code' => 'GR', 'code' => 'XYZ', 'names' => ['en' => 'X'], 'status' => 'active'])->assertForbidden();
        $nobody = $this->staff(['admin.access']);
        $this->flushSession();
        $this->actingAs($nobody);
        $this->get('/admin/countries')->assertForbidden();
        $this->get('/admin/regions')->assertForbidden();
    }
}
