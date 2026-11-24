<?php

namespace Tests\Feature;

use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Order\Application\Contract\OrderPaymentMethods;
use App\Order\Infrastructure\OrderAdministration;
use App\Payment\Application\Service\PaymentMethodRegistry;
use App\Shared\Infrastructure\Modules\AddonException;
use App\Shared\Infrastructure\Modules\AddonManager;
use App\Shared\Infrastructure\Modules\AddonPackage;
use App\Shared\Infrastructure\Modules\ModuleManifest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\PlacesOrders;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;
use ZipArchive;

final class AddonManagerTest extends TestCase
{
    use PlacesOrders, RefreshDatabase, StaffIdentities;

    private const EXAMPLE = 'docs/examples/addons/acme-bank-transfer';

    private UserRecord $actor;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actor = $this->staff(super: true);
        $this->actingAs($this->actor);
        $this->tmp = storage_path('framework/testing/addons-'.Str::random(12));
        mkdir($this->tmp.'/installed', 0750, true);
        config(['modules.addons_path' => $this->tmp.'/installed', 'modules.uploads' => true]);
    }

    protected function tearDown(): void
    {
        AddonPackage::remove($this->tmp);
        parent::tearDown();
    }

    private function manager(): AddonManager
    {
        return app(AddonManager::class);
    }

    /**
     * A package folder: the example add-on, or a generated one with its own namespace.
     *
     * @param  array<string, mixed>  $composer  Merged into composer.json
     * @param  array<string, string|null>  $files  Extra files (null removes one)
     */
    private function package(?string $name = null, array $composer = [], array $files = []): string
    {
        $dir = $this->tmp.'/src-'.Str::random(8);
        if ($name === null) {
            $this->copy(base_path(self::EXAMPLE), $dir);
        } else {
            $ns = 'TestAddon'.Str::studly(str_replace(['/', '-', '.'], ' ', $name));
            mkdir($dir.'/src', 0750, true);
            file_put_contents($dir.'/composer.json', json_encode(['name' => $name, 'version' => '1.0.0', 'autoload' => ['psr-4' => [$ns.'\\' => 'src/']],
                'extra' => ['cms-module' => ['contract' => 2, 'title' => 'Generated '.$name, 'cms' => '^1.0', 'provider' => $ns.'\\Provider', 'requires' => []]]]));
            file_put_contents($dir.'/src/Provider.php', "<?php\n\nnamespace $ns;\n\nfinal class Provider extends \\Illuminate\\Support\\ServiceProvider {}\n");
        }
        $manifest = json_decode((string) file_get_contents($dir.'/composer.json'), true);
        file_put_contents($dir.'/composer.json', json_encode(array_replace_recursive($manifest, $composer), JSON_UNESCAPED_SLASHES));
        foreach ($files as $path => $content) {
            if ($content === null) {
                @unlink($dir.'/'.$path);

                continue;
            }
            @mkdir(dirname($dir.'/'.$path), 0750, true);
            file_put_contents($dir.'/'.$path, $content);
        }

        return $dir;
    }

    /** @param array<string, string> $raw Extra entries written verbatim (name => content) */
    private function zip(string $dir, string $root = '', array $raw = []): string
    {
        $file = $this->tmp.'/'.Str::random(8).'.zip';
        $zip = new ZipArchive;
        $zip->open($file, ZipArchive::CREATE);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $path) {
            $zip->addFile((string) $path, $root.substr((string) $path, strlen($dir) + 1));
        }
        foreach ($raw as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $file;
    }

    private function upload(string $zip, bool $upgrade = false): TestResponse
    {
        return $this->post('/admin/addons/upload', ['package' => new UploadedFile($zip, 'addon.zip', 'application/zip', null, true), 'upgrade' => $upgrade]);
    }

    private function refused(callable $operation, string $key): void
    {
        try {
            $operation();
            self::fail('Expected '.$key);
        } catch (AddonException $e) {
            self::assertSame($key, $e->key, $e->getMessage());
        }
    }

    private function copy(string $from, string $to): void
    {
        mkdir($to, 0750, true);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $item) {
            $target = $to.'/'.substr((string) $item, strlen($from) + 1);
            $item->isDir() ? @mkdir($target, 0750, true) : copy((string) $item, $target);
        }
    }

    private function stagingEmpty(): bool
    {
        return (glob(app(AddonPackage::class)->stagingRoot().'/*') ?: []) === [];
    }

    public function test_bundled_stripe_checkout_is_listed_without_upload_and_has_its_own_lifecycle(): void
    {
        $stripe = array_search('ambresoft/stripe-checkout', array_column(app(AddonManager::class)->overview(), 'name'), true);
        self::assertIsInt($stripe);
        // Present right after installing the CMS: discovered from modules/, no ZIP involved.
        $this->get('/admin/addons')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Addons/Index')
            ->where("addons.$stripe.name", 'ambresoft/stripe-checkout')->where("addons.$stripe.title", 'Stripe Checkout')->where("addons.$stripe.source", 'bundled')->where("addons.$stripe.status", 'not_installed')
            ->where("addons.$stripe.contributes.payment-methods", ['stripe_checkout'])->where("addons.$stripe.actions.install", true)->where("addons.$stripe.actions.enable", false));
        // The deployment step installs it disabled; nothing is offered to customers yet.
        $this->artisan('cms:addons-sync')->expectsOutputToContain('Installed (disabled): ambresoft/stripe-checkout')->assertSuccessful();
        $this->artisan('cms:addons-sync')->assertSuccessful();
        self::assertSame(['bundled', '1.0.0', 'disabled'], array_values((array) DB::table('addons')->where('name', 'ambresoft/stripe-checkout')->first(['source', 'version', 'status'])));
        self::assertFalse(app(PaymentMethodRegistry::class)->has('stripe_checkout'));

        $this->get('/admin/addons')->assertInertia(fn (Assert $page) => $page->where("addons.$stripe.settings", []));
        $this->post('/admin/addons/ambresoft/stripe-checkout/enable')->assertSessionHasNoErrors()->assertRedirect('/admin/addons');
        self::assertSame('ambresoft/stripe-checkout', app(PaymentMethodRegistry::class)->owner('stripe_checkout'));
        // The enabled add-on links to the settings of what it contributes.
        $this->get('/admin/addons')->assertInertia(fn (Assert $page) => $page->where("addons.$stripe.settings", [['name' => 'Card payment (Stripe)', 'href' => '/admin/payment-methods/stripe_checkout/edit']]));
        // Add-on enabled, payment method still disabled until configured and verified (separate controls).
        $this->get('/admin/payment-methods')->assertInertia(fn (Assert $page) => $page->where('methods.2.code', 'stripe_checkout')->where('methods.2.owner_title', 'Stripe Checkout')
            ->where('methods.2.active', false)->where('methods.2.kind', 'redirect')->where('methods.2.issues', fn ($issues) => count($issues) === 2));
        $this->patch('/admin/payment-methods/stripe_checkout/active', ['version' => (int) DB::table('payment_methods')->where('code', 'stripe_checkout')->value('lock_version'), 'active' => true])->assertSessionHasErrors('is_active');
        self::assertSame([['id' => $this->methodId('cod'), 'name' => 'Cash on delivery']], app(OrderPaymentMethods::class)->choices('EUR', 2, 'en'));

        $this->post('/admin/addons/ambresoft/stripe-checkout/disable')->assertSessionHasNoErrors();
        $this->get('/admin/payment-methods')->assertInertia(fn (Assert $page) => $page->has('methods', 2));
        $this->get('/admin/payment-methods/stripe_checkout/edit')->assertNotFound();
        // Uninstalling a bundled add-on keeps its files (it ships with the CMS) and its stored data.
        $this->delete('/admin/addons/ambresoft/stripe-checkout')->assertSessionHasNoErrors();
        self::assertFalse(DB::table('addons')->where('name', 'ambresoft/stripe-checkout')->exists());
        self::assertTrue(is_file(base_path('modules/ambresoft/stripe-checkout/composer.json')));
        self::assertTrue(DB::table('payment_methods')->where('code', 'stripe_checkout')->exists());
        $this->get('/admin/addons')->assertInertia(fn (Assert $page) => $page->where("addons.$stripe.status", 'not_installed'));
        // The bundled name cannot be claimed by an upload.
        $this->refused(fn () => $this->manager()->upload((string) $this->actor->id, $this->zip($this->package('ambresoft/stripe-checkout')), true), 'addons.error_bundled_name');
    }

    public function test_a_third_party_payment_addon_installs_from_zip_and_registers_through_the_contract(): void
    {
        $this->upload($this->zip(base_path(self::EXAMPLE)))->assertSessionHasNoErrors()->assertRedirect('/admin/addons');
        self::assertTrue(is_file($this->tmp.'/installed/acme/bank-transfer/composer.json'));
        self::assertSame(['uploaded', '1.0.0', 'disabled'], array_values((array) DB::table('addons')->where('name', 'acme/bank-transfer')->first(['source', 'version', 'status'])));
        self::assertTrue($this->stagingEmpty());
        self::assertFalse(app(PaymentMethodRegistry::class)->has('acme.bank-transfer'), 'Installing never enables');

        $this->post('/admin/addons/acme/bank-transfer/enable')->assertSessionHasNoErrors();
        self::assertSame('acme/bank-transfer', app(PaymentMethodRegistry::class)->owner('acme.bank-transfer'));
        $this->get('/admin/payment-methods/acme.bank-transfer/edit')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('method.translations.en.name', 'Bank transfer')->where('method.fields.0.key', 'account_holder')->where('method.fields.1.label', 'IBAN'));
        $version = fn (): int => (int) DB::table('payment_methods')->where('code', 'acme.bank-transfer')->value('lock_version');
        $input = fn (string $iban): array => ['version' => $version(), 'is_active' => true, 'translations' => ['en' => ['name' => 'Bank transfer', 'description' => '']], 'settings' => ['account_holder' => 'Acme Ltd', 'iban' => $iban]];
        $this->put('/admin/payment-methods/acme.bank-transfer', $input('nope'))->assertSessionHasErrors('settings.iban');
        $this->put('/admin/payment-methods/acme.bank-transfer', $input('GR1601101250000000012300695'))->assertSessionHasNoErrors();
        self::assertTrue((bool) DB::table('payment_methods')->where('code', 'acme.bank-transfer')->value('is_active'));
        $product = $this->orderProduct('15.00');
        $order = app(OrderAdministration::class)->create((string) $this->actor->id, $this->orderInput((string) $product->id, ['payment_method_id' => $this->methodId('acme.bank-transfer')]));
        self::assertSame('Bank transfer', DB::table('orders')->where('id', $order)->value('payment_method_name'));

        // The same package again is refused; an upgrade must be explicit and newer.
        $this->refused(fn () => $this->manager()->upload((string) $this->actor->id, $this->zip(base_path(self::EXAMPLE)), false), 'addons.error_installed');
        $this->refused(fn () => $this->manager()->upload((string) $this->actor->id, $this->zip(base_path(self::EXAMPLE)), true), 'addons.error_not_newer');
        $this->upload($this->zip($this->package(null, ['version' => '1.1.0']), 'acme-bank-transfer/'), true)->assertSessionHasNoErrors();
        self::assertSame(['1.1.0', 'enabled'], array_values((array) DB::table('addons')->where('name', 'acme/bank-transfer')->first(['version', 'status'])));
        self::assertStringContainsString('1.1.0', (string) file_get_contents($this->tmp.'/installed/acme/bank-transfer/composer.json'));

        $this->delete('/admin/addons/acme/bank-transfer')->assertSessionHasErrors('addon');
        $this->post('/admin/addons/acme/bank-transfer/disable')->assertSessionHasNoErrors();
        $this->get('/admin/orders/create')->assertInertia(fn (Assert $page) => $page->where('paymentMethods', fn ($methods) => collect($methods)->pluck('name')->doesntContain('Bank transfer')));
        $this->delete('/admin/addons/acme/bank-transfer')->assertSessionHasNoErrors();
        self::assertFalse(file_exists($this->tmp.'/installed/acme/bank-transfer'));
        // Historical orders keep their method and the stored method row stays.
        self::assertSame('Bank transfer', DB::table('orders')->where('id', $order)->value('payment_method_name'));
        $this->get('/admin/orders/'.$order)->assertOk()->assertInertia(fn (Assert $page) => $page->where('payment.method.name', 'Bank transfer'));
    }

    /** @return array<string, array{callable(self): string, string}> */
    public static function invalidPackages(): array
    {
        return [
            'path traversal' => [fn (self $t) => $t->zip($t->package('acme/evil'), '', ['../evil.php' => '<?php echo 1;']), 'addons.error_zip_path'],
            'nested traversal' => [fn (self $t) => $t->zip($t->package('acme/evil'), '', ['src/../../evil.php' => '<?php']), 'addons.error_zip_path'],
            'absolute path' => [fn (self $t) => $t->zip($t->package('acme/evil'), '', ['/tmp/evil.php' => '<?php']), 'addons.error_zip_path'],
            'backslash path' => [fn (self $t) => $t->zip($t->package('acme/evil'), '', ['src\\evil.php' => '<?php']), 'addons.error_zip_path'],
            'hidden server config' => [fn (self $t) => $t->zip($t->package('acme/evil'), '', ['.htaccess' => 'Options +ExecCGI']), 'addons.error_zip_path'],
            'phar archive' => [fn (self $t) => $t->zip($t->package('acme/evil'), '', ['src/tool.phar' => 'x']), 'addons.error_zip_type'],
            'case duplicate' => [fn (self $t) => $t->zip($t->package('acme/evil'), '', ['src/Readme.txt' => 'a', 'src/README.TXT' => 'b']), 'addons.error_zip_duplicate'],
            'no manifest' => [fn (self $t) => $t->zip($t->package('acme/evil', [], ['composer.json' => null])), 'addons.error_zip_manifest'],
            'invalid json' => [fn (self $t) => $t->zip($t->package('acme/evil', [], ['composer.json' => '{nope'])), 'addons.error_manifest_json'],
            'contract 1' => [fn (self $t) => $t->zip($t->package('acme/evil', ['extra' => ['cms-module' => ['contract' => 1]]])), 'addons.error_manifest_contract'],
            'incompatible cms' => [fn (self $t) => $t->zip($t->package('acme/evil', ['extra' => ['cms-module' => ['cms' => '^9.0']]])), 'addons.error_incompatible'],
            'core namespace' => [fn (self $t) => $t->zip($t->package('acme/evil', ['autoload' => ['psr-4' => ['App\\Evil\\' => 'src/']], 'extra' => ['cms-module' => ['provider' => 'App\\Evil\\Provider']]])), 'addons.error_manifest_namespace'],
            'missing provider file' => [fn (self $t) => $t->zip($t->package('acme/evil', [], ['src/Provider.php' => null])), 'addons.error_provider_class'],
            'unprefixed migration' => [fn (self $t) => $t->zip($t->package('acme/evil', ['extra' => ['cms-module' => ['migrations' => 'database']]], ['database/2026_01_01_000000_create_users.php' => '<?php'])), 'addons.error_migration_name'],
            'unknown extension point' => [fn (self $t) => $t->zip($t->package('acme/evil', ['extra' => ['cms-module' => ['contributes' => ['admin-pages' => ['x' => 'y']]]]])), 'addons.error_unknown_contribution'],
            'core payment method id' => [fn (self $t) => $t->zip($t->package('acme/evil', ['extra' => ['cms-module' => ['contributes' => ['payment-methods' => ['cod' => 'TestAddonAcmeEvil\\Provider']]]]])), 'addons.error_payment_method_taken'],
            'missing dependency' => [fn (self $t) => $t->zip($t->package('acme/evil', ['require' => ['acme/base' => '^1.0'], 'extra' => ['cms-module' => ['requires' => ['acme/base']]]])), 'addons.error_dependency_missing'],
        ];
    }

    #[DataProvider('invalidPackages')]
    public function test_invalid_packages_are_refused_and_leave_nothing_behind(callable $build, string $key): void
    {
        $this->refused(fn () => $this->manager()->upload((string) $this->actor->id, $build($this), false), $key);
        self::assertSame(0, DB::table('addons')->count());
        self::assertSame([], glob($this->tmp.'/installed/*') ?: []);
        self::assertTrue($this->stagingEmpty());
    }

    public function test_symbolic_links_and_encrypted_entries_are_refused(): void
    {
        $file = $this->zip($this->package('acme/links'));
        $zip = new ZipArchive;
        $zip->open($file);
        $zip->addFromString('src/link.php', '/etc/passwd');
        $zip->setExternalAttributesName('src/link.php', ZipArchive::OPSYS_UNIX, (0120777 << 16));
        $zip->close();
        $this->refused(fn () => $this->manager()->upload((string) $this->actor->id, $file, false), 'addons.error_zip_link');

        $file = $this->zip($this->package('acme/secret'));
        $zip = new ZipArchive;
        $zip->open($file);
        $zip->setPassword('pw');
        $zip->setEncryptionName('src/Provider.php', ZipArchive::EM_AES_256);
        $zip->close();
        $this->refused(fn () => $this->manager()->upload((string) $this->actor->id, $file, false), 'addons.error_zip_encrypted');
        self::assertSame(0, DB::table('addons')->count());
    }

    public function test_owned_migrations_run_on_install_and_a_failing_one_rolls_everything_back(): void
    {
        $migration = "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Support\\Facades\\Schema;\n\nreturn new class extends Migration {\n    public function up(): void\n    {\n        Schema::create('acme_widgets_items', fn (\$t) => \$t->id());\n%s    }\n};\n";
        $broken = $this->package('acme/widgets', ['extra' => ['cms-module' => ['migrations' => 'database/migrations']]], [
            // Laravel caches required migration files per path within one process, so the broken attempt uses its own name.
            'database/migrations/2026_01_01_000000_acme_widgets_create_items_broken.php' => sprintf($migration, "        throw new \\RuntimeException('boom');\n"),
        ]);
        $this->refused(fn () => $this->manager()->upload((string) $this->actor->id, $this->zip($broken), false), 'addons.error_migration_failed');
        self::assertFalse(Schema::hasTable('acme_widgets_items'));
        self::assertFalse(DB::table('migrations')->where('migration', 'like', '%acme_widgets%')->exists());
        self::assertSame([0, []], [DB::table('addons')->count(), glob($this->tmp.'/installed/*/*') ?: []]);

        $working = $this->package('acme/widgets', ['extra' => ['cms-module' => ['migrations' => 'database/migrations']]], [
            'database/migrations/2026_01_01_000000_acme_widgets_create_items.php' => sprintf($migration, ''),
        ]);
        $this->manager()->upload((string) $this->actor->id, $this->zip($working), false);
        self::assertTrue(Schema::hasTable('acme_widgets_items'));
        self::assertTrue(DB::table('migrations')->where('migration', '2026_01_01_000000_acme_widgets_create_items')->exists());
        // A failing upgrade restores the previous files and version.
        $failingUpgrade = $this->package('acme/widgets', ['version' => '2.0.0', 'extra' => ['cms-module' => ['migrations' => 'database/migrations']]], [
            'database/migrations/2026_01_01_000000_acme_widgets_create_items.php' => sprintf($migration, ''),
            'database/migrations/2026_02_01_000000_acme_widgets_fail.php' => "<?php\n\nreturn new class extends Illuminate\\Database\\Migrations\\Migration {\n    public function up(): void\n    {\n        throw new RuntimeException('boom');\n    }\n};\n",
        ]);
        $this->refused(fn () => $this->manager()->upload((string) $this->actor->id, $this->zip($failingUpgrade), true), 'addons.error_migration_failed');
        self::assertSame('1.0.0', DB::table('addons')->where('name', 'acme/widgets')->value('version'));
        self::assertStringContainsString('"1.0.0"', (string) file_get_contents($this->tmp.'/installed/acme/widgets/composer.json'));
        // Uninstalling keeps the add-on's tables and migration history.
        $this->manager()->uninstall((string) $this->actor->id, 'acme/widgets');
        self::assertTrue(Schema::hasTable('acme_widgets_items'));
        self::assertFalse(is_dir($this->tmp.'/installed/acme/widgets'));
    }

    public function test_dependencies_are_enforced_across_the_lifecycle(): void
    {
        $actor = (string) $this->actor->id;
        $dependent = $this->package('acme/reports', ['require' => ['acme/core' => '^1.0'], 'extra' => ['cms-module' => ['requires' => ['acme/core']]]]);
        $this->refused(fn () => $this->manager()->upload($actor, $this->zip($dependent), false), 'addons.error_dependency_missing');
        $this->manager()->upload($actor, $this->zip($this->package('acme/core')), false);
        $this->manager()->upload($actor, $this->zip($dependent), false);
        $this->refused(fn () => $this->manager()->enable($actor, 'acme/reports'), 'addons.error_dependency_disabled');
        $this->manager()->enable($actor, 'acme/core');
        $this->manager()->enable($actor, 'acme/reports');
        $this->refused(fn () => $this->manager()->disable($actor, 'acme/core'), 'addons.error_required_by');
        $this->manager()->disable($actor, 'acme/reports');
        $this->manager()->disable($actor, 'acme/core');
        $this->refused(fn () => $this->manager()->uninstall($actor, 'acme/core'), 'addons.error_required_by');
        $this->manager()->uninstall($actor, 'acme/reports');
        $this->manager()->uninstall($actor, 'acme/core');
        self::assertSame(0, DB::table('addons')->count());
    }

    public function test_uploads_require_their_own_capability_and_can_be_switched_off(): void
    {
        $zip = $this->zip(base_path(self::EXAMPLE));
        $this->flushSession();
        $this->actingAs($this->staff(['admin.access', 'addons.manage']));
        $this->get('/admin/addons')->assertOk()->assertInertia(fn (Assert $page) => $page->where('uploads.allowed', false));
        $this->upload($zip)->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->staff(['admin.access', 'orders.view']));
        $this->post('/admin/addons/ambresoft/stripe-checkout/install')->assertForbidden();
        $this->upload($zip)->assertForbidden();
        self::assertSame(0, DB::table('addons')->count());

        $this->flushSession();
        $this->actingAs($this->actor);
        config(['modules.uploads' => false]);
        $this->upload($zip)->assertSessionHasErrors('package');
        config(['modules.uploads' => true, 'modules.upload_max_kb' => 1]);
        $this->upload($zip)->assertSessionHasErrors('package');
        $text = $this->tmp.'/not-a-zip.zip';
        file_put_contents($text, 'plain text');
        config(['modules.upload_max_kb' => 20480]);
        $this->post('/admin/addons/upload', ['package' => new UploadedFile($text, 'addon.zip', 'application/zip', null, true)])->assertSessionHasErrors('package');
        self::assertSame(0, DB::table('addons')->count());
    }

    public function test_the_validate_command_runs_the_installer_checks_without_installing(): void
    {
        $this->artisan('cms:addon-validate', ['path' => base_path(self::EXAMPLE)])->expectsOutputToContain('acme/bank-transfer 1.0.0')->assertSuccessful();
        $this->artisan('cms:addon-validate', ['path' => $this->zip(base_path(self::EXAMPLE), 'acme/')])->expectsOutputToContain('Contributes payment-methods: acme.bank-transfer')->assertSuccessful();
        $this->artisan('cms:addon-validate', ['path' => $this->zip($this->package('acme/evil'), '', ['../x.php' => '<?php'])])->assertFailed();
        self::assertSame(0, DB::table('addons')->count());
        self::assertTrue($this->stagingEmpty());
    }

    public function test_an_addon_icon_is_served_inert_and_invalid_icons_are_refused(): void
    {
        $this->actingAs($this->actor);
        // Bundled first-party add-ons ship the company mark; the list exposes a cache-busted URL.
        $row = collect($this->manager()->overview())->firstWhere('name', 'ambresoft/stripe-checkout');
        self::assertStringStartsWith('/admin/addons/ambresoft/stripe-checkout/icon?v=', $row['icon_url']);
        $response = $this->get('/admin/addons/ambresoft/stripe-checkout/icon')->assertOk();
        self::assertSame('image/svg+xml', $response->headers->get('Content-Type'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));
        $this->get('/admin/addons/acme/unknown/icon')->assertNotFound();

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><path d="M0 0h10v10z"/></svg>';
        foreach ([
            'script' => ['icon.svg', str_replace('<path', '<script>alert(1)</script><path', $svg)],
            'handler' => ['icon.svg', str_replace('<path', '<path onload="x()"', $svg)],
            'type' => ['icon.exe', 'x'],
            'empty' => ['icon.png', ''],
            'large' => ['icon.png', str_repeat('x', 262145)],
        ] as $case => [$file, $content]) {
            $dir = $this->package('acme/icon-'.$case, ['extra' => ['cms-module' => ['icon' => $file]]], [$file => $content]);
            try {
                ModuleManifest::fromDirectory($dir);
                self::fail('The '.$case.' icon was accepted');
            } catch (AddonException $e) {
                self::assertStringStartsWith('addons.error_manifest_icon', $e->getMessage());
            }
        }
        $good = $this->package('acme/icon-ok', ['extra' => ['cms-module' => ['icon' => 'brand/icon.svg']]], ['brand/icon.svg' => $svg]);
        self::assertSame('brand/icon.svg', ModuleManifest::fromDirectory($good)->icon);
        self::assertNull(ModuleManifest::fromDirectory($this->package('acme/no-icon'))->icon);
    }
}
