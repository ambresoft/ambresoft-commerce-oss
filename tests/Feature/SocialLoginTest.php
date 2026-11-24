<?php

namespace Tests\Feature;

use App\Identity\Infrastructure\Persistence\UserRecord;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class SocialLoginTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private const CLIENT_ID = '1234567890-abcdefg.apps.googleusercontent.com';

    private const SECRET = 'GOCSPX-super-secret-value';

    private UserRecord $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actor = $this->staff(super: true);
        $this->actingAs($this->actor);
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return [...['provider' => 'google', 'name' => 'Google', 'is_active' => true, 'settings' => ['client_id' => self::CLIENT_ID, 'client_secret' => self::SECRET]], ...$overrides];
    }

    private function create(array $overrides = []): string
    {
        $this->post('/admin/social-login', $this->payload($overrides))->assertSessionHasNoErrors()->assertRedirect();

        return (string) DB::table('social_login_providers')->latest('id')->value('id');
    }

    private function version(string $id): int
    {
        return (int) DB::table('social_login_providers')->where('id', $id)->value('lock_version');
    }

    public function test_a_google_provider_is_created_with_an_encrypted_write_only_secret(): void
    {
        $this->get('/admin/social-login/create')->assertInertia(fn (Assert $page) => $page->component('Admin/SocialLogin/Form')->where('provider', null)
            ->where('catalog.0.id', 'google')->where('catalog.0.fields.0.key', 'client_id')->where('catalog.0.fields.1.type', 'secret')
            ->where('catalog.0.callback_url', rtrim((string) config('app.url'), '/').'/auth/social/google/callback'));
        $id = $this->create(['name' => ' Google ']);

        $row = DB::table('social_login_providers')->sole();
        self::assertSame(['google', 'Google', true, 1], [$row->provider, $row->name, $row->is_active, (int) $row->lock_version]);
        self::assertSame(self::CLIENT_ID, json_decode($row->settings, true)['client_id']);
        self::assertStringNotContainsString(self::SECRET, $row->settings.$row->secrets);
        self::assertSame(['client_secret' => self::SECRET], json_decode(Crypt::decryptString($row->secrets), true));

        $page = $this->get('/admin/social-login/'.$id.'/edit');
        $page->assertInertia(fn (Assert $p) => $p->where('provider.fields.0.value', self::CLIENT_ID)->where('provider.fields.1.value', '')->where('provider.fields.1.set', true));
        self::assertStringNotContainsString(self::SECRET, $page->getContent());
        $list = $this->get('/admin/social-login');
        $list->assertInertia(fn (Assert $p) => $p->component('Admin/SocialLogin/Index')->where('providers.0.name', 'Google')->where('providers.0.provider_name', 'Google')->where('providers.0.active', true)->where('providers.0.complete', true)->missing('providers.0.settings')->missing('providers.0.secrets'));
        self::assertStringNotContainsString(self::SECRET, $list->getContent());
    }

    public function test_invalid_input_is_refused_beside_the_field(): void
    {
        $this->post('/admin/social-login', $this->payload(['name' => '  ']))->assertSessionHasErrors('name');
        $this->post('/admin/social-login', $this->payload(['provider' => 'facebook']))->assertSessionHasErrors('provider');
        $this->post('/admin/social-login', $this->payload(['settings' => ['client_id' => 'not-a-google-id', 'client_secret' => self::SECRET]]))->assertSessionHasErrors('settings.client_id');
        $this->post('/admin/social-login', $this->payload(['settings' => ['client_id' => '', 'client_secret' => '']]))->assertSessionHasErrors(['settings.client_id', 'settings.client_secret']);
        $this->post('/admin/social-login', $this->payload(['settings' => ['client_id' => self::CLIENT_ID, 'client_secret' => str_repeat('x', 201)]]))->assertSessionHasErrors('settings.client_secret');
        self::assertSame(0, DB::table('social_login_providers')->count());
    }

    public function test_editing_keeps_the_secret_unless_replaced_and_refuses_stale_versions(): void
    {
        $id = $this->create();
        $before = DB::table('social_login_providers')->where('id', $id)->value('secrets');
        $this->put('/admin/social-login/'.$id, $this->payload(['version' => 1, 'name' => 'Google login', 'settings' => ['client_id' => self::CLIENT_ID, 'client_secret' => '']]))->assertSessionHasNoErrors()->assertRedirect('/admin/social-login/'.$id.'/edit');
        $row = DB::table('social_login_providers')->where('id', $id)->first();
        self::assertSame(['Google login', 2], [$row->name, (int) $row->lock_version]);
        self::assertSame(self::SECRET, json_decode(Crypt::decryptString($row->secrets), true)['client_secret']);
        self::assertSame(json_decode(Crypt::decryptString($before), true), json_decode(Crypt::decryptString($row->secrets), true));

        $this->put('/admin/social-login/'.$id, $this->payload(['version' => 2, 'settings' => ['client_id' => self::CLIENT_ID, 'client_secret' => 'GOCSPX-rotated']]))->assertSessionHasNoErrors();
        self::assertSame('GOCSPX-rotated', json_decode(Crypt::decryptString(DB::table('social_login_providers')->where('id', $id)->value('secrets')), true)['client_secret']);

        $this->put('/admin/social-login/'.$id, $this->payload(['version' => 1]))->assertSessionHasErrors('version');
        $this->put('/admin/social-login/'.$id, $this->payload(['version' => 3, 'settings' => ['client_id' => self::CLIENT_ID], 'clear' => ['client_secret' => true]]))->assertSessionHasErrors('settings.client_secret');
        self::assertSame(3, $this->version($id));
        $this->put('/admin/social-login/'.$id, $this->payload(['provider' => 'other']))->assertSessionHasErrors('version');
    }

    public function test_names_are_unique_and_only_one_provider_per_type_is_active(): void
    {
        $first = $this->create();
        $this->post('/admin/social-login', $this->payload(['name' => 'GOOGLE', 'is_active' => false]))->assertSessionHasErrors('name');
        $this->post('/admin/social-login', $this->payload(['name' => 'Google (staging)', 'is_active' => true]))->assertSessionHasErrors('is_active');
        $second = $this->create(['name' => 'Google (staging)', 'is_active' => false]);
        $this->patch('/admin/social-login/'.$second.'/active', ['version' => 1, 'is_active' => true])->assertSessionHasErrors('is_active');
        $this->patch('/admin/social-login/'.$first.'/active', ['version' => 1, 'is_active' => false])->assertSessionHasNoErrors();
        $this->patch('/admin/social-login/'.$second.'/active', ['version' => 1, 'is_active' => true])->assertSessionHasNoErrors();
        $this->patch('/admin/social-login/'.$first.'/active', ['version' => 1, 'is_active' => true])->assertSessionHasErrors(['version']);
        self::assertSame([false, true], [(bool) DB::table('social_login_providers')->where('id', $first)->value('is_active'), (bool) DB::table('social_login_providers')->where('id', $second)->value('is_active')]);
    }

    public function test_the_database_protects_the_same_rules(): void
    {
        $this->create();
        $row = ['provider' => 'google', 'name' => 'Other', 'settings' => '{}', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()];
        foreach ([['name' => 'google'], ['name' => 'Another'], ['name' => ' ', 'is_active' => false], ['provider' => 'Google!', 'is_active' => false, 'name' => 'Bad']] as $override) {
            try {
                DB::transaction(fn () => DB::table('social_login_providers')->insert([...$row, ...$override]));
                self::fail('The database accepted '.json_encode($override));
            } catch (QueryException) {
                self::assertSame(1, DB::table('social_login_providers')->count());
            }
        }
    }

    public function test_deletion_requires_the_current_version(): void
    {
        $id = $this->create();
        $this->delete('/admin/social-login/'.$id, ['version' => 5])->assertSessionHasErrors('version');
        self::assertSame(1, DB::table('social_login_providers')->count());
        $this->delete('/admin/social-login/'.$id, ['version' => 1])->assertSessionHasNoErrors()->assertRedirect('/admin/social-login');
        self::assertSame(0, DB::table('social_login_providers')->count());
        $this->get('/admin/social-login/'.$id.'/edit')->assertNotFound();
    }

    public function test_permissions_are_enforced_on_the_server(): void
    {
        $id = $this->create();
        $this->flushSession();
        $this->actingAs($this->staff(['admin.access', 'social-login.view']));
        $this->get('/admin/social-login')->assertOk()->assertInertia(fn (Assert $p) => $p->where('navigation', fn ($n) => collect($n)->contains(fn ($i) => $i['id'] === 'core.social-login' && $i['group']['id'] === 'core.website')));
        $this->get('/admin/social-login/'.$id.'/edit')->assertOk();
        $this->get('/admin/social-login/create')->assertForbidden();
        $this->post('/admin/social-login', $this->payload(['name' => 'Nope']))->assertForbidden();
        $this->put('/admin/social-login/'.$id, $this->payload(['version' => 1]))->assertForbidden();
        $this->patch('/admin/social-login/'.$id.'/active', ['version' => 1, 'is_active' => false])->assertForbidden();
        $this->delete('/admin/social-login/'.$id, ['version' => 1])->assertForbidden();

        $this->flushSession();
        $this->actingAs($this->staff(['admin.access']));
        $this->get('/admin/social-login')->assertForbidden();
        $this->get('/admin')->assertInertia(fn (Assert $p) => $p->where('navigation', fn ($n) => ! collect($n)->contains('id', 'core.social-login')));
        self::assertSame(1, DB::table('social_login_providers')->count());
    }

    public function test_changes_are_audited_without_the_secret(): void
    {
        $id = $this->create();
        $this->put('/admin/social-login/'.$id, $this->payload(['version' => 1, 'settings' => ['client_id' => self::CLIENT_ID, 'client_secret' => 'GOCSPX-rotated']]))->assertSessionHasNoErrors();
        $entries = DB::table('audit_logs')->where('entity_type', 'social_login_provider')->get();
        self::assertNotEmpty($entries);
        $serialized = json_encode($entries);
        self::assertStringNotContainsString(self::SECRET, $serialized);
        self::assertStringNotContainsString('GOCSPX-rotated', $serialized);
    }
}
