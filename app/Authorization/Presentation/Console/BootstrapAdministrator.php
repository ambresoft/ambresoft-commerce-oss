<?php

namespace App\Authorization\Presentation\Console;

use App\Authorization\Infrastructure\Persistence\RoleRecord;
use App\Identity\Infrastructure\Persistence\UserRecord;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class BootstrapAdministrator extends Command
{
    protected $signature = 'cms:admin-grant {email : Existing verified active user email}';

    protected $description = 'Explicit operator recovery/bootstrap grant; never creates an account or changes a password';

    public function handle(): int
    {
        return DB::transaction(function (): int {
            DB::select('SELECT pg_advisory_xact_lock(2142512)');
            $user = UserRecord::where('email', mb_strtolower(trim((string) $this->argument('email'))))->where('is_active', true)->whereNotNull('email_verified_at')->first();
            $role = RoleRecord::where('is_super_admin', true)->first();
            if (! $user || ! $role) {
                $this->error('An active verified user and the synchronized super-admin role are required.');

                return self::FAILURE;
            }
            $user->roles()->syncWithoutDetaching([$role->id]);
            $this->info('Super-admin role granted to the specified existing identity.');

            return self::SUCCESS;
        });
    }
}
