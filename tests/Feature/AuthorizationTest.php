<?php

namespace Tests\Feature;

use App\Authorization\Application\Command\ManageStaffHandler;
use App\Authorization\Application\Command\SaveStaffData;
use App\Authorization\Infrastructure\AccessChecker;
use App\Authorization\Infrastructure\AuthorizationServiceProvider;
use App\Authorization\Infrastructure\PermissionRegistry;
use App\Authorization\Infrastructure\Persistence\PermissionRecord;
use App\Authorization\Infrastructure\Persistence\RoleRecord;
use App\Identity\Infrastructure\Persistence\UserRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class AuthorizationTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
    }

    public function test_reference_roles_and_permissions_sync_is_idempotent_without_overwriting_grants(): void
    {
        $role = RoleRecord::where('slug', 'catalog-manager')->sole();
        $role->permissions()->detach();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->assertDatabaseCount('roles', 5);
        $this->assertDatabaseCount('permissions', count(app(PermissionRegistry::class)->all()));
        self::assertSame(0, $role->permissions()->count());
        self::assertSame(1, RoleRecord::where('is_super_admin', true)->count());
    }

    public function test_reference_roles_grant_only_the_intended_commerce_capabilities(): void
    {
        $catalog = ['products.view', 'products.create', 'products.update', 'products.delete', 'categories.view', 'categories.create', 'categories.update', 'categories.delete'];
        $orders = ['orders.view', 'orders.update'];
        $commerce = [...$catalog, ...$orders];
        $expected = [
            'super-admin' => $commerce,
            'store-admin' => $commerce,
            'catalog-manager' => $catalog,
            'order-manager' => $orders,
            'content-manager' => ['categories.view', 'categories.update'],
        ];
        foreach ($expected as $slug => $abilities) {
            $user = $this->staff();
            $user->roles()->sync([RoleRecord::where('slug', $slug)->sole()->id]);
            foreach ($commerce as $ability) {
                self::assertSame(in_array($ability, $abilities, true), Gate::forUser($user)->allows($ability), $slug.': '.$ability);
            }
            self::assertTrue(Gate::forUser($user)->allows('admin.access'));
        }
        $guestStaff = $this->staff();
        foreach ($commerce as $ability) {
            self::assertFalse(Gate::forUser($guestStaff)->allows($ability));
        }
        // Payments: order managers record payments, store admins configure methods; add-ons stay with super admins.
        $payments = ['orders.payments' => ['store-admin', 'order-manager'], 'payment-methods.manage' => ['store-admin'], 'addons.manage' => [], 'addons.upload' => []];
        foreach (array_keys($expected) as $slug) {
            $user = $this->staff();
            $user->roles()->sync([RoleRecord::where('slug', $slug)->sole()->id]);
            foreach ($payments as $ability => $roles) {
                self::assertSame($slug === 'super-admin' || in_array($slug, $roles, true), Gate::forUser($user)->allows($ability), $slug.': '.$ability);
            }
        }
        // Products (Phase 4) and Orders (Phase 9) are implemented.
        $this->actingAs($this->staff(super: true));
        $this->get('/admin/products')->assertOk();
        $this->get('/admin/orders')->assertOk();
    }

    public function test_explicit_commerce_grant_upgrade_is_additive_idempotent_and_leaves_custom_roles_alone(): void
    {
        $role = RoleRecord::where('slug', 'catalog-manager')->sole();
        $custom = RoleRecord::create(['name' => 'Custom', 'slug' => 'custom']);
        $role->permissions()->sync([PermissionRecord::where('name', 'users.view')->sole()->id]);
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        self::assertSame(['users.view'], $role->permissions()->pluck('name')->all());
        $this->artisan('cms:permissions-sync', ['--grant-commerce-defaults' => true])->assertSuccessful();
        $this->artisan('cms:permissions-sync', ['--grant-commerce-defaults' => true])->assertSuccessful();
        self::assertSame(9, $role->permissions()->count());
        self::assertTrue($role->permissions()->where('name', 'users.view')->exists());
        self::assertFalse($role->permissions()->where('name', 'orders.view')->exists());
        self::assertSame(0, $custom->permissions()->count());
    }

    public function test_commerce_capabilities_are_visible_and_editable_in_role_management(): void
    {
        $this->actingAs($this->staff(super: true))->get('/admin/roles/create')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('permissions', count(app(PermissionRegistry::class)->all()) - 2)
                ->where('navigation', function ($items): bool {
                    $groups = ['core.order-list' => 'core.orders', 'core.order-statuses' => 'core.orders', 'core.order-emails' => 'core.orders', 'core.customer-list' => 'core.customers', 'core.customer-groups' => 'core.customers', 'core.customer-blacklist' => 'core.customers', 'core.users' => 'core.access', 'core.roles' => 'core.access', 'core.availability-statuses' => 'core.catalog-products'];
                    foreach ($groups as $id => $group) {
                        if ((collect($items)->firstWhere('id', $id)['group']['id'] ?? null) !== $group) {
                            return false;
                        }
                    }

                    return true;
                })
                ->where('permissions', fn ($items) => collect($items)->firstWhere('name', 'products.view')['label'] === 'View products'));
        $this->post('/admin/roles', ['name' => 'Product reader', 'slug' => 'product-reader', 'permissions' => ['admin.access', 'products.view']])->assertSessionHasNoErrors();
        $role = RoleRecord::where('slug', 'product-reader')->sole();
        self::assertEqualsCanonicalizing(['admin.access', 'products.view'], $role->permissions()->pluck('name')->all());
        $this->put('/admin/roles/'.$role->id, ['name' => 'Product reader', 'slug' => 'product-reader', 'permissions' => ['admin.access', 'categories.view', 'orders.update']])->assertSessionHasNoErrors();
        self::assertEqualsCanonicalizing(['admin.access', 'categories.view', 'orders.update'], $role->permissions()->pluck('name')->all());
    }

    public function test_guests_and_users_without_capabilities_cannot_access_staff_pages_or_mutations(): void
    {
        $this->get('/admin/users')->assertRedirect('/admin/login');
        $user = $this->staff();
        $this->actingAs($user);
        foreach (['/admin/users', '/admin/users/create', '/admin/roles', '/admin/roles/create', '/admin/users/'.$user->id.'/edit'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post('/admin/users', [])->assertForbidden();
        $this->put('/admin/users/'.$user->id, [])->assertForbidden();
        $this->delete('/admin/users/'.$user->id)->assertForbidden();
        $this->post('/admin/roles', [])->assertForbidden();
        $this->put('/admin/roles/1', [])->assertForbidden();
        $this->delete('/admin/roles/1')->assertForbidden();
    }

    public function test_multiple_roles_combine_capabilities_and_revocation_is_immediate(): void
    {
        $user = $this->staff(['admin.access', 'users.view']);
        $role = RoleRecord::create(['name' => 'Role viewer', 'slug' => 'role-viewer']);
        $role->permissions()->attach(PermissionRecord::where('name', 'roles.view')->value('id'));
        $user->roles()->attach($role->id);
        $this->actingAs($user)->get('/admin/users')->assertOk()->assertInertia(fn (Assert $p) => $p->has('navigation', 3)->where('capabilities', fn ($v) => $v['users.create'] === false));
        $this->get('/admin/roles')->assertOk();
        $user->roles()->detach($role->id);
        $this->get('/admin/roles')->assertForbidden();
        self::assertFalse(Gate::forUser($user)->allows('users.create'));
    }

    public function test_super_admin_bypasses_only_registered_capabilities_not_unrelated_policies(): void
    {
        $user = $this->staff(super: true);
        self::assertFalse(Gate::forUser($user)->allows('users.delete'));
        self::assertTrue(Gate::forUser($user)->allows('users.view'));
        Gate::define('some-business-invariant', fn () => false);
        self::assertFalse(Gate::forUser($user)->allows('some-business-invariant'));
        $this->actingAs($user)->get('/admin/users')->assertOk();
        $this->get('/admin/roles')->assertOk();
    }

    public function test_registration_never_accepts_roles_super_status_or_active_flags(): void
    {
        $this->post('/admin/register', ['name' => 'Registrant', 'email' => 'registrant@example.test', 'password' => 'a long test passphrase', 'password_confirmation' => 'a long test passphrase', 'role_ids' => [1], 'is_super_admin' => true, 'is_active' => false])->assertForbidden();
        self::assertSame(0, DB::table('role_user')->count());
        $this->assertDatabaseCount('users', 0);
    }

    public function test_staff_crud_normalizes_email_hashes_password_and_sends_verification(): void
    {
        $admin = $this->staff(super: true);
        $role = RoleRecord::where('slug', 'catalog-manager')->sole();
        $this->actingAs($admin)->get('/admin/users/create')->assertOk();
        $this->post('/admin/users', ['name' => 'New Staff', 'email' => ' NEW@EXAMPLE.TEST ', 'password' => 'a long test passphrase', 'password_confirmation' => 'a long test passphrase', 'is_active' => true, 'role_ids' => [(string) $role->id], 'email_verified_at' => now(), 'is_super_admin' => true])->assertSessionHasNoErrors()->assertRedirect('/admin/users');
        $user = UserRecord::where('email', 'new@example.test')->sole();
        self::assertTrue(Hash::check('a long test passphrase', $user->password));
        self::assertFalse($user->hasVerifiedEmail());
        self::assertSame([$role->id], $user->roles->pluck('id')->all());
        Notification::assertSentTo($user, VerifyEmail::class);
        $this->get('/admin/users/'.$user->id.'/edit')->assertOk()->assertInertia(fn (Assert $p) => $p->where('staff.name', 'New Staff')->missing('staff.password'));
        $this->put('/admin/users/'.$user->id, ['name' => 'Renamed', 'email' => 'new@example.test', 'password' => '', 'is_active' => true, 'role_ids' => []])->assertSessionHasNoErrors();
        self::assertSame('Renamed', $user->fresh()->name);
        self::assertSame(0, $user->roles()->count());
        $this->delete('/admin/users/'.$user->id)->assertSessionHasNoErrors();
        self::assertFalse($user->fresh()->is_active);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_write_only_capabilities_redirect_to_an_accessible_landing_page(): void
    {
        $actor = $this->staff(['admin.access', 'users.create', 'users.set-password', 'roles.create', 'roles.assign-permissions']);
        $this->actingAs($actor)->post('/admin/users', ['name' => 'New staff', 'email' => 'write-only@example.test', 'password' => 'a long test passphrase', 'password_confirmation' => 'a long test passphrase', 'is_active' => true])->assertSessionHasNoErrors()->assertRedirect('/admin');
        $this->post('/admin/roles', ['name' => 'Landing', 'slug' => 'landing', 'permissions' => ['admin.access']])->assertSessionHasNoErrors()->assertRedirect('/admin');
        $this->get('/admin/users')->assertForbidden();
        $this->get('/admin/roles')->assertForbidden();
    }

    public function test_staff_list_search_status_role_filters_are_bounded_and_do_not_leak_credentials(): void
    {
        $admin = $this->staff(super: true);
        $target = $this->staff();
        $target->is_active = false;
        $target->save();
        $this->actingAs($admin)->get('/admin/users?search='.$target->email.'&status=inactive&role='.$target->roles->first()->id)->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('users.data', 1)->where('users.data.0.id', (string) $target->id)->missing('users.data.0.password')->missing('users.data.0.remember_token'));
        $this->get('/admin/users?status=arbitrary')->assertSessionHasErrors('status');
    }

    public function test_lower_privileged_staff_cannot_grant_super_or_stronger_roles_or_reset_stronger_accounts(): void
    {
        $actor = $this->staff(['admin.access', 'users.create', 'users.update', 'users.set-password', 'users.assign-roles']);
        $super = $this->staff(super: true);
        $data = ['name' => 'Escalation', 'email' => 'escalation@example.test', 'password' => 'a long test passphrase', 'password_confirmation' => 'a long test passphrase', 'is_active' => true, 'role_ids' => [(string) RoleRecord::where('is_super_admin', true)->value('id')]];
        $this->actingAs($actor)->post('/admin/users', $data)->assertSessionHasErrors('access');
        $stronger = $this->staff(['admin.access', 'roles.create']);
        $data['role_ids'] = [(string) $stronger->roles->first()->id];
        $this->post('/admin/users', $data)->assertSessionHasErrors('access');
        unset($data['role_ids']);
        $data['email'] = $stronger->email;
        $this->put('/admin/users/'.$stronger->id, $data)->assertSessionHasErrors('access');
        $data['email'] = $super->email;
        $this->put('/admin/users/'.$super->id, $data)->assertSessionHasErrors('access');
        $this->assertDatabaseMissing('users', ['email' => 'escalation@example.test']);
    }

    public function test_user_update_cannot_bypass_deactivation_or_role_assignment_permission(): void
    {
        $actor = $this->staff(['admin.access', 'users.update']);
        $target = $this->staff();
        $data = ['name' => $target->name, 'email' => $target->email, 'password' => '', 'is_active' => false];
        $this->actingAs($actor)->put('/admin/users/'.$target->id, $data)->assertForbidden();
        $this->put('/admin/users/'.$target->id, [...$data, 'is_active' => true, 'role_ids' => []])->assertForbidden();
        self::assertTrue($target->fresh()->is_active);
    }

    public function test_self_deactivation_and_last_super_demotion_are_rejected(): void
    {
        $admin = $this->staff(super: true);
        $this->actingAs($admin)->delete('/admin/users/'.$admin->id)->assertSessionHasErrors('access');
        $this->put('/admin/users/'.$admin->id, ['name' => $admin->name, 'email' => $admin->email, 'password' => '', 'is_active' => true, 'role_ids' => []])->assertSessionHasErrors('access');
        self::assertTrue($admin->fresh()->is_active);
        self::assertTrue($admin->roles()->where('is_super_admin', true)->exists());
        $this->put('/admin/users/'.$admin->id, ['name' => $admin->name, 'email' => 'changed@example.test', 'password' => '', 'is_active' => true])->assertSessionHasErrors('access');
    }

    public function test_disabled_users_cannot_login_or_reuse_existing_sessions(): void
    {
        $user = $this->staff();
        $this->actingAs($user)->get('/admin')->assertOk();
        $user->is_active = false;
        $user->save();
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->assertGuest();
        $this->post('/admin/login', ['email' => $user->email, 'password' => 'a long test passphrase'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_role_crud_and_assigned_system_roles_cannot_be_deleted(): void
    {
        $admin = $this->staff(super: true);
        $this->actingAs($admin)->get('/admin/roles/create')->assertOk();
        $this->post('/admin/roles', ['name' => 'Auditor', 'slug' => 'auditor', 'description' => 'Read staff', 'permissions' => ['admin.access', 'users.view'], 'is_super_admin' => true])->assertSessionHasNoErrors()->assertRedirect('/admin/roles');
        $role = RoleRecord::where('slug', 'auditor')->sole();
        self::assertFalse($role->is_super_admin);
        self::assertSame(2, $role->permissions()->count());
        $this->get('/admin/roles/'.$role->id.'/edit')->assertOk();
        $this->put('/admin/roles/'.$role->id, ['name' => 'Reader', 'slug' => 'auditor', 'description' => '', 'permissions' => ['admin.access']])->assertSessionHasNoErrors();
        $admin->roles()->attach($role->id);
        $this->delete('/admin/roles/'.$role->id)->assertSessionHasErrors('access');
        $admin->roles()->detach($role->id);
        $this->delete('/admin/roles/'.$role->id)->assertSessionHasNoErrors();
        $system = RoleRecord::where('is_super_admin', true)->sole();
        $this->delete('/admin/roles/'.$system->id)->assertSessionHasErrors('access');
        $this->put('/admin/roles/'.$system->id, ['name' => 'Changed', 'slug' => 'changed', 'permissions' => []])->assertSessionHasErrors('access');
    }

    public function test_role_permission_edits_cannot_escalate_actor_or_grant_unknown_capabilities(): void
    {
        $actor = $this->staff(['admin.access', 'roles.create', 'roles.update', 'roles.assign-permissions']);
        $this->actingAs($actor)->post('/admin/roles', ['name' => 'Escalation', 'slug' => 'escalation', 'permissions' => ['users.create']])->assertSessionHasErrors('access');
        $this->post('/admin/roles', ['name' => 'Unknown', 'slug' => 'unknown', 'permissions' => ['products.publish']])->assertSessionHasErrors('permissions.0');
    }

    public function test_direct_application_calls_recheck_actor_authority(): void
    {
        $actor = $this->staff();
        $this->expectException(AuthorizationException::class);
        app(ManageStaffHandler::class)->saveUser((string) $actor->id, null, new SaveStaffData('No', 'no@example.test', 'a long test passphrase', true, []));
    }

    public function test_operator_bootstrap_requires_existing_verified_identity_and_has_no_default_credentials(): void
    {
        $user = $this->staff();
        $user->roles()->detach();
        $this->artisan('cms:admin-grant', ['email' => $user->email])->assertSuccessful();
        self::assertTrue($user->roles()->where('is_super_admin', true)->exists());
        $user->email_verified_at = null;
        $user->save();
        $this->artisan('cms:admin-grant', ['email' => $user->email])->assertFailed();
        $this->artisan('cms:admin-grant', ['email' => 'absent@example.test'])->assertFailed();
    }

    public function test_modules_can_contribute_namespaced_capabilities_without_core_schema_changes(): void
    {
        app(PermissionRegistry::class)->add('test.example.review', 'test-example::permissions.review');
        (new AuthorizationServiceProvider($this->app))->boot(app(AccessChecker::class));
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->assertDatabaseHas('permissions', ['name' => 'test.example.review']);
        self::assertSame(0, DB::table('permission_role')->where('permission_id', PermissionRecord::where('name', 'test.example.review')->value('id'))->count());
        $ordinary = $this->staff();
        self::assertFalse(Gate::forUser($ordinary)->allows('test.example.review'));
        self::assertTrue(Gate::forUser($this->staff(super: true))->allows('test.example.review'));
        $ordinary->roles->first()->permissions()->attach(PermissionRecord::where('name', 'test.example.review')->value('id'));
        self::assertTrue(Gate::forUser($ordinary)->allows('test.example.review'));
    }

    public function test_staff_mutations_keep_real_csrf_protection(): void
    {
        $this->actingAs($this->staff(super: true));
        $this->app->instance('env', 'production');
        foreach (['/admin/users', '/admin/roles'] as $url) {
            $this->post($url, [])->assertStatus(419);
        }
        $this->patch('/admin/locale', ['locale' => 'el', 'return_to' => '/admin'])->assertStatus(419);
    }
}
