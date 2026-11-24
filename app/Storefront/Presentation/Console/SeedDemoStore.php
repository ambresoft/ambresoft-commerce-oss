<?php

namespace App\Storefront\Presentation\Console;

use App\Authorization\Infrastructure\Persistence\RoleRecord;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Storefront\Infrastructure\DemoStoreSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class SeedDemoStore extends Command
{
    protected $signature = 'cms:demo-store
        {--force : Allow running outside local/testing (for a client demo install; never with --with-admin)}
        {--with-admin : Local only: also create a demo super administrator and fill the whole store (brands, banners, blog, FAQs, pages, customers, orders, newsletters)}
        {--email=admin@example.com : Email of the demo super administrator}';

    protected $description = 'Create a ready demo electronics store: categories, products with photos, menus and a full homepage';

    /** The other demo seeders, run as the demo administrator (each is idempotent). Newsletters first: the homepage sign-up joins its list. */
    private const DEMOS = ['cms:newsletters-demo', 'cms:brands-demo', 'cms:banners-demo', 'cms:blog-demo', 'cms:faqs-demo', 'cms:pages-demo', 'cms:customers-demo', 'cms:orders-demo'];

    public function handle(DemoStoreSeeder $seeder): int
    {
        $local = app()->environment(['local', 'testing']);
        if ($this->option('with-admin') && ! $local) {
            // A known demo login must never reach a real store, even with --force.
            $this->error('--with-admin is only available in the local or testing environment.');

            return self::FAILURE;
        }
        if (! $local && ! $this->option('force')) {
            $this->error('Run with --force to create demo data outside local or testing environments.');

            return self::FAILURE;
        }
        try {
            $admin = null;
            if ($this->option('with-admin')) {
                $admin = $this->administrator(mb_strtolower(trim((string) $this->option('email'))));
                if ($admin === null) {
                    return self::FAILURE;
                }
                $this->call('cms:newsletters-demo', ['actor' => $admin]);
            }
            $result = $seeder->run();
            $this->info($result['categories'] === 0 && ! $result['menus'] ? 'The demo store already exists; nothing was changed.' : sprintf('Created %d categories and %d products%s.', $result['categories'], $result['products'], $result['menus'] ? ' and the demo menus' : ''));
            if ($admin !== null) {
                foreach (array_slice(self::DEMOS, 1) as $demo) {
                    $this->call($demo, ['actor' => $admin]);
                }
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /** Creates (once) a verified demo super administrator with a random password shown only now, and returns its id. */
    private function administrator(string $email): ?string
    {
        $role = RoleRecord::where('is_super_admin', true)->first();
        if ($role === null) {
            $this->error('Run php artisan cms:permissions-sync first.');

            return null;
        }
        $existing = UserRecord::where('email', $email)->first();
        if ($existing !== null) {
            if (! $existing->roles()->where('roles.id', $role->id)->exists()) {
                $this->error($email.' already exists and is not a super administrator; pass another --email.');

                return null;
            }
            $this->line('Demo administrator '.$email.' already exists; its password is unchanged.');

            return (string) $existing->id;
        }
        $password = Str::password(20, symbols: false);
        $user = DB::transaction(function () use ($email, $password, $role): UserRecord {
            $user = UserRecord::create(['name' => 'Demo administrator', 'email' => $email, 'password' => $password]);
            $user->forceFill(['email_verified_at' => now(), 'is_active' => true])->save();
            $user->roles()->attach($role->id);

            return $user;
        });
        $this->newLine();
        $this->info('Demo super administrator created (local only). Sign in at '.rtrim((string) config('app.url'), '/').'/admin');
        $this->line('  Email:    '.$email);
        $this->line('  Password: '.$password);
        $this->warn('The password is shown only once. Change it in the admin, and never use this account on a public store.');
        $this->newLine();

        return (string) $user->id;
    }
}
