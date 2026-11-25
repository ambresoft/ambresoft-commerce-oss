<?php

namespace Tests\Feature;

use App\Audit\Infrastructure\AuditWriteFailure;
use App\Geography\Application\Contract\GeographyAdministration;
use App\Geography\Application\Contract\RegionCsvImportInterface;
use App\Geography\Infrastructure\Import\RegionImportFields;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\Persistence\LocaleRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class RegionImportTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private UserRecord $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->withoutMiddleware(ThrottleRequests::class);
        LocaleRecord::where('code', '<>', 'en')->update(['is_active' => false]);
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actor = $this->staff(['admin.access', 'regions.view', 'regions.manage', 'countries.manage']);
        $this->actingAs($this->actor);
        Storage::fake('local');
        app(GeographyAdministration::class)->setShipping((string) $this->actor->id, 'GR', true);
    }

    private function preview(string $csv, string $delimiter = ','): string
    {
        $this->withSession(['password_hash_web' => auth()->user()->getAuthPassword()]);
        $response = $this->post('/admin/regions/import/preview', ['file' => UploadedFile::fake()->createWithContent('regions.csv', $csv), 'delimiter' => $delimiter]);
        $response->assertSessionHasNoErrors()->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

        return $query['token'];
    }

    /** @return array<string, mixed> */
    private function report(string $token): array
    {
        return Cache::get('region-import:'.$token)['report'];
    }

    /** @return array<string, mixed> */
    private function confirm(string $token): array
    {
        $this->post('/admin/regions/import/'.$token.'/confirm', ['confirmed' => true])->assertSessionHasNoErrors()->assertRedirect();

        return $this->report($token);
    }

    private function create(string $code = 'ATT'): string
    {
        return app(GeographyAdministration::class)->createRegion((string) $this->actor->id, 'GR', $code, ['en' => 'Attica'], 'active');
    }

    public function test_import_is_registered_in_the_transfer_hub_and_owns_its_navigation(): void
    {
        $this->get('/admin/data-transfer')->assertInertia(fn (Assert $page) => $page->has('transfers', 2)->where('transfers.0.id', 'core.regions-import')->where('transfers.0.href', '/admin/regions/import')->where('transfers.1.id', 'core.regions-export'));
        config(['region-import.max_kilobytes' => 1024]);
        $this->get('/admin/regions/import')->assertInertia(fn (Assert $page) => $page->component('Admin/Regions/Import')->where('maxFileBytes', 1048576)->where('breadcrumbs.1.href', '/admin/data-transfer')->where('navigation', fn ($items) => collect($items)->where('active', true)->pluck('id')->all() === ['core.data-transfer']));
    }

    public function test_preview_is_read_only_and_cannot_confirm_without_explicit_acceptance(): void
    {
        $sequence = DB::selectOne('SELECT last_value FROM regions_id_seq')->last_value;
        $token = $this->preview("country_code,code,name.en,status\nGR,ATT,Attica,active\n");
        self::assertSame(1, $this->report($token)['created']);
        $this->assertDatabaseCount('regions', 0);
        self::assertSame($sequence, DB::selectOne('SELECT last_value FROM regions_id_seq')->last_value);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'region.created']);
        $this->get('/admin/regions/import?token='.$token)->assertInertia(fn (Assert $page) => $page->where('preview.rows', 1)->missing('preview.fingerprint')->missing('preview.fileHash')->missing('preview.operations')->where('preview.sample.0.country', 'GR'));
        $this->post('/admin/regions/import/'.$token.'/confirm', ['confirmed' => false])->assertSessionHasErrors('confirmed');
        $this->assertDatabaseCount('regions', 0);
    }

    public function test_creates_localized_regions_with_normalized_codes_and_records_the_actor_and_counts(): void
    {
        LocaleRecord::where('code', 'el')->update(['is_active' => true]);
        $token = $this->preview("country_code,code,name.en,name.el,status\ngr,att,Attica,Αττική,active\nGR,THS,Thessaloniki,Θεσσαλονίκη,\n");
        self::assertSame(0, $this->report($token)['errors']);
        $report = $this->confirm($token);
        self::assertSame('completed', $report['status']);
        self::assertSame(2, $report['created']);
        $region = DB::table('regions')->where('code', 'ATT')->sole();
        self::assertSame('active', $region->status);
        self::assertSame('disabled', DB::table('regions')->where('code', 'THS')->value('status'));
        $this->assertDatabaseHas('region_translations', ['region_id' => $region->id, 'locale_id' => 2, 'name' => 'Αττική']);
        $event = DB::table('audit_logs')->where('action', 'region.import_completed')->sole();
        self::assertSame((string) $this->actor->id, $event->actor_id);
        self::assertSame(2, json_decode($event->metadata, true)['created']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'region.created', 'actor_id' => (string) $this->actor->id]);
        $this->post('/admin/regions/import/'.$token.'/confirm', ['confirmed' => true])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('regions', 2);
    }

    public function test_country_code_matching_is_repeatable_and_unchanged_rows_keep_their_versions(): void
    {
        $csv = "country_code,code,name.en,status\nGR,ATT,Attica,active\n";
        $this->confirm($this->preview($csv));
        $region = DB::table('regions')->sole();
        $token = $this->preview($csv);
        self::assertSame(1, $this->report($token)['skipped']);
        self::assertSame(0, $this->confirm($token)['updated']);
        $this->assertDatabaseCount('regions', 1);
        self::assertSame($region->lock_version, DB::table('regions')->sole()->lock_version);
    }

    public function test_partial_updates_preserve_missing_names_and_inactive_history_and_clear_optional_translations(): void
    {
        LocaleRecord::where('code', 'el')->update(['is_active' => true]);
        $id = app(GeographyAdministration::class)->createRegion((string) $this->actor->id, 'GR', 'ATT', ['en' => 'Attica', 'el' => 'Αττική'], 'active');
        $fr = LocaleRecord::where('code', 'fr')->sole()->id;
        DB::table('region_translations')->insert(['region_id' => $id, 'locale_id' => $fr, 'name' => 'Attique']);
        $token = $this->preview("region_id,name.en,status\n$id,Updated Attica,disabled\n");
        self::assertSame(1, $this->confirm($token)['updated']);
        $this->assertDatabaseHas('region_translations', ['region_id' => $id, 'locale_id' => 2, 'name' => 'Αττική']);
        $this->assertDatabaseHas('region_translations', ['region_id' => $id, 'locale_id' => $fr, 'name' => 'Attique']);
        $this->assertDatabaseHas('regions', ['id' => $id, 'code' => 'ATT', 'status' => 'disabled', 'lock_version' => 2]);
        self::assertSame('completed', $this->confirm($this->preview("region_id,name.el\n$id,\n"))['status']);
        $this->assertDatabaseMissing('region_translations', ['region_id' => $id, 'locale_id' => 2]);
        self::assertSame(1, $this->report($this->preview("region_id,name.en\n$id,\n"))['errors']);
    }

    public function test_id_updates_can_rename_codes_but_cannot_move_countries_or_overwrite_another_identity(): void
    {
        $id = $this->create();
        app(GeographyAdministration::class)->setShipping((string) $this->actor->id, 'DE', true);
        self::assertSame('completed', $this->confirm($this->preview("region_id,code\n$id,ATT1\n"))['status']);
        $this->assertDatabaseHas('regions', ['id' => $id, 'code' => 'ATT1']);
        self::assertSame(1, $this->report($this->preview("region_id,country_code\n$id,DE\n"))['errors']);
        $this->create('OTHER');
        self::assertSame(1, $this->report($this->preview("region_id,code\n$id,OTHER\n"))['errors']);
    }

    public function test_invalid_rows_and_duplicate_targets_block_the_entire_import(): void
    {
        $id = $this->create();
        foreach ([
            "country_code,code,name.en\nXX,A,Invalid\n", "country_code,code,name.en\nDE,A,Not shipping\n",
            "country_code,code,name.en\nGR,BAD-CODE,Invalid\n", "country_code,code,name.el\nGR,A,Χωρίς προεπιλογή\n",
            "country_code,code,name.en,status\nGR,A,Invalid,published\n", "region_id\n999999\n",
            "region_id,status\n$id,disabled\n$id,active\n", "country_code,code,name.en\nGR,NEW,One\ngr,new,Two\n",
            "region_id,country_code,code\n$id,GR,ATT\n,GR,ATT\n",
        ] as $csv) {
            $token = $this->preview($csv);
            self::assertGreaterThan(0, $this->report($token)['errors']);
            $this->post('/admin/regions/import/'.$token.'/confirm', ['confirmed' => true])->assertSessionHasErrors('file');
            $this->assertDatabaseCount('regions', 1);
            $this->assertDatabaseHas('regions', ['id' => $id, 'status' => 'active']);
        }
    }

    public function test_csv_encoding_headers_delimiters_and_record_limits_are_enforced(): void
    {
        foreach ([
            '', "code,code\nA,A\n", "lock_version\n1\n", "name.fr\nAttique\n", "name.en\n\"unterminated",
            "name.en\nInvalid\xff\n", "name.en\nNul\0value\n", "country_code,code,name.en\nGR,A\n",
        ] as $csv) {
            self::assertGreaterThan(0, $this->report($this->preview($csv))['errors']);
        }
        $token = $this->preview("\xEF\xBB\xBFcountry_code;code;name.en\r\nGR;ATT;\"Attica, Greece\"\r\n", ';');
        self::assertSame('completed', $this->confirm($token)['status']);
        config(['region-import.max_rows' => 1]);
        self::assertGreaterThan(0, $this->report($this->preview("country_code,code,name.en\nGR,A,One\nGR,B,Two\n"))['errors']);
        config(['region-import.max_rows' => 10000, 'region-import.max_record_bytes' => 20]);
        self::assertGreaterThan(0, $this->report($this->preview("country_code,code,name.en\nGR,LONG,Long record value\n"))['errors']);
    }

    public function test_file_region_shipping_and_language_changes_invalidate_previews(): void
    {
        $id = $this->create();
        $token = $this->preview("region_id,status\n$id,disabled\n");
        app(GeographyAdministration::class)->updateRegion((string) $this->actor->id, $id, 1, 'ATT', ['en' => 'Changed after preview'], 'active');
        self::assertSame('failed', $this->confirm($token)['status']);
        $token = $this->preview("region_id,status\n$id,disabled\n");
        $entry = Cache::get('region-import:'.$token);
        Storage::disk('local')->put($entry['path'], "region_id,status\n$id,active\n");
        self::assertSame('failed', $this->confirm($token)['status']);
        $token = $this->preview("region_id,status\n$id,disabled\n");
        app(GeographyAdministration::class)->setShipping((string) $this->actor->id, 'GR', false);
        self::assertSame('failed', $this->confirm($token)['status']);
        app(GeographyAdministration::class)->setShipping((string) $this->actor->id, 'GR', true);
        $token = $this->preview("region_id,status\n$id,disabled\n");
        LocaleRecord::where('code', 'el')->update(['is_active' => true]);
        self::assertSame('failed', $this->confirm($token)['status']);
        $this->assertDatabaseHas('regions', ['id' => $id, 'status' => 'active', 'lock_version' => 2]);
    }

    public function test_a_failure_on_a_later_row_rolls_back_the_batch_and_its_success_events(): void
    {
        $token = $this->preview("country_code,code,name.en\nGR,A,One\nGR,B,Two\n");
        $real = app(GeographyAdministration::class);
        $proxy = Mockery::mock(GeographyAdministration::class);
        $proxy->shouldReceive('createRegion')->once()->withArgs(fn ($actor, $country, $code): bool => $code === 'A')->andReturnUsing(fn ($actor, $country, $code, $names, $status) => $real->createRegion($actor, $country, $code, $names, $status));
        $proxy->shouldReceive('createRegion')->once()->withArgs(fn ($actor, $country, $code): bool => $code === 'B')->andThrow(ValidationException::withMessages(['code' => 'Vetoed']));
        $this->app->instance(GeographyAdministration::class, $proxy);
        $report = $this->confirm($token);
        self::assertSame('failed', $report['status']);
        self::assertSame(0, $report['created']);
        $this->assertDatabaseCount('regions', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'region.created']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'region.import_completed']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'region.import_failed', 'status' => 'failure', 'actor_id' => (string) $this->actor->id]);
    }

    public function test_management_permissions_are_enforced_for_every_endpoint_and_the_direct_import_port(): void
    {
        $token = $this->preview("country_code,code,name.en\nGR,A,One\n");
        $this->flushSession();
        $denied = $this->staff(['admin.access', 'regions.view']);
        $this->actingAs($denied);
        foreach (['/admin/regions/import', '/admin/regions/import/template', '/admin/regions/import/'.$token.'/errors'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post('/admin/regions/import/preview', [])->assertForbidden();
        $this->post('/admin/regions/import/'.$token.'/confirm', ['confirmed' => true])->assertForbidden();
        $this->delete('/admin/regions/import/'.$token)->assertForbidden();
        $this->get('/admin/data-transfer')->assertForbidden();
        try {
            app(RegionCsvImportInterface::class)->confirm((string) $denied->id, Storage::disk('local')->path(Cache::get('region-import:'.$token)['path']), ',', $this->report($token));
            self::fail('Direct import must reauthorize.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('regions', 0);
        }
    }

    public function test_previews_are_owner_bound_and_expire_and_busy_or_used_tokens_cannot_run_again(): void
    {
        $token = $this->preview("country_code,code,name.en\nGR,A,One\n");
        $lock = Cache::lock('region-import-lock:'.$token, 600);
        $lock->get();
        $this->post('/admin/regions/import/'.$token.'/confirm', ['confirmed' => true])->assertSessionHasErrors('file');
        $lock->release();
        $this->flushSession();
        $other = $this->staff(['admin.access', 'regions.manage']);
        $this->actingAs($other);
        $this->get('/admin/regions/import?token='.$token)->assertNotFound();
        $this->post('/admin/regions/import/'.$token.'/confirm', ['confirmed' => true])->assertNotFound();
        $this->delete('/admin/regions/import/'.$token)->assertNotFound();
        $this->get('/admin/regions/import/'.$token.'/errors')->assertNotFound();
        $this->flushSession();
        $this->actingAs($this->actor);
        $this->travel(3601)->seconds();
        $this->get('/admin/regions/import?token='.$token)->assertNotFound();
        $this->travelBack();
    }

    public function test_template_and_documented_example_use_the_current_columns_and_errors_are_formula_safe(): void
    {
        LocaleRecord::where('code', 'el')->update(['is_active' => true]);
        $csv = $this->get('/admin/regions/import/template')->assertOk()->streamedContent();
        $header = str_getcsv(explode("\n", substr($csv, 3))[0], ',', '"', '');
        self::assertSame(app(RegionImportFields::class)->columns(), $header);
        self::assertSame(0, $this->report($this->preview($csv))['errors']);
        self::assertSame('completed', $this->confirm($this->preview(file_get_contents(base_path('docs/examples/region-import.csv'))))['status']);
        $token = $this->preview("region_id\n=HYPERLINK(1)\n");
        $errors = $this->get('/admin/regions/import/'.$token.'/errors')->assertOk()->streamedContent();
        self::assertStringContainsString("'=HYPERLINK(1)", $errors);
    }

    public function test_cancel_and_expired_upload_pruning_do_not_delete_regions(): void
    {
        $id = $this->create();
        $token = $this->preview("region_id,status\n$id,disabled\n");
        $entry = Cache::get('region-import:'.$token);
        $this->delete('/admin/regions/import/'.$token)->assertRedirect('/admin/regions/import');
        self::assertNull(Cache::get('region-import:'.$token));
        Storage::disk('local')->assertMissing($entry['path']);
        $this->assertDatabaseHas('regions', ['id' => $id, 'status' => 'active']);
        Storage::disk('local')->put('region-imports/expired.csv', 'old');
        touch(Storage::disk('local')->path('region-imports/expired.csv'), time() - 5000);
        $live = $this->preview("country_code,code,name.en\nGR,A,One\n");
        $livePath = Cache::get('region-import:'.$live)['path'];
        touch(Storage::disk('local')->path($livePath), time() - 5000);
        $this->artisan('cms:region-import-prune')->assertSuccessful();
        Storage::disk('local')->assertMissing('region-imports/expired.csv');
        Storage::disk('local')->assertExists($livePath);
    }

    public function test_revoked_management_capability_prevents_a_reviewed_batch_from_running(): void
    {
        $token = $this->preview("country_code,code,name.en\nGR,A,One\n");
        $permission = DB::table('permissions')->where('name', 'regions.manage')->value('id');
        $this->actor->roles()->sole()->permissions()->detach($permission);
        $this->post('/admin/regions/import/'.$token.'/confirm', ['confirmed' => true])->assertForbidden();
        $this->assertDatabaseCount('regions', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'region.import_completed']);
    }

    public function test_a_completion_audit_failure_rolls_back_all_region_mutations_and_propagates(): void
    {
        $token = $this->preview("country_code,code,name.en\nGR,A,One\n");
        $entry = Cache::get('region-import:'.$token);
        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT test_region_import_audit_failure CHECK (action <> 'region.import_completed')");
        try {
            app(RegionCsvImportInterface::class)->confirm((string) $this->actor->id, Storage::disk('local')->path($entry['path']), ',', $entry['report']);
            self::fail('A missing completion audit must not produce success.');
        } catch (AuditWriteFailure) {
            $this->assertDatabaseCount('regions', 0);
            $this->assertDatabaseMissing('audit_logs', ['action' => 'region.created']);
            $this->assertDatabaseMissing('audit_logs', ['action' => 'region.import_completed']);
        } finally {
            DB::statement('ALTER TABLE audit_logs DROP CONSTRAINT test_region_import_audit_failure');
        }
    }

    public function test_numeric_looking_names_are_compared_as_exact_text(): void
    {
        $id = app(GeographyAdministration::class)->createRegion((string) $this->actor->id, 'GR', 'A', ['en' => '01'], 'active');
        $token = $this->preview("region_id,name.en\n$id,1\n");
        self::assertSame(1, $this->report($token)['updated']);
        self::assertSame('completed', $this->confirm($token)['status']);
        $this->assertDatabaseHas('region_translations', ['region_id' => $id, 'locale_id' => 1, 'name' => '1']);
    }
}
