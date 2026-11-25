<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class FoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_neutral_page_uses_the_inertia_contract(): void
    {
        $this->withoutVite();
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Storefront/Home')
            ->where('storefront.theme', 'default'));
    }

    public function test_liveness_and_absent_future_entry_points(): void
    {
        $this->get('/up')->assertOk();

        foreach (['/products', '/categories', '/api/products', '/api/auth/login', '/storefront'] as $uri) {
            $this->get($uri)->assertNotFound();
        }
    }
}
