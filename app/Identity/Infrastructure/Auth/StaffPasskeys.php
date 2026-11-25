<?php

namespace App\Identity\Infrastructure\Auth;

use App\Audit\Domain\Model\AuditEvent;
use App\Audit\Infrastructure\AuditLogger;
use App\Audit\Infrastructure\AuditWriteFailure;
use App\Identity\Infrastructure\Persistence\UserRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Component\Serializer\SerializerInterface;
use Throwable;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

/** WebAuthn validation stays in the maintained library; ceremonies belong only to the staff area. */
final class StaffPasskeys
{
    private SerializerInterface $serializer;

    private CeremonyStepManagerFactory $ceremonies;

    public function __construct()
    {
        $this->serializer = (new WebauthnSerializerFactory(new AttestationStatementSupportManager([new NoneAttestationStatementSupport])))->create();
        $this->ceremonies = new CeremonyStepManagerFactory;
        $this->ceremonies->setAllowedOrigins([rtrim((string) config('app.url'), '/')]);
    }

    /** @return array<string, mixed> */
    public function creationOptions(Request $request, UserRecord $user, string $purpose): array
    {
        $exclude = DB::table('staff_passkeys')->where('user_id', $user->id)->pluck('credential_id')
            ->map(fn (string $id) => PublicKeyCredentialDescriptor::create('public-key', Base64UrlSafe::decodeNoPadding($id)))->all();
        $options = PublicKeyCredentialCreationOptions::create(
            PublicKeyCredentialRpEntity::create((string) config('app.name'), $this->host()),
            PublicKeyCredentialUserEntity::create($user->email, $this->handle($user), $user->name), random_bytes(32),
            [PublicKeyCredentialParameters::create('public-key', -7), PublicKeyCredentialParameters::create('public-key', -257)],
            AuthenticatorSelectionCriteria::create(userVerification: 'required', residentKey: 'required'),
            attestation: 'none', excludeCredentials: $exclude, timeout: 60000,
        );

        return $this->remember($request, $options, $purpose, $user);
    }

    /** @return array<string, mixed> */
    public function loginOptions(Request $request): array
    {
        // Discoverable credentials avoid exposing whether an email has a passkey.
        return $this->remember($request, PublicKeyCredentialRequestOptions::create(random_bytes(32), $this->host(), userVerification: 'required', timeout: 60000), 'login');
    }

    public function enroll(Request $request, UserRecord $user, string $purpose): void
    {
        $state = $this->consume($request, $purpose, $user);
        try {
            $credential = $this->credential($request);
            if (! $credential->response instanceof AuthenticatorAttestationResponse) {
                throw new \UnexpectedValueException;
            }
            $options = $this->serializer->deserialize($state['options'], PublicKeyCredentialCreationOptions::class, 'json');
            $record = AuthenticatorAttestationResponseValidator::create($this->ceremonies->creationCeremony())
                ->check($credential->response, $options, $this->host());
            DB::table('staff_passkeys')->insert(['user_id' => $user->id,
                'credential_id' => Base64UrlSafe::encodeUnpadded($record->publicKeyCredentialId),
                'credential' => $this->serializer->serialize($record, 'json'), 'created_at' => now()]);
            app(AuditLogger::class)->record(new AuditEvent('auth.passkey_registered', 'user', (string) $user->id));
        } catch (AuditWriteFailure $failure) {
            throw $failure;
        } catch (Throwable) {
            $this->fail();
        }
    }

    public function login(Request $request): UserRecord
    {
        $state = $this->consume($request, 'login');
        try {
            $credential = $this->credential($request);
            if (! $credential->response instanceof AuthenticatorAssertionResponse) {
                throw new \UnexpectedValueException;
            }

            return DB::transaction(function () use ($credential, $state): UserRecord {
                DB::select('SELECT pg_advisory_xact_lock(2142512)');
                $row = DB::table('staff_passkeys')->where('credential_id', Base64UrlSafe::encodeUnpadded($credential->rawId))->lockForUpdate()->first();
                if ($row === null) {
                    throw new \UnexpectedValueException;
                }
                $user = UserRecord::findOrFail($row->user_id);
                if (Gate::forUser($user)->denies('access-admin')) {
                    throw new \UnexpectedValueException;
                }
                $record = $this->serializer->deserialize($row->credential, CredentialRecord::class, 'json');
                if (! hash_equals($this->handle($user), $record->userHandle)) {
                    throw new \UnexpectedValueException;
                }
                $options = $this->serializer->deserialize($state['options'], PublicKeyCredentialRequestOptions::class, 'json');
                $record = AuthenticatorAssertionResponseValidator::create($this->ceremonies->requestCeremony())
                    ->check($record, $credential->response, $options, $this->host(), null);
                DB::table('staff_passkeys')->where('id', $row->id)->update(['credential' => $this->serializer->serialize($record, 'json')]);

                return $user;
            });
        } catch (Throwable) {
            $this->fail();
        }
    }

    private function credential(Request $request): PublicKeyCredential
    {
        $data = $request->validate(['credential' => ['required', 'array']]);
        $json = json_encode($data['credential'], JSON_THROW_ON_ERROR);
        if (strlen($json) > 65536) {
            $this->fail();
        }

        return $this->serializer->deserialize($json, PublicKeyCredential::class, 'json');
    }

    /** @return array<string, mixed> */
    private function remember(Request $request, PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions $options, string $purpose, ?UserRecord $user = null): array
    {
        $json = $this->serializer->serialize($options, 'json');
        $request->session()->put('staff_passkey', ['options' => $json, 'purpose' => $purpose, 'expires' => now()->addMinutes(5)->timestamp,
            'user' => $user?->id, 'password' => $user ? hash('sha256', $user->getAuthPassword()) : null]);

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function consume(Request $request, string $purpose, ?UserRecord $user = null): array
    {
        $state = $request->session()->pull('staff_passkey');
        if (! is_array($state) || $state['purpose'] !== $purpose || $state['expires'] <= now()->timestamp
            || $state['user'] !== $user?->id || ($user && ! hash_equals($state['password'], hash('sha256', $user->getAuthPassword())))
            || ! Cache::add('staff-passkey-used:'.hash('sha256', $state['options']), true, 600)) {
            $this->fail();
        }

        return $state;
    }

    private function handle(UserRecord $user): string
    {
        return hash_hmac('sha256', 'staff-passkey|'.$user->id, (string) config('app.key'), true);
    }

    private function host(): string
    {
        return (string) parse_url(config('app.url'), PHP_URL_HOST);
    }

    private function fail(): never
    {
        throw ValidationException::withMessages(['passkey' => __('auth.passkey_failed')]);
    }
}
