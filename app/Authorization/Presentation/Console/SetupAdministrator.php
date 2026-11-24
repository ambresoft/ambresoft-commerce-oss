<?php

namespace App\Authorization\Presentation\Console;

use App\Authorization\Infrastructure\Persistence\RoleRecord;
use App\Identity\Infrastructure\Auth\StaffInvitations;
use App\Identity\Infrastructure\Persistence\UserRecord;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class SetupAdministrator extends Command
{
    protected $signature = 'cms:admin-setup {email} {--name=Administrator}';

    protected $description = 'Email a private setup link for the first super administrator';

    public function handle(StaffInvitations $invitations): int
    {
        $data = ['email' => mb_strtolower(trim((string) $this->argument('email'))), 'name' => $this->option('name')];
        $validator = Validator::make($data, ['email' => ['required', 'string', 'email:rfc', 'max:254'], 'name' => ['required', 'string', 'max:120']]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }
        $user = DB::transaction(function () use ($data): ?UserRecord {
            DB::select('SELECT pg_advisory_xact_lock(2142512)');
            $role = RoleRecord::where('is_super_admin', true)->first();
            if ($role === null) {
                return null;
            }
            $existing = UserRecord::where('email', $data['email'])->lockForUpdate()->first();
            if (DB::table('role_user')->exists()) {
                // A failed email delivery or expired setup link must not strand the first operator.
                if ($existing && $existing->is_active && ! $existing->hasVerifiedEmail()
                    && DB::table('role_user')->count() === 1 && $existing->roles()->where('roles.id', $role->id)->exists()) {
                    $existing->password = Str::random(64);
                    $existing->save();

                    return $existing;
                }

                return null;
            }
            if ($existing !== null) {
                return null;
            }
            $user = UserRecord::create([...$data, 'password' => Str::random(64)]);
            $user->roles()->attach($role->id);

            return $user;
        });
        if ($user === null) {
            $this->error('Setup requires synchronized roles and no existing staff assignments. Use cms:admin-grant for recovery.');

            return self::FAILURE;
        }
        $invitations->send($user);
        $this->info('Private administrator setup link emailed. It expires in 7 days.');

        return self::SUCCESS;
    }
}
