<?php

namespace Tests\Feature;

use App\Audit\Infrastructure\AuditTables;
use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\Persistence\LocaleRecord;
use App\Settings\Infrastructure\GeneralOptions;
use App\Settings\Infrastructure\GeneralSettings;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class GeneralSettingsTest extends TestCase
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

    private function product(?string $override): ProductRecord
    {
        $category = CategoryRecord::query()->first() ?? CategoryRecord::create(['parent_id' => null, 'position' => 1, 'status' => 'active']);
        $p = ProductRecord::create(['sku' => 'SKU-'.uniqid(), 'price' => '1', 'category_id' => $category->id, 'out_of_stock_actions' => $override]);
        $p->translations()->create(['locale_id' => LocaleRecord::where('code', 'en')->sole()->id, 'name' => 'Product '.$p->id, 'slug' => 'product-'.$p->id]);

        return $p;
    }

    public function test_the_installation_starts_with_one_settings_row_that_blocks_purchase_at_zero_stock(): void
    {
        self::assertSame(1, DB::table('general_settings')->count());
        self::assertSame([
            ...GeneralOptions::DEFAULTS, 'bestsellers_excluded_categories' => [], 'closed_messages' => [], 'price_hidden_messages' => [],
            'out_of_stock_action' => 'N', 'version' => 1, 'tax_calculation_method' => 'unit_price', 'display_catalog_prices_with_tax' => false,
            'display_cart_prices_with_tax' => false, 'tax_estimate_without_address' => false, 'tax_default_country' => null, 'tax_default_region' => null,
            'order_email_from' => null, 'order_email_from_name' => null, 'order_email_reply_to' => null,
            'mail_method' => 'php_mail', 'smtp_host' => null, 'smtp_port' => null, 'smtp_encryption' => 'none', 'smtp_auth' => false, 'smtp_username' => null, 'smtp_password_set' => false, 'sendmail_path' => '/usr/sbin/sendmail',
        ], app(GeneralSettings::class)->all());
        $this->get('/admin/settings/general')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Settings/General')
            ->where('settings.out_of_stock_action', 'N')->where('settings.version', 1)->where('overrides', 0));
    }

    public function test_saving_changes_the_setting_bumps_the_version_and_is_audited(): void
    {
        $this->put('/admin/settings/general', ['version' => 1, 'out_of_stock_action' => 'B'])->assertSessionHasNoErrors()->assertSessionHas('status');
        $row = DB::table('general_settings')->where('id', 1)->first();
        self::assertSame(['B', 2, $this->actor->id], [$row->out_of_stock_action, (int) $row->lock_version, (int) $row->updated_by]);
        self::assertTrue(DB::table('audit_logs')->where('entity_type', 'general_settings')->where('changes', 'like', '%out_of_stock_action%')->exists());
        self::assertSame('B', app(GeneralSettings::class)->outOfStockAction());
        self::assertArrayHasKey('general_settings', AuditTables::inventory());
    }

    public function test_invalid_values_and_stale_versions_are_rejected_without_changing_anything(): void
    {
        foreach (['', 'X', 'b', 'S'] as $value) {
            $this->put('/admin/settings/general', ['version' => 1, 'out_of_stock_action' => $value])->assertSessionHasErrors('out_of_stock_action');
        }
        $this->put('/admin/settings/general', ['version' => 1, 'out_of_stock_action' => 'B'])->assertSessionHasNoErrors();
        // A second tab still holding version 1 must not overwrite the newer value.
        $this->put('/admin/settings/general', ['version' => 1, 'out_of_stock_action' => 'N'])->assertSessionHasErrors('version');
        self::assertSame('B', DB::table('general_settings')->where('id', 1)->value('out_of_stock_action'));
    }

    public function test_the_database_keeps_a_single_valid_settings_row(): void
    {
        foreach ([['id' => 2], ['id' => 1, 'out_of_stock_action' => 'Z']] as $values) {
            DB::beginTransaction();
            try {
                $values['id'] === 2 ? DB::table('general_settings')->insert(['id' => 2]) : DB::table('general_settings')->where('id', 1)->update($values);
                self::fail('Expected the settings constraint to refuse the row');
            } catch (QueryException) {
                self::assertTrue(true);
            } finally {
                DB::rollBack();
            }
        }
    }

    public function test_the_page_and_the_save_require_the_settings_capability(): void
    {
        $this->actingAs($this->staff());
        $this->get('/admin/settings/general')->assertForbidden();
        $this->put('/admin/settings/general', ['version' => 1, 'out_of_stock_action' => 'B'])->assertForbidden();
        self::assertSame('N', DB::table('general_settings')->where('id', 1)->value('out_of_stock_action'));
        $this->get('/admin')->assertInertia(fn (Assert $page) => $page->where('capabilities', fn ($c) => ($c['settings.manage'] ?? false) === false));
    }

    public function test_the_page_counts_only_live_products_that_override_the_setting(): void
    {
        $this->product(null);
        $this->product('N');
        $this->product('B');
        $this->product('B')->delete();
        $this->get('/admin/settings/general')->assertInertia(fn (Assert $page) => $page->where('overrides', 2));
    }

    public function test_the_navigation_lists_general_settings_under_settings_for_authorized_staff(): void
    {
        $this->get('/admin')->assertInertia(fn (Assert $page) => $page->where('navigation', fn ($items) => str_contains((string) json_encode($items, JSON_UNESCAPED_SLASHES), '/admin/settings/general')));
    }
}
