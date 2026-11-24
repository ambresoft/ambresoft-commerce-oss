<?php

namespace Tests\Feature;

use App\Authorization\Infrastructure\Persistence\RoleRecord;
use App\Identity\Infrastructure\Auth\StaffInvitation;
use App\Identity\Infrastructure\Auth\StaffInvitations;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Media\Infrastructure\Persistence\MediaFileRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class StaffInvitationTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
    }

    public function test_first_super_admin_requires_operator_setup_and_a_single_use_email_invitation(): void
    {
        $this->get('/admin/register')->assertForbidden();
        $this->artisan('cms:admin-setup first@example.test')->assertSuccessful();
        $user = UserRecord::sole();
        self::assertTrue($user->roles()->where('is_super_admin', true)->exists());
        self::assertNull($user->email_verified_at);
        $url = '';
        Notification::assertSentTo($user, StaffInvitation::class, function ($notification) use (&$url): bool {
            $url = $notification->url;

            return true;
        });
        $this->artisan('cms:admin-setup first@example.test')->assertSuccessful();
        $this->get($url)->assertForbidden();
        $url = app(StaffInvitations::class)->url($user->fresh());
        $this->artisan('cms:admin-setup attacker@example.test')->assertFailed();
        $this->get($url)->assertOk()->assertInertia(fn (Assert $p) => $p->component('Auth/Register')->where('invitation.email', 'first@example.test'));
        $oldSession = session()->getId();
        $this->post($url, ['password' => 'a long test passphrase', 'password_confirmation' => 'a long test passphrase',
            'email' => 'attacker@example.test', 'name' => 'Attacker', 'role_ids' => [], 'is_active' => false])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($user);
        self::assertNotSame($oldSession, session()->getId());
        self::assertSame('first@example.test', $user->fresh()->email);
        self::assertTrue(Hash::check('a long test passphrase', $user->fresh()->password));
        self::assertTrue($user->fresh()->hasVerifiedEmail());
        Auth::logout();
        $this->flushSession();
        $this->get($url)->assertForbidden();
        $this->post($url, ['password' => 'another long passphrase', 'password_confirmation' => 'another long passphrase'])->assertForbidden();
    }

    public function test_invitation_expiry_tampering_password_changes_disabled_users_and_removed_roles_are_rejected(): void
    {
        $user = $this->staff();
        $user->forceFill(['email_verified_at' => null])->save();
        $url = app(StaffInvitations::class)->url($user);
        $this->get($url.'&extra=1')->assertForbidden();
        $this->travel(8)->days();
        $this->get($url)->assertForbidden();
        $this->travelBack();
        $user->forceFill(['is_active' => false])->save();
        $this->get($url)->assertForbidden();
        $user->forceFill(['is_active' => true, 'password' => 'a new long passphrase'])->save();
        $this->get($url)->assertForbidden();
        $url = app(StaffInvitations::class)->url($user);
        $user->roles()->detach();
        $this->get($url)->assertForbidden();
        $this->assertGuest();
    }

    public function test_super_admin_can_invite_with_only_server_selected_roles_and_no_shared_password(): void
    {
        $super = $this->staff(super: true);
        $role = RoleRecord::where('slug', 'catalog-manager')->sole();
        $this->actingAs($super)->post('/admin/users', ['name' => 'Invited staff', 'email' => 'invitee@example.test',
            'invite' => true, 'is_active' => true, 'role_ids' => [$role->id]])->assertSessionHasNoErrors()->assertRedirect('/admin/users');
        $user = UserRecord::where('email', 'invitee@example.test')->sole();
        Notification::assertSentTo($user, StaffInvitation::class);
        self::assertNull($user->email_verified_at);
        self::assertSame([$role->id], $user->roles()->pluck('roles.id')->all());
        $this->post('/admin/users/'.$user->id.'/invitation')->assertSessionHasNoErrors();
        $this->get('/admin/users/'.$user->id.'/edit')->assertOk();
        Auth::logout();
        $url = app(StaffInvitations::class)->url($user);
        $this->post($url, ['password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->assertDatabaseCount('users', 2);
        $this->assertGuest();
    }

    public function test_customers_and_unprivileged_staff_cannot_invite_or_register_admin_accounts(): void
    {
        $target = $this->staff(super: true);
        $user = UserRecord::create(['name' => 'Customer', 'email' => 'customer@example.test', 'password' => 'customer password']);
        $user->markEmailAsVerified();
        $this->actingAs($user)->post('/admin/users/'.$target->id.'/invitation')->assertForbidden();
        $this->post('/admin/users', ['invite' => true])->assertForbidden();
        $this->postJson('/admin/passkeys/options', ['password' => 'customer password'])->assertForbidden();
        self::assertSame(2, DB::table('users')->count());
        Notification::assertNothingSent();
    }

    public function test_invitation_email_uses_the_store_palette_and_embeds_the_private_store_logo(): void
    {
        Storage::fake('local');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aN1sAAAAASUVORK5CYII=');
        Storage::disk('local')->put('images/store-logo.png', $png);
        $logo = MediaFileRecord::create(['disk' => 'local', 'storage_key' => 'images/store-logo.png', 'mime_type' => 'image/png',
            'byte_size' => strlen($png), 'width' => 1, 'height' => 1]);
        DB::table('company_settings')->where('id', 1)->update(['name' => 'North & South', 'logo_media_id' => $logo->id]);
        DB::table('storefront_theme_settings')->where('id', 1)->update(['primary_color' => '#123456', 'on_primary_color' => '#fafafa',
            'text_color' => '#222222', 'background_color' => '#f4f4f4']);
        $user = $this->staff();
        $url = app(StaffInvitations::class)->url($user);
        $sent = app(MailChannel::class)->send($user, new StaffInvitation($url));
        $email = $sent->getSymfonySentMessage()->getOriginalMessage();
        $html = $email->getHtmlBody();
        self::assertStringContainsString('alt="North &amp; South"', $html);
        foreach (['#123456', '#fafafa', '#222222', '#f4f4f4', 'src="cid:'] as $expected) {
            self::assertStringContainsString($expected, $html);
        }
        self::assertStringContainsString(e($url), $html);
        self::assertStringContainsString($url, $email->getTextBody());
        self::assertCount(1, $email->getAttachments());
        self::assertSame($png, $email->getAttachments()[0]->getBody());
        self::assertStringNotContainsString('/admin/settings/company/logo', $html);

        Storage::disk('local')->delete('images/store-logo.png');
        $sent = app(MailChannel::class)->send($user, new StaffInvitation($url));
        $email = $sent->getSymfonySentMessage()->getOriginalMessage();
        self::assertStringContainsString('North &amp; South', $email->getHtmlBody());
        self::assertStringNotContainsString('src="cid:', $email->getHtmlBody());
        self::assertCount(0, $email->getAttachments());
    }
}
