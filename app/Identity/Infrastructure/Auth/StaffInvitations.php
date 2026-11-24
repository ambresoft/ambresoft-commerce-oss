<?php

namespace App\Identity\Infrastructure\Auth;

use App\Identity\Infrastructure\Persistence\UserRecord;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use SensitiveParameter;

final class StaffInvitations
{
    public function url(UserRecord $user): string
    {
        return rtrim((string) config('app.url'), '/').URL::temporarySignedRoute('admin.register', now()->addDays(7), [
            'user' => $user->id, 'token' => $this->token($user),
        ], absolute: false);
    }

    public function send(UserRecord $user): void
    {
        $user->notify(new StaffInvitation($this->url($user)));
    }

    public function invited(Request $request, bool $lock = false): UserRecord
    {
        abort_unless($request->hasValidRelativeSignature(), 403);
        $id = $request->query('user');
        $token = $request->query('token');
        abort_unless(is_string($id) && ctype_digit($id) && is_string($token), 403);
        $user = UserRecord::query()->when($lock, fn ($q) => $q->lockForUpdate())->find($id);
        abort_unless($user !== null && $user->is_active && ! $user->hasVerifiedEmail()
            && $user->roles()->exists() && hash_equals($this->token($user), $token), 403);

        return $user;
    }

    /** @param Closure(UserRecord): void|null $enroll */
    public function accept(Request $request, #[SensitiveParameter] ?string $password, ?Closure $enroll = null): UserRecord
    {
        return DB::transaction(function () use ($request, $password, $enroll): UserRecord {
            DB::select('SELECT pg_advisory_xact_lock(2142512)');
            $user = $this->invited($request, lock: true);
            $enroll?->__invoke($user);
            $user->forceFill(['password' => $password ?? Str::random(64), 'email_verified_at' => now(), 'remember_token' => Str::random(60)])->save();

            return $user;
        });
    }

    private function token(UserRecord $user): string
    {
        return hash_hmac('sha256', 'admin|'.$user->id.'|'.$user->email.'|'.$user->getAuthPassword(), (string) config('app.key'));
    }
}
