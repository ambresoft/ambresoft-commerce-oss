<?php

namespace Tests\Feature;

use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Identity\Infrastructure\Persistence\UserRecord;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class ProductFacebookTypeTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private UserRecord $actor;

    private ProductRecord $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::flush();
        Storage::fake('local');
        app()->setLocale('en');
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actor = $this->staff(super: true);
        $locale = (int) DB::table('locales')->where('code', 'en')->value('id');
        $category = CategoryRecord::create(['position' => 1, 'status' => 'active']);
        $category->translations()->create(['locale_id' => $locale, 'name' => 'Phones', 'slug' => 'phones']);
        $this->product = ProductRecord::create(['sku' => 'FB-1', 'price' => '10', 'quantity' => 5, 'category_id' => $category->id, 'status' => 'active']);
        $this->product->translations()->create(['locale_id' => $locale, 'name' => 'Aster One', 'slug' => 'aster-one', 'seo_description' => 'A phone']);
    }

    /** @param array<string, mixed> $changes */
    private function save(array $changes = []): TestResponse
    {
        $p = $this->product->fresh();

        return $this->actingAs($this->actor)->post('/admin/products/'.$p->id, array_replace(['version' => $p->lock_version, 'sku' => 'FB-1', 'type' => 'physical', 'status' => 'active', 'price' => '10', 'category_id' => (string) $p->category_id,
            'translations' => ['en' => ['name' => 'Aster One', 'slug' => 'aster-one', 'seo_description' => 'A phone']], 'images' => [], 'primary_image' => null, 'files' => []], $changes));
    }

    public function test_the_additional_tab_saves_and_loads_the_object_type_from_the_page_list(): void
    {
        $this->actingAs($this->actor)->get('/admin/products/'.$this->product->id.'/edit')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('product.facebook_type', 'product')->where('facebookTypes.products.6', 'product')->where('facebookTypes.websites.1', 'article'));

        $this->save(['facebook_type' => 'book'])->assertRedirect()->assertSessionHasNoErrors();
        self::assertSame('book', DB::table('products')->where('id', $this->product->id)->value('facebook_type'));
        $this->get('/admin/products/'.$this->product->id.'/edit')->assertInertia(fn (Assert $p) => $p->where('product.facebook_type', 'book'));

        // A save that does not send the field keeps it; a type outside the pages' list is refused.
        $this->save()->assertRedirect()->assertSessionHasNoErrors();
        self::assertSame('book', DB::table('products')->where('id', $this->product->id)->value('facebook_type'));
        $this->save(['facebook_type' => 'spaceship'])->assertSessionHasErrors('facebook_type');
        self::assertSame('book', DB::table('products')->where('id', $this->product->id)->value('facebook_type'));
        $this->assertQueryRefused();
    }

    private function assertQueryRefused(): void
    {
        $this->expectException(QueryException::class);
        DB::table('products')->where('id', $this->product->id)->update(['facebook_type' => 'Not Valid']);
    }

    public function test_an_empty_type_falls_back_to_the_product_default(): void
    {
        $this->save(['facebook_type' => 'book'])->assertRedirect()->assertSessionHasNoErrors();
        $this->save(['facebook_type' => ''])->assertRedirect()->assertSessionHasNoErrors();
        self::assertSame('product', DB::table('products')->where('id', $this->product->id)->value('facebook_type'));
    }

    public function test_the_storefront_product_page_outputs_social_metadata_with_the_saved_type(): void
    {
        $base = rtrim((string) config('app.url'), '/');
        $html = $this->get('/phones/aster-one')->assertOk()->getContent();
        self::assertStringContainsString('<meta property="og:type" content="product"', $html);
        self::assertStringContainsString('<meta property="og:title" content="Aster One"', $html);
        self::assertStringContainsString('<meta property="og:description" content="A phone"', $html);
        self::assertStringContainsString('<meta property="og:url" content="'.$base.'/phones/aster-one"', $html);

        DB::table('products')->where('id', $this->product->id)->update(['facebook_type' => 'book']);
        $this->get('/phones/aster-one')->assertSee('<meta property="og:type" content="book"', false)->assertDontSee('content="product"', false);
    }
}
