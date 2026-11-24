<?php

namespace Tests\Feature;

use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Shared\Application\Command\AddonPanelAction;
use App\Shared\Application\Contract\AddonStatus;
use App\Shared\Infrastructure\Modules\AddonManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\GeneratesAddons;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

/** The generic Settings panel and the `uninstall-clears` manifest key, through a throwaway add-on. */
final class AddonSettingsPanelTest extends TestCase
{
    use GeneratesAddons, RefreshDatabase, StaffIdentities;

    private const ADDON = 'acme/panel';

    private const SETTINGS = '/admin/addons/acme/panel/settings';

    private const SECRET = 'the-one-time-value';

    private UserRecord $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->admin = $this->staff(super: true);
        $settings = <<<'PHP'
<?php

namespace {ns};

use App\Shared\Application\Command\AddonPanelAction;
use App\Shared\Application\Command\AddonPanelRow;
use App\Shared\Application\Command\AddonPanelSecret;
use App\Shared\Application\Contract\AddonSettings;
use App\Shared\Application\Contract\AddonSettingsPanel;

final class Settings implements AddonSettings, AddonSettingsPanel
{
    public function settings(): array { return []; }

    public function validateSettings(array $values): array { return []; }

    public function panelRows(): array { return [new AddonPanelRow('State', 'Ready')]; }

    public function panelActions(): array { return [new AddonPanelAction('issue', 'Issue', 'Sure?', true), new AddonPanelAction('quiet', 'Quiet')]; }

    public function runPanelAction(string $action): ?AddonPanelSecret
    {
        return $action === 'issue' ? new AddonPanelSecret('Value', 'Copy it now', 'the-one-time-value') : null;
    }
}
PHP;
        $migration = <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acme_panel_tokens', fn (Blueprint $t) => $t->id());
        Schema::create('acme_panel_notes', fn (Blueprint $t) => $t->id());
    }

    public function down(): void
    {
        Schema::dropIfExists('acme_panel_notes');
        Schema::dropIfExists('acme_panel_tokens');
    }
};
PHP;
        $namespace = $this->installAddon(self::ADDON, [
            'src/Provider.php' => "<?php\n\nnamespace {ns};\n\nfinal class Provider extends \\Illuminate\\Support\\ServiceProvider {}\n",
            'src/Settings.php' => $settings,
            'database/migrations/2026_01_01_000000_acme_panel_create_tokens.php' => $migration,
        ], ['migrations' => 'database/migrations', 'uninstall-clears' => ['acme_panel_tokens'], 'contributes' => ['settings' => '{ns}\\Settings']]);
        self::assertNotSame('', $namespace);
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        $this->removeGeneratedAddons();
        parent::tearDown();
    }

    public function test_the_page_shows_the_panel_without_a_form(): void
    {
        $this->get(self::SETTINGS)->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Addons/Settings')
            ->where('addon.panel.rows', [['label' => 'State', 'value' => 'Ready']])
            ->where('addon.panel.actions.0', ['key' => 'issue', 'label' => 'Issue', 'confirm' => 'Sure?', 'destructive' => true, 'in_form' => false])->where('addon.fields', []));
    }

    public function test_a_secret_leaves_only_in_the_action_response(): void
    {
        $response = $this->postJson(self::SETTINGS.'/actions/issue')->assertOk()->assertJsonPath('secret.value', self::SECRET)->assertJsonPath('secret.hint', 'Copy it now');

        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringNotContainsString(self::SECRET, json_encode(session()->all(), JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString(self::SECRET, (string) $this->get(self::SETTINGS)->getContent());
        self::assertStringNotContainsString(self::SECRET, DB::table('audit_logs')->get()->toJson());
    }

    public function test_an_action_without_a_secret_and_the_audit_trail(): void
    {
        $this->postJson(self::SETTINGS.'/actions/quiet')->assertOk()->assertExactJson(['secret' => null]);

        $row = DB::table('audit_logs')->where('action', 'addon.settings_action')->sole();
        self::assertSame(self::ADDON, $row->entity_id);
        self::assertSame((string) $this->admin->id, (string) $row->actor_id);
    }

    public function test_only_actions_on_offer_run(): void
    {
        $this->postJson(self::SETTINGS.'/actions/other')->assertNotFound();
        $this->postJson('/admin/addons/acme/unknown/settings/actions/issue')->assertNotFound();
    }

    public function test_actions_need_the_addons_capability(): void
    {
        $this->actingAs($this->staff(['admin.access']));

        $this->postJson(self::SETTINGS.'/actions/issue')->assertForbidden();
    }

    public function test_a_disabled_addon_offers_nothing(): void
    {
        app(AddonManager::class)->disable(null, self::ADDON);

        self::assertFalse(app(AddonStatus::class)->isEnabled(self::ADDON));
        $this->postJson(self::SETTINGS.'/actions/issue')->assertNotFound();
    }

    public function test_the_status_contract_follows_the_lifecycle(): void
    {
        self::assertTrue(app(AddonStatus::class)->isEnabled(self::ADDON));
        self::assertFalse(app(AddonStatus::class)->isEnabled('acme/unknown'));
    }

    /** The add-on writes what it received here, so a test can see what reached it. */
    private function remoteAddon(): string
    {
        $settings = <<<'PHP'
<?php

namespace {ns};

use App\Shared\Application\Command\AddonPanelRow;
use App\Shared\Application\Command\AddonRemoteChoice;
use App\Shared\Application\Command\AddonRemoteForm;
use App\Shared\Application\Command\SettingField;
use App\Shared\Application\Contract\AddonRemoteSettings;
use App\Shared\Application\Contract\AddonSettings;

final class Settings implements AddonSettings, AddonRemoteSettings
{
    public static array $received = [];

    public function settings(): array { return [new SettingField('plain', 'text', 'Plain')]; }

    public function validateSettings(array $values): array { return []; }

    public function remoteForm(): ?AddonRemoteForm
    {
        $fields = [new SettingField('model', 'text', 'Model', maxLength: 20), new SettingField('api_key', 'secret', 'API key')];

        return new AddonRemoteForm('Provider', 'Choose', [new AddonRemoteChoice('alpha', 'Alpha', 'First', $fields, ['model' => 'one', 'api_key' => 'x'], icon: 'data:image/png;base64,AA=='), new AddonRemoteChoice('beta', 'Beta', 'Second', $fields, icon: 'https://elsewhere.test/logo.png')], 'alpha', [new AddonPanelRow('Status', 'Connected')]);
    }

    public function submitRemoteForm(string $choice, array $values): array
    {
        self::$received = [$choice, $values];

        return $values['model'] === 'refused' ? ['model' => 'No such model'] : [];
    }
}
PHP;

        return $this->installAddon('acme/remote', [
            'src/Provider.php' => "<?php\n\nnamespace {ns};\n\nfinal class Provider extends \\Illuminate\\Support\\ServiceProvider {}\n",
            'src/Settings.php' => $settings,
        ], ['contributes' => ['settings' => '{ns}\\Settings']]);
    }

    public function test_a_remote_form_shows_its_cards_and_never_a_stored_secret(): void
    {
        $this->remoteAddon();

        $this->actingAs($this->admin)->get('/admin/addons/acme/remote/settings')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('addon.remote.title', 'Provider')->where('addon.remote.selected', 'alpha')->has('addon.remote.choices', 2)
            ->where('addon.remote.rows.0.value', 'Connected')
            ->where('addon.remote.choices.0.icon', 'data:image/png;base64,AA==')->where('addon.remote.choices.1.icon', '')
            ->where('addon.remote.choices.0.fields.0.value', 'one')
            ->where('addon.remote.choices.0.fields.1.value', '')->where('addon.remote.choices.0.fields.1.set', true)
            ->where('addon.remote.choices.1.fields.1.set', false));
    }

    public function test_a_remote_submission_reaches_the_addon_and_stays_nowhere_here(): void
    {
        $namespace = $this->remoteAddon();

        $this->actingAs($this->admin)->postJson('/admin/addons/acme/remote/settings/remote', ['choice' => 'beta', 'values' => ['model' => 'two', 'api_key' => 'the-raw-value']])
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private');

        $class = $namespace.'\\Settings';
        self::assertSame(['beta', ['model' => 'two', 'api_key' => 'the-raw-value']], $class::$received);
        self::assertStringNotContainsString('the-raw-value', json_encode(DB::table('addon_settings')->get(), JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('the-raw-value', json_encode(DB::table('audit_logs')->get(), JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('the-raw-value', json_encode(session()->all(), JSON_THROW_ON_ERROR));
        self::assertDatabaseHas('audit_logs', ['action' => 'addon.settings_action', 'entity_label' => 'remote:beta']);
    }

    public function test_a_refused_remote_submission_answers_json_and_flashes_nothing(): void
    {
        $this->remoteAddon();

        $this->actingAs($this->admin)->postJson('/admin/addons/acme/remote/settings/remote', ['choice' => 'alpha', 'values' => ['model' => 'refused', 'api_key' => 'the-raw-value']])
            ->assertStatus(422)->assertExactJson(['errors' => ['model' => 'No such model']]);
        $this->actingAs($this->admin)->postJson('/admin/addons/acme/remote/settings/remote', ['choice' => 'nope', 'values' => []])
            ->assertStatus(422)->assertJsonPath('errors.form', __('settings_fields.choice_invalid'));
        $this->actingAs($this->admin)->postJson('/admin/addons/acme/remote/settings/remote', ['choice' => 'alpha', 'values' => ['model' => str_repeat('a', 30)]])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['model']]);

        self::assertStringNotContainsString('the-raw-value', json_encode(session()->all(), JSON_THROW_ON_ERROR));
        self::assertDatabaseMissing('audit_logs', ['entity_label' => 'remote:alpha']);
    }

    public function test_remote_forms_need_the_capability(): void
    {
        $this->remoteAddon();

        $this->actingAs($this->staff(['admin.access']))->postJson('/admin/addons/acme/remote/settings/remote', ['choice' => 'alpha', 'values' => []])->assertForbidden();
    }

    public function test_an_addon_without_a_remote_form_has_no_such_route(): void
    {
        $this->postJson('/admin/addons/acme/panel/settings/remote', ['choice' => 'alpha', 'values' => []])->assertNotFound();
    }

    public function test_actions_can_sit_beside_the_form(): void
    {
        $action = new AddonPanelAction('test', 'Test', inForm: true);

        self::assertTrue($action->inForm);
        self::assertFalse((new AddonPanelAction('other', 'Other'))->inForm);
    }

    public function test_uninstalling_clears_only_the_declared_table(): void
    {
        DB::table('acme_panel_tokens')->insert(['id' => 1]);
        DB::table('acme_panel_notes')->insert(['id' => 1]);
        $manager = app(AddonManager::class);
        $manager->disable(null, self::ADDON);
        self::assertSame(1, DB::table('acme_panel_tokens')->count(), 'disabling keeps data');

        $manager->uninstall(null, self::ADDON);

        self::assertTrue(Schema::hasTable('acme_panel_tokens'), 'the table stays, as uninstalling always keeps tables');
        self::assertSame(0, DB::table('acme_panel_tokens')->count());
        self::assertSame(1, DB::table('acme_panel_notes')->count(), 'everything else is retained as before');
    }
}
