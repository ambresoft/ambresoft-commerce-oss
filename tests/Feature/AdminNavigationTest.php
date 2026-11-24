<?php

namespace Tests\Feature;

use App\Admin\Navigation\AdminNavigation;
use App\Identity\Infrastructure\Persistence\UserRecord as User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use LogicException;
use Tests\TestCase;

final class AdminNavigationTest extends TestCase
{
    public function test_contributions_are_sorted_filtered_and_never_grant_route_access(): void
    {
        $navigation = new AdminNavigation;
        Route::get('/admin/test', fn () => 'test')->name('module.test.example.admin.index');
        Route::getRoutes()->refreshNameLookups();
        Gate::define('test.allowed', fn (User $user) => true);
        Gate::define('test.denied', fn (User $user) => false);
        $navigation->add('test.last', 'Last', 'module.test.example.admin.index', 'test.allowed', 10);
        $navigation->add('test.hidden', 'Hidden', 'module.test.example.admin.index', 'test.denied', -1);
        $navigation->add('test.first', 'First', 'module.test.example.admin.index', 'test.allowed', 0);
        $user = new User(['name' => 'Example', 'email' => 'example@example.test']);
        self::assertSame(['test.first', 'test.last'], array_column($navigation->forUser($user), 'id'));
        self::assertFalse(Gate::forUser($user)->allows('access-admin'));
    }

    public function test_duplicate_ids_are_rejected(): void
    {
        $navigation = new AdminNavigation;
        $navigation->add('test.one', 'One', 'admin.dashboard', 'access-admin');
        $this->expectException(LogicException::class);
        $navigation->add('test.one', 'Two', 'admin.dashboard', 'access-admin');
    }

    public function test_group_metadata_is_localized_and_only_authorized_children_are_projected(): void
    {
        $navigation = new AdminNavigation;
        $navigation->addGroup('test.access', 'navigation.access_management');
        Gate::define('test.allowed', fn () => true);
        Gate::define('test.denied', fn () => false);
        $navigation->add('test.users', 'navigation.users', 'admin.users.index', 'test.allowed', 10, 'test.access');
        $navigation->add('test.roles', 'navigation.roles', 'admin.roles.index', 'test.denied', 20, 'test.access');
        app()->setLocale('el');
        $items = $navigation->forUser(new User);
        self::assertCount(1, $items);
        self::assertSame('test.users', $items[0]['id']);
        self::assertSame(['id' => 'test.access', 'label' => 'Διαχείριση πρόσβασης'], $items[0]['group']);
        Gate::define('test.allowed', fn () => false);
        self::assertSame([], $navigation->forUser(new User));
    }

    public function test_missing_groups_are_rejected(): void
    {
        $navigation = new AdminNavigation;
        $navigation->add('test.users', 'Users', 'admin.users.index', 'access-admin', 10, 'test.missing');
        $this->expectException(LogicException::class);
        $navigation->forUser(new User);
    }

    public function test_duplicate_groups_are_rejected(): void
    {
        $navigation = new AdminNavigation;
        $navigation->addGroup('test.access', 'Access');
        $this->expectException(LogicException::class);
        $navigation->addGroup('test.access', 'Duplicate');
    }

    public function test_missing_routes_are_rejected_instead_of_rendering_dead_links(): void
    {
        $navigation = new AdminNavigation;
        $navigation->add('test.missing', 'Missing', 'admin.missing', 'access-admin');
        $this->expectException(LogicException::class);
        $navigation->forUser(new User);
    }
}
