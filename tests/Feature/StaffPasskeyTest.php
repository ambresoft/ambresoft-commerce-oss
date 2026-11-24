<?php

namespace Tests\Feature;

use App\Identity\Infrastructure\Auth\StaffInvitations;
use App\Identity\Infrastructure\Persistence\UserRecord;
use CBOR\ByteStringObject;
use CBOR\Encoder;
use CBOR\MapObject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use ParagonIE\ConstantTime\Base64UrlSafe as B64;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class StaffPasskeyTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private const ORIGIN = 'https://cms.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['app.url' => self::ORIGIN]);
        Notification::fake();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
    }

    /** A software authenticator signs real ES256 assertions; no validation is mocked. */
    private function authenticator(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $details = openssl_pkey_get_details($key);
        $cose = (new Encoder)->encode([1 => 2, 3 => -7, -1 => 1,
            -2 => ByteStringObject::create($details['ec']['x']), -3 => ByteStringObject::create($details['ec']['y'])]);

        return ['key' => $key, 'id' => random_bytes(32), 'cose' => $cose];
    }

    private function attestation(array $device, array $options, string $origin = self::ORIGIN, int $flags = 0x45): array
    {
        $client = json_encode(['type' => 'webauthn.create', 'challenge' => $options['challenge'], 'origin' => $origin, 'crossOrigin' => false]);
        $auth = hash('sha256', 'cms.test', true).chr($flags).pack('N', 0).str_repeat("\0", 16).pack('n', strlen($device['id'])).$device['id'].$device['cose'];
        $object = (new Encoder)->encode(['fmt' => 'none', 'attStmt' => MapObject::create(), 'authData' => ByteStringObject::create($auth)]);

        return ['id' => B64::encodeUnpadded($device['id']), 'rawId' => B64::encodeUnpadded($device['id']), 'type' => 'public-key',
            'response' => ['clientDataJSON' => B64::encodeUnpadded($client), 'attestationObject' => B64::encodeUnpadded($object), 'transports' => ['internal']]];
    }

    private function assertion(array $device, array $options, string $handle, string $origin = self::ORIGIN, int $flags = 0x05, int $counter = 1): array
    {
        $client = json_encode(['type' => 'webauthn.get', 'challenge' => $options['challenge'], 'origin' => $origin, 'crossOrigin' => false]);
        $auth = hash('sha256', 'cms.test', true).chr($flags).pack('N', $counter);
        openssl_sign($auth.hash('sha256', $client, true), $signature, $device['key'], OPENSSL_ALGO_SHA256);

        return ['id' => B64::encodeUnpadded($device['id']), 'rawId' => B64::encodeUnpadded($device['id']), 'type' => 'public-key', 'response' => [
            'clientDataJSON' => B64::encodeUnpadded($client), 'authenticatorData' => B64::encodeUnpadded($auth),
            'signature' => B64::encodeUnpadded($signature), 'userHandle' => $handle,
        ]];
    }

    private function enroll(UserRecord $user): array
    {
        $device = $this->authenticator();
        $options = $this->actingAs($user)->postJson('/admin/passkeys/options', ['password' => 'a long test passphrase'])->assertOk()->json();
        $this->postJson('/admin/passkeys', ['credential' => $this->attestation($device, $options)])->assertOk();
        Auth::logout();
        $this->flushSession();

        return [$device, $options['user']['id']];
    }

    public function test_invitation_allows_passkey_registration_then_real_passwordless_staff_login(): void
    {
        $user = $this->staff(super: true);
        $user->forceFill(['email_verified_at' => null])->save();
        $url = str_replace(self::ORIGIN, '', app(StaffInvitations::class)->url($user));
        $device = $this->authenticator();
        $options = $this->postJson($url, ['operation' => 'passkey_options'])->assertOk()->json();
        self::assertSame('required', $options['authenticatorSelection']['userVerification']);
        $this->postJson($url, ['operation' => 'passkey_finish', 'credential' => $this->attestation($device, $options)])
            ->assertOk()->assertJsonPath('redirect', '/admin');
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('staff_passkeys', 1);
        Auth::logout();
        $this->flushSession();
        $this->get($url)->assertForbidden();
        $optionsLogin = $this->postJson('/admin/passkeys/login/options')->assertOk()->json();
        $state = session('staff_passkey');
        $credential = $this->assertion($device, $optionsLogin, $options['user']['id']);
        $oldSession = session()->getId();
        $this->postJson('/admin/passkeys/login', ['credential' => $credential])->assertOk()->assertJsonPath('redirect', '/admin');
        $this->assertAuthenticatedAs($user);
        self::assertNotSame($oldSession, session()->getId());
        $this->get('/admin')->assertOk();
        Auth::logout();
        // Even a stale concurrent copy of the session cannot consume the challenge again.
        $this->withSession(['staff_passkey' => $state])->postJson('/admin/passkeys/login', [
            'credential' => $this->assertion($device, $optionsLogin, $options['user']['id'], counter: 2),
        ])->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_login_rejects_wrong_origin_user_handle_challenge_signature_and_missing_verification(): void
    {
        [$device, $handle] = $this->enroll($this->staff(super: true));
        foreach (['origin', 'handle', 'challenge', 'signature', 'verification'] as $bad) {
            $options = $this->postJson('/admin/passkeys/login/options')->assertOk()->json();
            $credential = $this->assertion($device, $options, $bad === 'handle' ? B64::encodeUnpadded(random_bytes(32)) : $handle,
                $bad === 'origin' ? 'https://evil.cms.test' : self::ORIGIN, $bad === 'verification' ? 0x01 : 0x05);
            if ($bad === 'challenge') {
                $otherOptions = $this->postJson('/admin/passkeys/login/options')->assertOk()->json();
            }
            if ($bad === 'signature') {
                $credential['response']['signature'] = B64::encodeUnpadded(random_bytes(70));
            }
            $this->postJson('/admin/passkeys/login', ['credential' => $credential])->assertUnprocessable()->assertJsonValidationErrors('passkey');
            $this->assertGuest();
        }
    }

    public function test_disabled_or_demoted_staff_cannot_login_with_a_valid_passkey(): void
    {
        $user = $this->staff(super: true);
        [$device, $handle] = $this->enroll($user);
        foreach (['disabled', 'demoted'] as $reason) {
            $options = $this->postJson('/admin/passkeys/login/options')->json();
            $user->forceFill(['is_active' => $reason !== 'disabled'])->save();
            if ($reason === 'demoted') {
                $user->roles()->detach();
            }
            $this->postJson('/admin/passkeys/login', ['credential' => $this->assertion($device, $options, $handle)])->assertUnprocessable();
            $this->assertGuest();
        }
    }

    public function test_enrollment_requires_own_authenticated_staff_session_password_and_fresh_challenge(): void
    {
        $this->postJson('/admin/passkeys/options', ['password' => 'a long test passphrase'])->assertUnauthorized();
        $user = $this->staff(super: true);
        $this->actingAs($user)->postJson('/admin/passkeys/options', ['password' => 'wrong'])->assertUnprocessable();
        $options = $this->postJson('/admin/passkeys/options', ['password' => 'a long test passphrase'])->assertOk()->json();
        $device = $this->authenticator();
        $this->travel(6)->minutes();
        $this->postJson('/admin/passkeys', ['credential' => $this->attestation($device, $options)])->assertUnprocessable();
        $this->assertDatabaseCount('staff_passkeys', 0);
    }

    public function test_enrollment_checks_origin_verification_and_duplicate_credentials(): void
    {
        $user = $this->staff(super: true);
        $device = $this->authenticator();
        foreach (['origin', 'verification', 'valid', 'duplicate'] as $case) {
            $options = $this->actingAs($user)->postJson('/admin/passkeys/options', ['password' => 'a long test passphrase'])->assertOk()->json();
            $response = $this->postJson('/admin/passkeys', ['credential' => $this->attestation($device, $options,
                $case === 'origin' ? 'https://attacker.test' : self::ORIGIN, $case === 'verification' ? 0x41 : 0x45)]);
            $case === 'valid' ? $response->assertOk() : $response->assertUnprocessable();
        }
        $this->assertDatabaseCount('staff_passkeys', 1);
        self::assertNotNull(DB::table('staff_passkeys')->first());
    }

    public function test_only_the_owner_can_list_or_revoke_their_passkey_with_a_current_password(): void
    {
        $owner = $this->staff(super: true);
        $this->enroll($owner);
        $id = DB::table('staff_passkeys')->value('id');
        $other = $this->staff(super: true);
        $this->actingAs($other)->get('/admin/passkeys')->assertOk()->assertInertia(fn ($page) => $page->has('passkeys', 0));
        $this->postJson('/admin/passkeys/'.$id.'/delete', ['password' => 'a long test passphrase'])->assertOk();
        $this->assertDatabaseCount('staff_passkeys', 1);
        $this->flushSession();
        $this->actingAs($owner)->postJson('/admin/passkeys/'.$id.'/delete', ['password' => 'wrong'])->assertUnprocessable();
        $this->postJson('/admin/passkeys/'.$id.'/delete', ['password' => 'a long test passphrase'])->assertOk();
        $this->assertDatabaseCount('staff_passkeys', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.passkey_registered', 'entity_id' => (string) $owner->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.passkey_revoked', 'entity_id' => (string) $owner->id]);
    }

    public function test_passkey_endpoints_retain_csrf_protection(): void
    {
        config(['app.url' => 'http://localhost']);
        $this->app->instance('env', 'production');
        foreach (['/admin/passkeys/login/options', '/admin/passkeys/login'] as $url) {
            $this->post($url)->assertStatus(419);
        }
        $this->actingAs($this->staff(super: true));
        foreach (['/admin/passkeys/options', '/admin/passkeys', '/admin/passkeys/1/delete'] as $url) {
            $this->post($url)->assertStatus(419);
        }
    }
}
