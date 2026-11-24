<?php

namespace Tests\Feature;

use App\Catalog\Application\Command\ManageCategoriesHandler;
use App\Catalog\Application\Command\SaveCategoryData;
use App\Catalog\Application\Command\SaveProductData;
use App\Catalog\Application\Contract\ProductAdministration;
use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\Persistence\LocaleRecord;
use App\Website\Application\Command\SaveFaqData;
use App\Website\Infrastructure\FaqAdministration;
use App\Website\Infrastructure\FaqDemoSeeder;
use App\Website\Infrastructure\FaqLinks;
use App\Website\Infrastructure\PageAdministration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class FaqTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private UserRecord $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        LocaleRecord::where('code', '<>', 'en')->update(['is_active' => false]);
        $this->actor = $this->staff(super: true);
        $this->actingAs($this->actor);
    }

    public function test_four_sample_faqs_are_typed_localized_and_idempotent_without_overwriting_edits(): void
    {
        LocaleRecord::where('code', 'el')->update(['is_active' => true]);
        $seeder = app(FaqDemoSeeder::class);
        self::assertSame(4, $seeder->run((string) $this->actor->id));
        self::assertSame(2, DB::table('faqs')->where('type', 'general')->count());
        self::assertSame(1, DB::table('faqs')->where('type', 'category')->count());
        self::assertSame(1, DB::table('faqs')->where('type', 'product')->count());
        self::assertSame(8, DB::table('faq_translations')->count());
        $id = (string) DB::table('feature_demo_records')->where('key', 'faqs.sample.1')->value('entity_id');
        DB::table('faq_translations')->where('faq_id', $id)->update(['question' => 'Merchant edited question']);
        self::assertSame(0, $seeder->run((string) $this->actor->id));
        self::assertSame('Merchant edited question', DB::table('faq_translations')->where('faq_id', $id)->value('question'));
        self::assertSame(0, DB::table('product_faqs')->count());
        $this->app['env'] = 'production';
        $this->expectException(\RuntimeException::class);
        $seeder->run((string) $this->actor->id);
    }

    public function test_ai_log_navigation_is_in_logs_with_its_own_capability_with_its_own_capability(): void
    {
        $this->get('/admin/settings/ai-log')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Settings/AiLog')
            ->where('navigation', function ($entries): bool {
                $log = collect($entries)->firstWhere('id', 'core.ai-log');
                $audit = collect($entries)->firstWhere('id', 'core.audit-log');

                return $log['group']['id'] === 'core.logs' && $audit['group']['id'] === 'core.logs' && $log['active'] && ! $audit['active'];
            }));
        $this->flushSession();
        $viewer = $this->staff(['admin.access', 'ai.logs.view']);
        $this->actingAs($viewer)->get('/admin/settings/ai-log')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('navigation', function ($entries): bool {
                return collect($entries)->contains('id', 'core.ai-log') && ! collect($entries)->contains('id', 'core.audit-log');
            }));
        $this->get('/admin/faqs')->assertForbidden();
    }

    private function faq(string $type = 'general', string $question = 'How can I contact you?'): string
    {
        return app(FaqAdministration::class)->save((string) $this->actor->id, null, new SaveFaqData($type, 'active', 0, ['en' => ['question' => $question, 'answer' => '<p>Contact our support team.</p>']]));
    }

    private function category(string $name = 'Shoes'): string
    {
        return app(ManageCategoriesHandler::class)->save((string) $this->actor->id, null, new SaveCategoryData(null, 1, 'active', ['en' => ['name' => $name, 'slug' => strtolower($name), 'description' => '', 'seo_title' => '', 'seo_description' => '', 'image_alt' => '']]));
    }

    /** @param array<string, mixed> $extra */
    private function page(array $extra = [], ?string $id = null, ?int $version = null): string
    {
        return app(PageAdministration::class)->save((string) $this->actor->id, $id, $version, ['position' => 0, 'status' => 'active', 'translations' => ['en' => ['name' => 'Help', 'content' => '<p>Store information.</p>']], ...$extra]);
    }

    public function test_manual_faqs_are_localized_sanitized_versioned_and_audited(): void
    {
        LocaleRecord::where('code', 'el')->update(['is_active' => true]);
        $input = ['type' => 'general', 'status' => 'active', 'position' => 5, 'translations' => ['en' => ['question' => 'How do I contact you?', 'answer' => '<p onclick="bad()">Support <script>bad()</script></p>'], 'el' => ['question' => 'Πώς επικοινωνώ;', 'answer' => '<p>Υποστήριξη.</p>']]];
        $this->post('/admin/faqs', $input)->assertSessionHasNoErrors()->assertRedirect();
        $id = (string) DB::table('faqs')->sole()->id;
        self::assertSame('<p>Support </p>', DB::table('faq_translations')->where('faq_id', $id)->where('locale_id', LocaleRecord::where('code', 'en')->sole()->id)->value('answer'));
        $this->put('/admin/faqs/'.$id, ['version' => 1, ...$input, 'position' => 8])->assertSessionHasNoErrors();
        $this->put('/admin/faqs/'.$id, ['version' => 1, ...$input])->assertSessionHasErrors('version');
        $this->get('/admin/faqs?search=contact&type=general')->assertInertia(fn (Assert $page) => $page->where('faqs.data.0.id', $id)->where('faqs.data.0.position', 8));
        self::assertTrue(DB::table('audit_logs')->where('entity_type', 'faq')->exists());
        self::assertTrue(DB::table('audit_logs')->where('entity_type', 'faq_translation')->exists());
    }

    public function test_every_active_language_is_required_and_inactive_languages_and_empty_sanitized_answers_are_rejected(): void
    {
        LocaleRecord::where('code', 'el')->update(['is_active' => true]);
        $input = ['type' => 'general', 'status' => 'active', 'position' => 0, 'translations' => ['en' => ['question' => 'Question?', 'answer' => 'Answer']]];
        $this->post('/admin/faqs', $input)->assertSessionHasErrors('translations.el');
        $input['translations']['el'] = ['question' => 'Ερώτηση;', 'answer' => 'Απάντηση'];
        $input['translations']['fr'] = ['question' => 'Question?', 'answer' => 'Réponse'];
        $this->post('/admin/faqs', $input)->assertSessionHasErrors('translations');
        unset($input['translations']['fr']);
        $input['translations']['en']['answer'] = '<script>alert(1)</script>';
        $this->post('/admin/faqs', $input)->assertSessionHasErrors('translations.en.answer');
        self::assertSame(0, DB::table('faqs')->count());
    }

    public function test_typed_links_keep_order_and_old_saves_preserve_them(): void
    {
        $category = $this->category();
        $general = $this->faq();
        $specific = $this->faq('category', 'How do I choose shoes?');
        $record = CategoryRecord::findOrFail($category);
        $data = new SaveCategoryData(null, 1, 'active', [], $record->lock_version, faqIds: [$specific, $general]);
        app(ManageCategoriesHandler::class)->save((string) $this->actor->id, $category, $data);
        self::assertSame([$specific, $general], app(FaqLinks::class)->assigned('category', $category));
        $this->get('/admin/categories/'.$category.'/edit')->assertInertia(fn (Assert $page) => $page->where('category.faqIds', [$specific, $general])->where('faqOptions', fn ($rows) => count($rows) === 2));
        app(ManageCategoriesHandler::class)->save((string) $this->actor->id, $category, new SaveCategoryData(null, 1, 'active', [], 2));
        self::assertSame([$specific, $general], app(FaqLinks::class)->assigned('category', $category));
        app(ManageCategoriesHandler::class)->save((string) $this->actor->id, $category, new SaveCategoryData(null, 1, 'active', [], 3, faqIds: [$general, $specific]));
        self::assertSame([$general, $specific], app(FaqLinks::class)->assigned('category', $category));
        app(ManageCategoriesHandler::class)->save((string) $this->actor->id, $category, new SaveCategoryData(null, 1, 'active', [], 4, faqIds: []));
        self::assertSame([], app(FaqLinks::class)->assigned('category', $category));
    }

    public function test_product_editor_links_are_versioned_and_duplication_reuses_the_same_faqs(): void
    {
        $category = $this->category();
        $faq = $this->faq('product');
        $products = app(ProductAdministration::class);
        $id = $products->draft((string) $this->actor->id);
        $fields = ['version' => 1, 'sku' => 'FAQ-SKU', 'type' => 'physical', 'status' => 'disabled', 'price' => '10', 'category_id' => $category, 'translations' => ['en' => ['name' => 'Shoes']], 'faq_ids' => [$faq]];
        $this->post('/admin/products/'.$id, $fields)->assertRedirect()->assertSessionHasNoErrors();
        self::assertSame([$faq], app(FaqLinks::class)->assigned('product', $id));
        $this->get('/admin/products/'.$id.'/edit')->assertInertia(fn (Assert $page) => $page->where('product.faq_ids', [$faq]));
        $copy = $products->duplicate((string) $this->actor->id, $id, 2);
        self::assertSame([$faq], app(FaqLinks::class)->assigned('product', $copy));
        $this->post('/admin/products/'.$id, [...$fields, 'faq_ids' => []])->assertSessionHasErrors('version');
        self::assertSame([$faq], app(FaqLinks::class)->assigned('product', $id));
        unset($fields['faq_ids']);
        $products->save((string) $this->actor->id, $id, new SaveProductData(2, [...$fields, 'version' => 2]));
        self::assertSame([$faq], app(FaqLinks::class)->assigned('product', $id));
    }

    public function test_pages_link_general_faqs_and_reject_category_or_product_faqs_atomically(): void
    {
        $general = $this->faq();
        $specific = $this->faq('product');
        $page = $this->page(['faq_ids' => [$general]]);
        self::assertSame([$general], app(FaqLinks::class)->assigned('page', $page));
        try {
            $this->page(['faq_ids' => [$specific]], $page, 1);
            self::fail('A product FAQ cannot be linked to a CMS page');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('faq_ids', $exception->errors());
        }
        self::assertSame([$general], app(FaqLinks::class)->assigned('page', $page));
        self::assertSame(1, DB::table('pages')->where('id', $page)->value('lock_version'));
        $this->page([], $page, 1);
        self::assertSame([$general], app(FaqLinks::class)->assigned('page', $page));
    }

    public function test_reviewed_ai_drafts_are_saved_with_the_owner_and_invalid_drafts_roll_back_everything(): void
    {
        $draft = ['translations' => ['en' => ['question' => 'What are your contact details?', 'answer' => '<p>Contact support.</p>']]];
        $page = $this->page(['faq_ids' => [], 'faq_drafts' => [$draft]]);
        self::assertSame(1, DB::table('faqs')->count());
        self::assertCount(1, app(FaqLinks::class)->assigned('page', $page));
        try {
            $this->page(['faq_ids' => [], 'faq_drafts' => [$draft, ['translations' => ['en' => ['question' => 'Missing answer?', 'answer' => '']]]]], $page, 1);
            self::fail('Invalid reviewed entries must roll back the whole save');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('faq_drafts.1.translations.en.answer', $exception->errors());
        }
        self::assertSame(1, DB::table('faqs')->count());
        self::assertCount(1, app(FaqLinks::class)->assigned('page', $page));
    }

    public function test_linked_faqs_cannot_change_type_or_be_deleted_and_disabled_links_are_preserved(): void
    {
        $faq = $this->faq();
        $page = $this->page(['faq_ids' => [$faq]]);
        $input = ['version' => 1, 'type' => 'product', 'status' => 'active', 'position' => 0, 'translations' => ['en' => ['question' => 'Question?', 'answer' => 'Answer']]];
        $this->put('/admin/faqs/'.$faq, $input)->assertSessionHasErrors('type');
        $this->delete('/admin/faqs/'.$faq, ['version' => 1])->assertSessionHasErrors('faq');
        $this->put('/admin/faqs/'.$faq, [...$input, 'type' => 'general', 'status' => 'disabled'])->assertSessionHasNoErrors();
        $this->page(['faq_ids' => [$faq]], $page, 1);
        self::assertSame([$faq], app(FaqLinks::class)->assigned('page', $page));
        $this->post('/admin/pages', ['position' => 0, 'status' => 'active', 'translations' => ['en' => ['name' => 'New page']], 'faq_ids' => [$faq]])->assertSessionHasErrors('faq_ids');
    }

    public function test_permissions_protect_content_and_linking_without_preventing_ordinary_owner_saves(): void
    {
        $faq = $this->faq();
        $this->actingAs($this->staff(['admin.access', 'pages.manage']));
        $this->get('/admin/faqs')->assertForbidden();
        $this->post('/admin/faqs', [])->assertForbidden();
        $this->post('/admin/pages', ['position' => 0, 'status' => 'active', 'translations' => ['en' => ['name' => 'Unlinked page']]])->assertSessionHasNoErrors();
        $this->post('/admin/pages', ['position' => 0, 'status' => 'active', 'translations' => ['en' => ['name' => 'Linked page']], 'faq_ids' => [$faq]])->assertForbidden();
    }

    public function test_postgres_rejects_wrong_types_and_restricts_linked_faq_deletion(): void
    {
        $category = $this->category();
        $faq = $this->faq('product');
        DB::beginTransaction();
        try {
            DB::table('category_faqs')->insert(['category_id' => $category, 'faq_id' => $faq, 'faq_type' => 'category', 'position' => 1]);
            self::fail('Composite FAQ type ownership must be enforced');
        } catch (QueryException $exception) {
            self::assertSame('23503', $exception->getCode());
        } finally {
            DB::rollBack();
        }
        $general = $this->faq();
        $this->page(['faq_ids' => [$general]]);
        DB::beginTransaction();
        try {
            DB::table('faqs')->where('id', $general)->delete();
            self::fail('Linked FAQs must not be deleted');
        } catch (QueryException $exception) {
            self::assertSame('23001', $exception->getCode());
        } finally {
            DB::rollBack();
        }
    }
}
