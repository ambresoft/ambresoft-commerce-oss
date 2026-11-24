<?php

namespace Tests\Feature;

use App\Authorization\Application\Command\ManageStaffHandler;
use App\Authorization\Application\Command\SaveStaffData;
use App\Authorization\Infrastructure\AccessChecker;
use App\Authorization\Infrastructure\Persistence\PermissionRecord;
use App\Authorization\Infrastructure\Persistence\RoleRecord;
use App\Identity\Infrastructure\Auth\StaffInvitation;
use App\Identity\Infrastructure\Persistence\UserRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class StaffManagementSecurityTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
    }

    public function test_staff_management_authority_is_super_only_by_default(): void
    {
        $super = $this->staff(super: true);
        $ordinary = $this->staff();
        $ordinary->roles()->sync([RoleRecord::where('slug', 'store-admin')->sole()->id]);
        foreach (AccessChecker::SUPER_DELEGATED as $ability) {
            self::assertTrue(Gate::forUser($super)->allows($ability), $ability);
            self::assertFalse(Gate::forUser($ordinary)->allows($ability), $ability);
        }
        $this->actingAs($ordinary)->get('/admin/users')->assertOk();
        $this->get('/admin/users/'.$super->id.'/edit')->assertForbidden();
    }

    public function test_even_a_delegated_staff_manager_cannot_open_edit_or_disable_a_super_admin(): void
    {
        $actor = $this->staff(['admin.access', 'users.view', ...AccessChecker::SUPER_DELEGATED]);
        $super = $this->staff(super: true);
        $hash = $super->password;
        $this->actingAs($actor)->get('/admin/users?search='.$super->email)->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('users.data', 1)->where('users.data.0.editUrl', null)->where('users.data.0.canDeactivate', false));
        $this->get('/admin/users/'.$super->id.'/edit')->assertForbidden();
        $this->put('/admin/users/'.$super->id, ['name' => 'Compromised', 'email' => $super->email,
            'password' => 'another long passphrase', 'password_confirmation' => 'another long passphrase', 'is_active' => false])->assertSessionHasErrors('access');
        $this->delete('/admin/users/'.$super->id)->assertSessionHasErrors('access');
        self::assertTrue($super->fresh()->is_active);
        self::assertSame($hash, $super->fresh()->password);
        self::assertSame($super->name, $super->fresh()->name);
    }

    public function test_a_super_admin_can_edit_and_disable_another_super_admin(): void
    {
        $actor = $this->staff(super: true);
        $target = $this->staff(super: true);
        $this->actingAs($actor)->get('/admin/users?search='.$target->email)->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('users.data.0.editUrl', '/admin/users/'.$target->id.'/edit')->where('users.data.0.canDeactivate', true));
        $this->get('/admin/users/'.$target->id.'/edit')->assertOk();
        $this->put('/admin/users/'.$target->id, ['name' => 'Renamed super', 'email' => $target->email, 'is_active' => true])->assertSessionHasNoErrors();
        $this->delete('/admin/users/'.$target->id)->assertSessionHasNoErrors();
        self::assertFalse($target->fresh()->is_active);
        self::assertSame('Renamed super', $target->fresh()->name);
        self::assertTrue($actor->fresh()->is_active);
    }

    public function test_edit_permission_does_not_allow_setting_passwords_or_changing_recovery_email(): void
    {
        $actor = $this->staff(['admin.access', 'users.create', 'users.update']);
        $target = $this->staff();
        $data = ['name' => 'Renamed', 'email' => $target->email, 'is_active' => true];
        $this->actingAs($actor)->put('/admin/users/'.$target->id, $data)->assertSessionHasNoErrors();
        $password = ['password' => 'another long passphrase', 'password_confirmation' => 'another long passphrase'];
        $this->put('/admin/users/'.$target->id, [...$data, ...$password])->assertForbidden();
        $this->post('/admin/users', [...$data, ...$password, 'email' => 'new@example.test'])->assertForbidden();
        $this->put('/admin/users/'.$target->id, [...$data, 'email' => 'attacker@example.test'])->assertForbidden();
        self::assertSame($target->email, $target->fresh()->email);
        self::assertTrue(Hash::check('a long test passphrase', $target->fresh()->password));

        $this->expectException(AuthorizationException::class);
        app(ManageStaffHandler::class)->saveUser((string) $actor->id, (string) $target->id,
            new SaveStaffData('Renamed', $target->email, 'another long passphrase', true, null));
    }

    public function test_super_admin_can_delegate_password_and_edit_authority_through_a_role(): void
    {
        $super = $this->staff(super: true);
        $this->actingAs($super)->post('/admin/roles', ['name' => 'Password manager', 'slug' => 'password-manager',
            'permissions' => ['admin.access', 'users.update', 'users.set-password']])->assertSessionHasNoErrors();
        $role = RoleRecord::where('slug', 'password-manager')->sole();
        $actor = $this->staff();
        $this->put('/admin/users/'.$actor->id, ['name' => $actor->name, 'email' => $actor->email,
            'is_active' => true, 'role_ids' => [$role->id]])->assertSessionHasNoErrors();
        $this->flushSession();
        $target = $this->staff();
        $this->actingAs($actor)->put('/admin/users/'.$target->id, ['name' => $target->name, 'email' => $target->email,
            'is_active' => true, 'password' => 'a delegated new passphrase', 'password_confirmation' => 'a delegated new passphrase'])->assertSessionHasNoErrors();
        self::assertTrue(Hash::check('a delegated new passphrase', $target->fresh()->password));
    }

    public function test_delegated_user_creation_and_role_assignment_can_invite_without_password_authority(): void
    {
        $actor = $this->staff(['admin.access', 'users.create', 'users.assign-roles']);
        $ordinary = $this->staff();
        $this->actingAs($actor)->post('/admin/users', ['name' => 'Invited staff', 'email' => 'delegated-invite@example.test',
            'is_active' => true, 'invite' => true, 'role_ids' => [$ordinary->roles()->sole()->id]])->assertSessionHasNoErrors();
        $user = UserRecord::where('email', 'delegated-invite@example.test')->sole();
        Notification::assertSentTo($user, StaffInvitation::class);
        self::assertNull($user->email_verified_at);
        self::assertFalse(Gate::forUser($actor)->allows('users.set-password'));
    }

    public function test_delegated_managers_cannot_redelegate_staff_authority_via_roles_or_membership(): void
    {
        $actor = $this->staff(['admin.access', 'products.view', ...AccessChecker::SUPER_DELEGATED]);
        $target = $this->staff();
        $this->actingAs($actor)->get('/admin/roles/create')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('permissions', fn ($items) => collect($items)->whereIn('name', AccessChecker::SUPER_DELEGATED)->isEmpty()));
        foreach (AccessChecker::SUPER_DELEGATED as $ability) {
            $this->post('/admin/roles', ['name' => 'Unauthorized authority', 'slug' => 'forbidden-authority',
                'permissions' => ['admin.access', $ability]])->assertSessionHasErrors('access');
        }
        $powerRole = $actor->roles()->sole();
        $this->get('/admin/roles/'.$powerRole->id.'/edit')->assertForbidden();
        $this->put('/admin/roles/'.$powerRole->id, ['name' => 'Changed', 'slug' => $powerRole->slug,
            'permissions' => ['admin.access']])->assertSessionHasErrors('access');
        $this->put('/admin/users/'.$target->id, ['name' => $target->name, 'email' => $target->email,
            'is_active' => true, 'role_ids' => [$powerRole->id]])->assertSessionHasErrors('access');

        $this->post('/admin/roles', ['name' => 'Product reader', 'slug' => 'delegated-product-reader',
            'permissions' => ['admin.access', 'products.view']])->assertSessionHasNoErrors();
        $ordinaryRole = RoleRecord::where('slug', 'delegated-product-reader')->sole();
        $this->put('/admin/users/'.$target->id, ['name' => $target->name, 'email' => $target->email,
            'is_active' => true, 'role_ids' => [$ordinaryRole->id]])->assertSessionHasNoErrors();
        self::assertTrue(Gate::forUser($target)->allows('products.view'));
        self::assertFalse(Gate::forUser($target)->allows('users.set-password'));
    }

    public function test_upgrade_removes_legacy_non_super_grants_and_sync_does_not_restore_them(): void
    {
        $role = RoleRecord::where('slug', 'store-admin')->sole();
        $role->permissions()->syncWithoutDetaching(PermissionRecord::whereIn('name', AccessChecker::SUPER_DELEGATED)->pluck('id'));
        $super = $this->staff(super: true);
        $migration = require database_path('migrations/2026_10_22_000200_reserve_staff_management_grants_for_super_admins.php');
        $migration->up();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        self::assertFalse($role->permissions()->whereIn('name', AccessChecker::SUPER_DELEGATED)->exists());
        self::assertTrue($role->permissions()->where('name', 'products.view')->exists());
        self::assertTrue($super->roles()->where('is_super_admin', true)->exists());
        $role->permissions()->attach(PermissionRecord::where('name', 'users.update')->sole()->id);
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        self::assertTrue($role->permissions()->where('name', 'users.update')->exists());
        self::assertNotNull(DB::table('users')->where('id', $super->id)->first());
    }
}
