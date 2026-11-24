<?php

namespace App\Customer\Infrastructure;

use App\Audit\Infrastructure\AuditContext;
use App\Authorization\Infrastructure\AccessChecker;
use App\Identity\Application\Contract\RegistrationNotifierInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Identity\Infrastructure\Security\AccountSecurity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Customer sign-in accounts (A21, A99): a `users` identity linked one-to-one to a `customers` profile.
 *
 * Registration creates the identity and keeps the given names as a pending registration. The profile is attached only
 * once the e-mail is verified, which proves ownership: an existing profile with that e-mail (for example one created
 * by staff) is claimed with its addresses, groups and orders; otherwise a new profile joins the default group. Being a
 * customer never grants administration access.
 */
final class CustomerAccounts
{
    public function __construct(private AccessChecker $access) {}

    /** @return string the new identity ID; the verification e-mail is sent after commit. */
    public function register(string $firstName, string $lastName, string $email, #[SensitiveParameter] string $password): string
    {
        $user = DB::transaction(function () use ($firstName, $lastName, $email, $password) {
            // The identity's display name (users.name, 120 characters); the profile keeps the two names separately.
            $user = app(UserRepositoryInterface::class)->register(mb_substr(trim($firstName).' '.trim($lastName), 0, 120), $email, $password);
            DB::table('customer_registrations')->insert(['user_id' => $user->id->value, 'first_name' => $firstName, 'last_name' => $lastName, 'created_at' => now()]);

            return $user;
        });
        // The pending registration is committed, so the e-mail links to the customer pages.
        app(RegistrationNotifierInterface::class)->registered($user->id);

        return $user->id->value;
    }

    /**
     * A customer whose e-mail a sign-in provider has already verified: no password is known to anyone (the customer can
     * set one through a reset), no verification e-mail is sent and the profile attaches at the first sign-in.
     */
    public function registerVerified(string $firstName, string $lastName, string $email): UserRecord
    {
        $id = DB::transaction(function () use ($firstName, $lastName, $email): string {
            $user = app(UserRepositoryInterface::class)->register(mb_substr(trim($firstName).' '.trim($lastName), 0, 120), $email, Str::random(48));
            DB::table('customer_registrations')->insert(['user_id' => $user->id->value, 'first_name' => $firstName, 'last_name' => $lastName, 'created_at' => now()]);
            UserRecord::query()->whereKey($user->id->value)->update(['email_verified_at' => now()]);

            return $user->id->value;
        });

        $identity = UserRecord::query()->findOrFail($id);
        app(AccountSecurity::class)->record(request(), $identity, 'registration');

        return $identity;
    }

    /** An existing but unverified account proves nothing about its password's owner: a provider sign-in verifies it and retires that password. */
    public function claimUnverified(UserRecord $user): void
    {
        if ($user->hasVerifiedEmail()) {
            return;
        }
        $user->forceFill(['password' => Str::random(48), 'remember_token' => null])->save();
        $user->markEmailAsVerified();
    }

    /** Customer identities (linked or pending) without administration access receive customer e-mail links and pages. */
    public function usesCustomerArea(UserRecord $user): bool
    {
        return $this->isCustomer((string) $user->getKey()) && ! $this->access->allows($user, 'admin.access');
    }

    /** The message for a profile that cannot sign in: deleted by the customer, or disabled by staff. */
    public static function blockedMessage(object $profile): string
    {
        return __($profile->deleted_at === null ? 'auth.customer_disabled' : 'auth.customer_deleted');
    }

    public function isCustomer(string $userId): bool
    {
        return DB::table('customers')->where('user_id', $userId)->exists() || DB::table('customer_registrations')->where('user_id', $userId)->exists();
    }

    /** @return object{id: int, first_name: string, last_name: string, email: string, status: string, account_linked_at: string, deleted_at: ?string}|null */
    public function profile(UserRecord $user): ?object
    {
        return DB::table('customers')->where('user_id', $user->getKey())->first(['id', 'first_name', 'last_name', 'email', 'status', 'account_linked_at', 'deleted_at']);
    }

    /**
     * Attaches the profile of a verified identity: the linked one, an unlinked profile with the same e-mail (claimed),
     * or a new one from the pending registration. Returns null when there is nothing to attach yet (a staff identity
     * that never registered as a customer): the caller asks for the customer's name.
     */
    public function activate(UserRecord $user): ?string
    {
        if (! $user->hasVerifiedEmail()) {
            return null;
        }

        return $this->attach($user, null);
    }

    /** Creates (or claims) the profile of a verified identity that has no pending registration. */
    public function complete(UserRecord $user, string $firstName, string $lastName): string
    {
        $names = Validator::make(['first_name' => $firstName, 'last_name' => $lastName], self::nameRules(), [], ['first_name' => __('customers.first_name'), 'last_name' => __('customers.last_name')])->validate();
        if (! $user->hasVerifiedEmail()) {
            throw ValidationException::withMessages(['first_name' => __('auth.customer_unverified')]);
        }

        return $this->attach($user, $names) ?? throw ValidationException::withMessages(['first_name' => __('auth.customer_conflict')]);
    }

    /** @return array<string, list<string>> */
    public static function nameRules(): array
    {
        return ['first_name' => ['required', 'string', 'max:60', 'not_regex:/[\x00-\x1f\x7f]/'], 'last_name' => ['required', 'string', 'max:60', 'not_regex:/[\x00-\x1f\x7f]/']];
    }

    /** @param array{first_name: string, last_name: string}|null $names */
    private function attach(UserRecord $user, ?array $names): ?string
    {
        return DB::transaction(function () use ($user, $names): ?string {
            // Serialized with staff customer and group writes (EloquentCustomerAdministration).
            DB::select('SELECT pg_advisory_xact_lock(2142514)');
            app(AuditContext::class)->actor((string) $user->getKey());
            $linked = DB::table('customers')->where('user_id', $user->getKey())->value('id');
            if ($linked !== null) {
                DB::table('customer_registrations')->where('user_id', $user->getKey())->delete();

                return (string) $linked;
            }
            $email = mb_strtolower(trim($user->email));
            $existing = DB::table('customers')->where('email', $email)->lockForUpdate()->first(['id', 'user_id', 'lock_version']);
            if ($existing !== null) {
                if ($existing->user_id !== null) {
                    // The profile with this e-mail already belongs to another sign-in identity.
                    return null;
                }
                DB::table('customers')->where('id', $existing->id)->update(['user_id' => $user->getKey(), 'account_linked_at' => now(), 'lock_version' => (int) $existing->lock_version + 1, 'updated_at' => now()]);
                DB::table('customer_registrations')->where('user_id', $user->getKey())->delete();

                return (string) $existing->id;
            }
            $pending = DB::table('customer_registrations')->where('user_id', $user->getKey())->lockForUpdate()->first(['first_name', 'last_name']);
            $names ??= $pending === null ? null : ['first_name' => $pending->first_name, 'last_name' => $pending->last_name];
            if ($names === null) {
                return null;
            }
            $id = DB::table('customers')->insertGetId(['email' => $email, 'first_name' => trim($names['first_name']), 'last_name' => trim($names['last_name']), 'status' => 'active',
                'user_id' => $user->getKey(), 'account_linked_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            // Same rule as staff creation without an explicit choice: the configured default group, if any.
            $default = DB::table('customer_groups')->where('is_default', true)->where('status', 'active')->value('id');
            if ($default !== null) {
                DB::table('customer_group_members')->insert(['customer_id' => $id, 'group_id' => $default]);
            }
            DB::table('customer_registrations')->where('user_id', $user->getKey())->delete();

            return (string) $id;
        });
    }
}
