<?php

namespace App\Identity\Infrastructure\Auth;

use App\Audit\Infrastructure\AuditContext;
use App\Identity\Application\Command\ResetPasswordData;
use App\Identity\Application\Contract\PasswordResetterInterface;
use App\Identity\Infrastructure\Persistence\UserRecord;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use SensitiveParameter;

final class LaravelPasswordResetter implements PasswordResetterInterface
{
    public function reset(#[SensitiveParameter] ResetPasswordData $data): bool
    {
        $status = Password::reset([
            'email' => $data->email,
            'token' => $data->token,
            'password' => $data->password,
        ], function (UserRecord $user, #[SensitiveParameter] string $password): void {
            DB::transaction(function () use ($user, $password): void {
                app(AuditContext::class)->actor((string) $user->id);
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                event(new PasswordReset($user));
            });
        });

        return $status === Password::PasswordReset;
    }
}
