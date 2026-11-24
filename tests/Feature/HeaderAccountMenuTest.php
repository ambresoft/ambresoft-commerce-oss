<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class HeaderAccountMenuTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_publishes_translated_account_shortcuts_for_guests(): void
    {
        $this->withoutVite();
        DB::table('general_settings')->where('id', 1)->update(['storefront_open' => true]);
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('i18n.strings', fn ($strings) => collect(['account.menu_title', 'account.nav_orders', 'auth.sign_in', 'navigation.sign_out'])
                ->every(fn (string $key): bool => isset($strings[$key]) && $strings[$key] !== $key)));
    }
}
