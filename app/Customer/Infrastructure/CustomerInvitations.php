<?php

namespace App\Customer\Infrastructure;

use App\Audit\Domain\Model\AuditEvent;
use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Identity\Domain\Model\PasswordPolicy;
use App\Identity\Infrastructure\Persistence\UserRecord;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Staff invite a customer they created to set a password (A100). The invitation creates an unverified sign-in identity
 * with an unusable random password and e-mails a signed link valid for 7 days. The link is bound to the identity's
 * e-mail and current password hash, so it works once and dies when the e-mail or password changes. Accepting it proves
 * the mailbox: the e-mail is verified and the existing profile is claimed like any verified identity.
 */
final class CustomerInvitations
{
    public const DAYS = 7;

    public function __construct(private CustomerAccounts $accounts) {}

    public function send(string $actor, string $customerId): void
    {
        app(AuditLogger::class)->operation('customer', $actor, function () use ($actor, $customerId): void {
            app(AuditContext::class)->actor($actor);
            Gate::forUser(UserRecord::findOrFail($actor))->authorize('customers.update');
            $user = DB::transaction(function () use ($customerId): UserRecord {
                DB::select('SELECT pg_advisory_xact_lock(2142514)');
                $customer = DB::table('customers')->where('id', $customerId)->lockForUpdate()->first() ?? throw new ModelNotFoundException('Customer not found.');
                if ($customer->user_id !== null) {
                    throw ValidationException::withMessages(['invitation' => __('customers.invite_linked')]);
                }
                if ($customer->status !== 'active') {
                    throw ValidationException::withMessages(['invitation' => __('customers.invite_disabled')]);
                }
                $user = UserRecord::where('email', $customer->email)->lockForUpdate()->first();
                if ($user !== null && $user->hasVerifiedEmail()) {
                    // A verified identity already owns this e-mail; it claims the profile at its next sign-in.
                    throw ValidationException::withMessages(['invitation' => __('customers.invite_existing')]);
                }

                return $user ?? UserRecord::create(['name' => mb_substr(trim($customer->first_name.' '.$customer->last_name), 0, 120), 'email' => $customer->email, 'password' => Str::random(64)]);
            });
            $user->notify(new CustomerInvitation($this->url($user), (string) config('app.name')));
            app(AuditLogger::class)->record(new AuditEvent('customer.invitation_sent', 'customer', $customerId));
        });
    }

    /** The identity an invitation link opens, or null when it is no longer usable. */
    public function invited(string $userId, string $hash): ?UserRecord
    {
        $user = UserRecord::find($userId);

        return $user !== null && $user->is_active && ! $user->hasVerifiedEmail() && hash_equals($this->hash($user), $hash) ? $user : null;
    }

    public function accept(UserRecord $user, #[SensitiveParameter] string $password, #[SensitiveParameter] string $confirmation): void
    {
        Validator::make(['password' => $password, 'password_confirmation' => $confirmation], ['password' => ['required', 'string', 'confirmed', Password::min(PasswordPolicy::CUSTOMER_MIN)->max(PasswordPolicy::MAX)]])->validate();
        DB::transaction(function () use ($user, $password): void {
            app(AuditContext::class)->actor((string) $user->getKey());
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60), 'email_verified_at' => now()])->save();
        });
        $this->accounts->activate($user->refresh());
    }

    private function url(UserRecord $user): string
    {
        return rtrim((string) config('app.url'), '/').URL::temporarySignedRoute('account.invitation', now()->addDays(self::DAYS), ['user' => $user->getKey(), 'hash' => $this->hash($user)], absolute: false);
    }

    private function hash(UserRecord $user): string
    {
        return hash_hmac('sha256', $user->email.'|'.$user->getAuthPassword(), (string) config('app.key'));
    }
}
