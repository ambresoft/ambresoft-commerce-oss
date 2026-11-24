<?php

namespace Tests\Feature;

use App\Catalog\Infrastructure\ProductRatings;
use App\Catalog\Infrastructure\ProductReviews;
use App\Catalog\Infrastructure\ReviewPurchases;
use App\Identity\Infrastructure\Persistence\UserRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsReportFixtures;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class ProductReviewTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase, StaffIdentities;

    private UserRecord $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->fixtureStore();
        $this->actor = $this->staff(super: true);
        $this->actingAs($this->actor);
    }

    /** @param array<string, mixed> $input */
    private function submit(string $customer, string $product, array $input = []): string
    {
        return app(ProductReviews::class)->submit((string) $this->ids[$customer], (string) $this->ids[$product], [...['rating' => 4, 'body' => 'Works well.'], ...$input]);
    }

    /** @param array<string, mixed> $input */
    private function rejects(string $field, array $input, string $customer = 'c1', string $product = 'A'): void
    {
        try {
            $this->submit($customer, $product, $input);
            self::fail('Expected a validation error for '.$field.': '.json_encode($input));
        } catch (ValidationException $e) {
            self::assertArrayHasKey($field, $e->errors(), json_encode($e->errors()));
        }
    }

    private function review(string $id): object
    {
        return DB::table('product_reviews')->where('id', $id)->sole();
    }

    private function approve(string $id): void
    {
        app(ProductReviews::class)->moderate((string) $this->actor->id, $id, (int) $this->review($id)->lock_version, 'approved');
    }

    private function customer(string $first, string $last = 'Buyer', string $status = 'active'): int
    {
        return (int) DB::table('customers')->insertGetId(['email' => strtolower(Str::random(10)).'@example.test', 'first_name' => $first, 'last_name' => $last, 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_customer_submission_is_pending_and_ignores_forged_protected_fields(): void
    {
        $id = $this->submit('c1', 'A', ['rating' => '5', 'title' => '  Great phone ', 'body' => "  Fast\r\nand light.  ", 'status' => 'approved', 'verified_purchase' => true, 'moderated_by' => $this->actor->id, 'moderated_at' => now(), 'moderation_note' => 'mine', 'customer_id' => $this->ids['c2'], 'product_id' => $this->ids['B'], 'lock_version' => 99]);
        $row = $this->review($id);
        self::assertSame(['pending', false, null, null, null, 5, 'Great phone', "Fast\nand light.", $this->ids['c1'], $this->ids['A'], 1], [$row->status, $row->verified_purchase, $row->moderated_by, $row->moderated_at, $row->moderation_note, (int) $row->rating, $row->title, $row->body, (int) $row->customer_id, (int) $row->product_id, (int) $row->lock_version]);
        // A blank optional title is stored as no title.
        self::assertNull($this->review($this->submit('c2', 'A', ['title' => '   ']))->title);
    }

    public function test_rating_title_and_text_are_validated(): void
    {
        foreach ([0, 6, -1, 4.5, '4.5', '3.0', 'five', true, null, [], '', ' 4'] as $rating) {
            $this->rejects('rating', ['rating' => $rating]);
        }
        $this->rejects('rating', ['rating' => null, 'body' => 'Fine']);
        foreach (['', '   ', "\n\t ", '!!! ...', str_repeat('a', 5001), "Bad\x00byte", ['text'], 42] as $body) {
            $this->rejects('body', ['body' => $body]);
        }
        foreach (['???', str_repeat('t', 151), "Line\nbreak", "Tab\tbed", ['x']] as $title) {
            $this->rejects('title', ['title' => $title]);
        }
        self::assertSame(0, DB::table('product_reviews')->count());
        // Boundaries and plain HTML-like text are accepted and stored as typed; every view escapes it.
        $id = $this->submit('c1', 'A', ['rating' => 1, 'title' => str_repeat('t', 150), 'body' => '<script>alert(1)</script> '.str_repeat('b', 4974)]);
        self::assertSame(5000, mb_strlen($this->review($id)->body));
        $this->submit('c2', 'A', ['rating' => 5, 'body' => 'Ναι, 5/5']);
    }

    public function test_one_review_per_customer_and_product(): void
    {
        $this->submit('c1', 'A');
        try {
            $this->submit('c1', 'A', ['rating' => 1, 'body' => 'Changed my mind']);
            self::fail('A duplicate review was created');
        } catch (ValidationException $e) {
            self::assertSame([__('reviews.duplicate')], $e->errors()['product']);
        }
        $this->submit('c1', 'C');
        $this->submit('c2', 'A');
        self::assertSame(3, DB::table('product_reviews')->count());
        $this->expectException(QueryException::class);
        DB::table('product_reviews')->insert(['product_id' => $this->ids['A'], 'customer_id' => $this->ids['c1'], 'rating' => 3, 'body' => 'Bypass', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_missing_trashed_or_disabled_products_and_unusable_customers_cannot_review(): void
    {
        $disabled = (int) DB::table('products')->insertGetId(['type' => 'physical', 'sku' => 'DIS-1', 'status' => 'disabled', 'price' => 10, 'quantity' => 1, 'out_of_stock_actions' => 'N', 'created_at' => now(), 'updated_at' => now()]);
        $hidden = (int) DB::table('products')->insertGetId(['type' => 'physical', 'sku' => 'HID-1', 'status' => 'hidden', 'price' => 10, 'quantity' => 1, 'out_of_stock_actions' => 'N', 'created_at' => now(), 'updated_at' => now()]);
        $reviews = app(ProductReviews::class);
        foreach (['999999', 'abc', '0', (string) $this->ids['E'], (string) $disabled] as $product) {
            try {
                $reviews->submit((string) $this->ids['c1'], $product, ['rating' => 4, 'body' => 'Nice']);
                self::fail('Reviewed ineligible product '.$product);
            } catch (ValidationException $e) {
                self::assertSame([__('reviews.product_unavailable')], $e->errors()['product']);
            }
        }
        foreach (['999999', (string) $this->customer('Disabled', status: 'disabled')] as $customer) {
            try {
                $reviews->submit($customer, (string) $this->ids['A'], ['rating' => 4, 'body' => 'Nice']);
                self::fail('Unusable customer reviewed');
            } catch (ValidationException $e) {
                self::assertArrayHasKey('customer', $e->errors());
            }
        }
        // Hidden products can be bought by direct link and so can be reviewed.
        $reviews->submit((string) $this->ids['c1'], (string) $hidden, ['rating' => 4, 'body' => 'Nice']);
        self::assertSame(1, DB::table('product_reviews')->count());
    }

    public function test_verified_purchase_requires_a_paid_non_void_order_of_the_exact_product(): void
    {
        // c1: paid and completed order containing A and B.
        $this->sale('2026-10-05 10:00', [['A', 'Phone A', 1, '50'], ['B', 'Laptop B', 1, '200']], [['250', '2026-10-05 10:05']], ['customer' => 'c1', 'status' => 'completed']);
        // c2: cash on delivery for C not yet collected, A partly paid, B paid then cancelled (no refund records exist).
        $this->sale('2026-10-06 10:00', [['C', 'Book C', 1, '30']], [], ['customer' => 'c2']);
        $this->sale('2026-10-06 11:00', [['A', 'Phone A', 2, '50']], [['40', '2026-10-06 11:10']], ['customer' => 'c2']);
        $this->sale('2026-10-06 12:00', [['B', 'Laptop B', 1, '200']], [['200', '2026-10-06 12:10']], ['customer' => 'c2', 'status' => 'cancelled']);
        // A guest order with c3's e-mail is not linked to the customer and proves nothing.
        $this->skus['D'] = 'NS-1';
        $this->sale('2026-10-07 10:00', [['D', 'Book D', 1, '50']], [['50', '2026-10-07 10:05']], ['email' => 'c3@example.test']);
        $verified = fn (string $customer, string $product): bool => (bool) $this->review($this->submit($customer, $product, ['verified_purchase' => true]))->verified_purchase;
        self::assertTrue($verified('c1', 'A'));
        self::assertTrue($verified('c1', 'B'));
        self::assertFalse($verified('c1', 'C'));
        self::assertFalse($verified('c2', 'C'));
        self::assertFalse($verified('c2', 'A'));
        self::assertFalse($verified('c2', 'B'));
        self::assertFalse($verified('c3', 'D'));
        // The batch seam answers many pairs at once with the same rule.
        $pairs = [[(string) $this->ids['c1'], (string) $this->ids['A']], [(string) $this->ids['c2'], (string) $this->ids['A']], [(string) $this->ids['c1'], (string) $this->ids['D']]];
        self::assertSame([$this->ids['c1'].':'.$this->ids['A'] => true], app(ReviewPurchases::class)->verifiedPairs($pairs));
    }

    public function test_approval_rechecks_the_purchase_so_the_public_badge_is_current(): void
    {
        $order = $this->sale('2026-10-06 10:00', [['C', 'Book C', 1, '30']], [], ['customer' => 'c2', 'payment' => 'cod']);
        $id = $this->submit('c2', 'C');
        self::assertFalse((bool) $this->review($id)->verified_purchase);
        DB::table('order_payments')->insert(['reference' => (string) Str::uuid(), 'order_id' => $order, 'payment_method_id' => $this->ids['cod'], 'kind' => 'manual', 'state' => 'captured', 'amount' => '30', 'currency_code' => 'EUR', 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->approve($id);
        self::assertTrue((bool) $this->review($id)->verified_purchase);
        // Cancelled afterwards: rejecting and re-approving publishes the current answer.
        DB::table('orders')->where('id', $order)->update(['status_id' => $this->ids['cancelled']]);
        $reviews = app(ProductReviews::class);
        foreach (['rejected', 'pending', 'approved'] as $status) {
            $reviews->moderate((string) $this->actor->id, $id, (int) $this->review($id)->lock_version, $status);
        }
        self::assertFalse((bool) $this->review($id)->verified_purchase);
    }

    public function test_moderation_transitions_record_the_moderator_and_reject_invalid_or_stale_changes(): void
    {
        $id = $this->submit('c1', 'A');
        $moderator = $this->staff(['admin.access', 'reviews.view', 'reviews.moderate']);
        $this->actingAs($moderator);
        $this->post('/admin/reviews/'.$id.'/status', ['version' => 1, 'status' => 'approved'])->assertSessionHasNoErrors()->assertRedirect();
        $row = $this->review($id);
        self::assertSame(['approved', (int) $moderator->id, 2], [$row->status, (int) $row->moderated_by, (int) $row->lock_version]);
        self::assertNotNull($row->moderated_at);
        foreach ([['approved', 'status'], ['pending', 'status'], ['archived', 'status']] as [$status, $field]) {
            $this->post('/admin/reviews/'.$id.'/status', ['version' => 2, 'status' => $status])->assertSessionHasErrors($field);
        }
        $this->post('/admin/reviews/'.$id.'/status', ['version' => 1, 'status' => 'rejected'])->assertSessionHasErrors('version');
        $chain = [['rejected', 3], ['spam', 4], ['pending', 5], ['spam', 6], ['pending', 7], ['rejected', 8], ['pending', 9]];
        foreach ($chain as [$status, $version]) {
            $this->post('/admin/reviews/'.$id.'/status', ['version' => $version - 1, 'status' => $status])->assertSessionHasNoErrors();
            self::assertSame($status, $this->review($id)->status);
        }
        $this->post('/admin/reviews/'.$id.'/status', ['version' => 9, 'status' => 'pending'])->assertSessionHasErrors('status');
        // Every saved change is in the audit trail with the moderator, without the review text.
        $logs = DB::table('audit_logs')->where('entity_type', 'review')->where('entity_id', $id)->where('action', 'review.status_changed')->orderBy('id')->get();
        self::assertCount(8, $logs);
        self::assertSame((string) $moderator->id, (string) $logs->first()->actor_id);
        self::assertStringNotContainsString('Works well', $logs->pluck('changes')->implode(' '));
    }

    public function test_rating_summary_counts_only_approved_reviews_and_follows_every_change(): void
    {
        $ratings = app(ProductRatings::class);
        $empty = ['count' => 0, 'average' => null, 'distribution' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0]];
        self::assertSame($empty, $ratings->summary((string) $this->ids['A']));
        $one = $this->submit('c1', 'A', ['rating' => 5]);
        $two = $this->submit('c2', 'A', ['rating' => 4]);
        $three = $this->submit('c3', 'A', ['rating' => 4]);
        self::assertSame($empty, $ratings->summary((string) $this->ids['A']));
        foreach ([$one, $two, $three] as $id) {
            $this->approve($id);
        }
        self::assertSame(['count' => 3, 'average' => '4.3', 'distribution' => [1 => 0, 2 => 0, 3 => 0, 4 => 2, 5 => 1]], $ratings->summary((string) $this->ids['A']));
        // Staff correction of a rating: 5, 5, 4 = 4.67 → 4.7.
        $this->patch('/admin/reviews/'.$two, ['version' => $this->review($two)->lock_version, 'rating' => 5])->assertSessionHasNoErrors();
        self::assertSame(['count' => 3, 'average' => '4.7', 'distribution' => [1 => 0, 2 => 0, 3 => 0, 4 => 1, 5 => 2]], $ratings->summary((string) $this->ids['A']));
        $this->post('/admin/reviews/'.$one.'/status', ['version' => $this->review($one)->lock_version, 'status' => 'rejected'])->assertSessionHasNoErrors();
        self::assertSame(['count' => 2, 'average' => '4.5', 'distribution' => [1 => 0, 2 => 0, 3 => 0, 4 => 1, 5 => 1]], $ratings->summary((string) $this->ids['A']));
        $this->post('/admin/reviews/'.$two.'/status', ['version' => $this->review($two)->lock_version, 'status' => 'spam'])->assertSessionHasNoErrors();
        self::assertSame(['count' => 1, 'average' => '4.0', 'distribution' => [1 => 0, 2 => 0, 3 => 0, 4 => 1, 5 => 0]], $ratings->summary((string) $this->ids['A']));
        $this->delete('/admin/reviews/'.$three, ['version' => $this->review($three)->lock_version])->assertSessionHasNoErrors();
        self::assertSame($empty, $ratings->summary((string) $this->ids['A']));
        // Permanently deleting a product removes its reviews with it.
        $this->approve($this->submit('c1', 'C', ['rating' => 3]));
        DB::table('order_items')->where('product_id', $this->ids['C'])->delete();
        DB::table('product_translations')->where('product_id', $this->ids['C'])->delete();
        DB::table('products')->where('id', $this->ids['C'])->delete();
        self::assertSame(0, DB::table('product_reviews')->where('product_id', $this->ids['C'])->count());
    }

    public function test_summaries_for_many_products_take_one_query(): void
    {
        $products = [];
        foreach (range(1, 20) as $n) {
            $products[] = $id = (string) DB::table('products')->insertGetId(['type' => 'physical', 'sku' => 'BULK-'.$n, 'status' => 'active', 'price' => 10, 'quantity' => 1, 'out_of_stock_actions' => 'N', 'created_at' => now(), 'updated_at' => now()]);
            foreach (['c1', 'c2'] as $c => $customer) {
                $review = app(ProductReviews::class)->submit((string) $this->ids[$customer], $id, ['rating' => 1 + ($n + $c) % 5, 'body' => 'Review '.$n]);
                $this->approve($review);
            }
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $summaries = app(ProductRatings::class)->summaries([...$products, (string) $this->ids['D']]);
        self::assertCount(1, DB::getQueryLog());
        DB::disableQueryLog();
        self::assertCount(21, $summaries);
        self::assertSame(2, $summaries[$products[0]]['count']);
        self::assertSame('2.5', $summaries[$products[0]]['average']);
        self::assertNull($summaries[(string) $this->ids['D']]['average']);
    }

    public function test_public_projection_returns_only_approved_reviews_with_safe_fields(): void
    {
        $approved = $this->submit('c1', 'A', ['rating' => 5, 'title' => 'Great', 'body' => 'Public text']);
        $this->approve($approved);
        DB::table('product_reviews')->where('id', $approved)->update(['moderation_note' => 'Internal only remark']);
        $rejected = $this->submit('c2', 'A', ['body' => 'Rejected text']);
        app(ProductReviews::class)->moderate((string) $this->actor->id, $rejected, 1, 'rejected');
        $spam = $this->submit('c3', 'A', ['body' => 'Spam text']);
        app(ProductReviews::class)->moderate((string) $this->actor->id, $spam, 1, 'spam');
        $this->submit('c1', 'C', ['body' => 'Pending text']);
        $page = app(ProductRatings::class)->approved((string) $this->ids['A']);
        self::assertSame(1, $page['total']);
        self::assertSame(['id', 'rating', 'title', 'body', 'author', 'verified_purchase', 'created_at'], array_keys($page['data'][0]));
        self::assertSame(['C1 C.', 'Public text', false], [$page['data'][0]['author'], $page['data'][0]['body'], $page['data'][0]['verified_purchase']]);
        $json = json_encode($page);
        foreach (['c1@example.test', 'Internal only', 'Rejected text', 'Spam text', 'Pending text', '"customer_id"', '"status"'] as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
        self::assertSame(0, app(ProductRatings::class)->approved((string) $this->ids['C'])['total']);
        self::assertSame(0, app(ProductRatings::class)->approved('nope')['total']);
        // No public storefront route exposes reviews in this phase.
        foreach (['/reviews', '/api/reviews'] as $url) {
            $this->get($url)->assertNotFound();
        }
        // Only signed-in customers may POST a review there (A100); nothing lists reviews publicly.
        $this->get('/products/'.$this->ids['A'].'/reviews')->assertMethodNotAllowed();
    }

    public function test_admin_list_filters_search_sort_and_paginate_together(): void
    {
        $this->sale('2026-10-05 10:00', [['A', 'Phone A', 1, '50']], [['50', '2026-10-05 10:05']], ['customer' => 'c1']);
        $a1 = $this->submit('c1', 'A', ['rating' => 5, 'title' => 'Superb phone']);
        $a2 = $this->submit('c2', 'A', ['rating' => 2]);
        $c1 = $this->submit('c1', 'C', ['rating' => 5]);
        $this->approve($a1);
        $this->get('/admin/reviews')->assertInertia(fn (Assert $page) => $page->component('Admin/Reviews/Index')->where('reviews.total', 3)->where('filters.sort', 'created_at')->where('reviews.data.0.id', $c1)
            ->where('reviews.data.0.customer.email', 'c1@example.test')->missing('reviews.data.0.body')->missing('reviews.data.0.moderation_note'));
        $this->get('/admin/reviews?search=Product+A')->assertInertia(fn (Assert $page) => $page->where('reviews.total', 2));
        $this->get('/admin/reviews?search=PH-1&rating=5')->assertInertia(fn (Assert $page) => $page->where('reviews.total', 1)->where('reviews.data.0.id', $a1));
        $this->get('/admin/reviews?search=C2+Customer')->assertInertia(fn (Assert $page) => $page->where('reviews.total', 1)->where('reviews.data.0.id', $a2));
        $this->get('/admin/reviews?search=c2@example')->assertInertia(fn (Assert $page) => $page->where('reviews.total', 1));
        $this->get('/admin/reviews?status=approved&verified=yes')->assertInertia(fn (Assert $page) => $page->where('reviews.total', 1)->where('reviews.data.0.verified_purchase', true));
        $this->get('/admin/reviews?status=pending&verified=no&rating=5')->assertInertia(fn (Assert $page) => $page->where('reviews.total', 1)->where('reviews.data.0.id', $c1));
        $this->get('/admin/reviews?product='.$this->ids['A'].'&sort=rating&direction=asc')->assertInertia(fn (Assert $page) => $page->where('reviews.total', 2)->where('reviews.data.0.id', $a2));
        $this->get('/admin/reviews?sort=moderated_at&direction=desc')->assertInertia(fn (Assert $page) => $page->where('reviews.data.0.id', $a1));
        $this->get('/admin/reviews?created_from=2026-10-17&created_to=2026-10-17')->assertInertia(fn (Assert $page) => $page->where('reviews.total', 3));
        $this->get('/admin/reviews?created_from=2026-10-18')->assertInertia(fn (Assert $page) => $page->where('reviews.total', 0));
        $this->get('/admin/reviews?search=%25')->assertInertia(fn (Assert $page) => $page->where('reviews.total', 0));
        foreach (['status=live', 'rating=6', 'verified=maybe', 'sort=body;DROP', 'per_page=7', 'created_from=17-10-2026', 'product=x'] as $invalid) {
            $this->get('/admin/reviews?'.$invalid)->assertSessionHasErrors();
        }
        $this->get('/admin/reviews?created_from=2026-10-18&created_to=2026-10-01')->assertSessionHasErrors('created_to');
        foreach (range(1, 25) as $n) {
            app(ProductReviews::class)->submit((string) $this->customer('Page'.$n), (string) $this->ids['D'], ['rating' => 3, 'body' => 'Bulk '.$n]);
        }
        $this->get('/admin/reviews?rating=3&product='.$this->ids['D'])->assertInertia(fn (Assert $page) => $page->where('reviews.total', 25)->has('reviews.data', 25)->where('reviews.last_page', 1));
        $this->get('/admin/reviews?status=pending')->assertInertia(fn (Assert $page) => $page->where('reviews.total', 27)->has('reviews.data', 25)->where('reviews.last_page', 2));
        $this->get('/admin/reviews?status=pending&page=2')->assertInertia(fn (Assert $page) => $page->has('reviews.data', 2));
    }

    public function test_customer_email_is_shown_and_searchable_only_with_customer_access(): void
    {
        $this->submit('c1', 'A');
        $this->actingAs($this->staff(['admin.access', 'reviews.view']));
        $this->get('/admin/reviews')->assertInertia(fn (Assert $page) => $page->where('reviews.data.0.customer.email', null)->where('reviews.data.0.customer.name', 'C1 Customer'));
        $this->get('/admin/reviews?search=c1@example')->assertInertia(fn (Assert $page) => $page->where('reviews.total', 0));
    }

    public function test_permissions_are_enforced_on_the_server(): void
    {
        $id = $this->submit('c1', 'A');
        session()->forget('password_hash_web');
        $this->actingAs($this->staff(['admin.access', 'products.view']));
        $this->get('/admin/reviews')->assertForbidden();
        $this->get('/admin/reviews/'.$id.'/edit')->assertForbidden();
        $this->post('/admin/reviews/'.$id.'/status', ['version' => 1, 'status' => 'approved'])->assertForbidden();

        session()->forget('password_hash_web');
        $viewer = $this->staff(['admin.access', 'reviews.view']);
        $this->actingAs($viewer);
        $this->get('/admin/reviews')->assertOk()->assertInertia(fn (Assert $page) => $page->where('navigation', fn ($items) => collect($items)->contains('id', 'core.reviews')));
        $this->get('/admin/reviews/'.$id.'/edit')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Reviews/Edit')->where('review.transitions', ['approved', 'rejected', 'spam']));
        $this->get('/admin/reviews/999999/edit')->assertNotFound();
        $this->post('/admin/reviews/'.$id.'/status', ['version' => 1, 'status' => 'approved'])->assertForbidden();
        $this->post('/admin/reviews/bulk', ['ids' => [$id], 'status' => 'approved'])->assertForbidden();
        $this->patch('/admin/reviews/'.$id, ['version' => 1, 'rating' => 1])->assertForbidden();
        $this->patch('/admin/reviews/'.$id, ['version' => 1, 'moderation_note' => 'x'])->assertForbidden();
        $this->delete('/admin/reviews/'.$id, ['version' => 1])->assertForbidden();

        session()->forget('password_hash_web');
        $this->actingAs($this->staff(['admin.access', 'reviews.view', 'reviews.moderate']));
        $this->patch('/admin/reviews/'.$id, ['version' => 1, 'rating' => 1])->assertForbidden();
        $this->patch('/admin/reviews/'.$id, ['version' => 1, 'moderation_note' => 'Checked with the courier'])->assertSessionHasNoErrors();

        session()->forget('password_hash_web');
        $this->actingAs($this->staff(['admin.access', 'reviews.view', 'reviews.edit']));
        $this->patch('/admin/reviews/'.$id, ['version' => 2, 'moderation_note' => 'x'])->assertForbidden();
        $this->post('/admin/reviews/'.$id.'/status', ['version' => 2, 'status' => 'approved'])->assertForbidden();
        $this->patch('/admin/reviews/'.$id, ['version' => 2, 'rating' => 1])->assertSessionHasNoErrors();

        // The services authorize their own actor too, whatever the entry point.
        $this->expectException(AuthorizationException::class);
        app(ProductReviews::class)->moderate((string) $viewer->id, $id, 3, 'approved');
    }

    public function test_staff_corrections_are_versioned_and_keep_status(): void
    {
        $id = $this->submit('c1', 'A', ['title' => 'Old']);
        $this->approve($id);
        $this->patch('/admin/reviews/'.$id, ['version' => 2, 'rating' => 3, 'title' => '', 'body' => ' Corrected text ', 'moderation_note' => ' Removed a phone number ', 'status' => 'pending', 'verified_purchase' => true, 'return_to' => '/admin/reviews?status=approved'])
            ->assertSessionHasNoErrors()->assertRedirect('/admin/reviews/'.$id.'/edit?return_to=%2Fadmin%2Freviews%3Fstatus%3Dapproved');
        $row = $this->review($id);
        self::assertSame(['approved', 3, null, 'Corrected text', 'Removed a phone number', (int) $this->actor->id, false, 3], [$row->status, (int) $row->rating, $row->title, $row->body, $row->moderation_note, (int) $row->edited_by, (bool) $row->verified_purchase, (int) $row->lock_version]);
        $this->patch('/admin/reviews/'.$id, ['version' => 2, 'body' => 'Stale'])->assertSessionHasErrors('version');
        foreach ([['rating' => 0], ['rating' => 2.5], ['body' => '  '], ['title' => '...'], ['moderation_note' => str_repeat('n', 2001)]] as $invalid) {
            $this->patch('/admin/reviews/'.$id, ['version' => 3, ...$invalid])->assertSessionHasErrors();
        }
        $this->patch('/admin/reviews/'.$id, ['version' => 3])->assertSessionHasErrors('review');
        // A note-only change is not a content correction; an unchanged save writes nothing.
        $this->patch('/admin/reviews/'.$id, ['version' => 3, 'moderation_note' => ''])->assertSessionHasNoErrors();
        $this->patch('/admin/reviews/'.$id, ['version' => 4, 'rating' => 3, 'body' => 'Corrected text'])->assertSessionHasNoErrors();
        $row = $this->review($id);
        self::assertSame([null, 4], [$row->moderation_note, (int) $row->lock_version]);
        $this->get('/admin/reviews/'.$id.'/edit?return_to=https://evil.test')->assertInertia(fn (Assert $page) => $page->where('returnTo', '/admin/reviews')->where('review.body', 'Corrected text')->where('review.editor_name', $this->actor->name)
            ->where('review.product_rating.count', 1)->where('review.product_rating.average', '3.0'));
    }

    public function test_bulk_moderation_processes_each_review_and_reports_refusals(): void
    {
        $pending = $this->submit('c1', 'A');
        $approved = $this->submit('c2', 'A');
        $this->approve($approved);
        $spam = $this->submit('c3', 'A');
        app(ProductReviews::class)->moderate((string) $this->actor->id, $spam, 1, 'spam');
        $this->post('/admin/reviews/bulk', ['ids' => [$pending, $approved, $spam, 999999], 'status' => 'approved', 'return_to' => '/admin/reviews?status=pending'])->assertRedirect('/admin/reviews?status=pending')
            ->assertSessionHas('review_bulk_results', fn (array $results): bool => array_column($results, 'success', 'id') === [$pending => true, $approved => false, $spam => false, '999999' => false]);
        self::assertSame(['approved', 'approved', 'spam'], [$this->review($pending)->status, $this->review($approved)->status, $this->review($spam)->status]);
        $this->post('/admin/reviews/bulk', ['ids' => [$pending, $approved], 'status' => 'spam'])->assertSessionHasNoErrors();
        self::assertSame(['spam', 'spam'], [$this->review($pending)->status, $this->review($approved)->status]);
        $this->post('/admin/reviews/bulk', ['ids' => [$pending], 'status' => 'pending'])->assertSessionHasErrors('status');
        $this->post('/admin/reviews/bulk', ['ids' => range(1, 101), 'status' => 'approved'])->assertSessionHasErrors('ids');
        $this->post('/admin/reviews/bulk', ['ids' => [], 'status' => 'approved'])->assertSessionHasErrors('ids');
        self::assertSame(1, DB::table('audit_logs')->where('action', 'review.bulk_completed')->where('status', 'failure')->count());
    }

    public function test_deletion_needs_the_current_version_and_is_audited_without_text(): void
    {
        $id = $this->submit('c1', 'A', ['title' => 'Private title', 'body' => 'Private body']);
        $this->delete('/admin/reviews/'.$id, ['version' => 9])->assertSessionHasErrors('version');
        $this->delete('/admin/reviews/'.$id, ['version' => 1, 'return_to' => '/admin/reviews?rating=4'])->assertRedirect('/admin/reviews?rating=4');
        self::assertSame(0, DB::table('product_reviews')->count());
        $log = DB::table('audit_logs')->where('action', 'review.deleted')->sole();
        self::assertSame((string) $this->actor->id, (string) $log->actor_id);
        self::assertStringNotContainsString('Private', $log->changes);
        self::assertStringContainsString('"rating"', $log->changes);
    }

    public function test_database_enforces_ratings_statuses_and_text(): void
    {
        $base = ['product_id' => $this->ids['A'], 'customer_id' => $this->ids['c1'], 'rating' => 3, 'body' => 'Ok', 'created_at' => now(), 'updated_at' => now()];
        foreach ([['rating' => 0], ['rating' => 6], ['status' => 'live'], ['body' => '  '], ['title' => ' '], ['status' => 'approved'], ['customer_id' => 999999]] as $invalid) {
            try {
                DB::transaction(fn () => DB::table('product_reviews')->insert([...$base, ...$invalid]));
                self::fail('Inserted '.json_encode($invalid));
            } catch (QueryException $e) {
                self::assertContains($e->getCode(), ['23514', '23503']);
            }
        }
        self::assertSame(0, DB::table('product_reviews')->count());
    }

    public function test_guests_cannot_reach_review_administration(): void
    {
        $id = $this->submit('c1', 'A');
        auth()->logout();
        $this->get('/admin/reviews')->assertRedirect('/admin/login');
        $this->post('/admin/reviews/'.$id.'/status', ['version' => 1, 'status' => 'approved'])->assertRedirect('/admin/login');
        self::assertSame('pending', $this->review($id)->status);
    }
}
