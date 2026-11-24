<?php

namespace Tests\Feature;

use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Website\Infrastructure\PageAdministration;
use App\Website\Infrastructure\PageContentSanitizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class PageTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private UserRecord $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actor = $this->staff(super: true);
        $this->actingAs($this->actor);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'parent_id' => '', 'position' => 10, 'status' => 'active', 'created_on' => '2026-10-01', 'facebook_type' => 'website',
            'translations' => [
                'en' => ['name' => 'Privacy policy', 'content' => '<h1>Privacy Policy</h1><p>What we collect.</p>', 'seo_title' => 'Privacy', 'meta_description' => 'How we use data', 'meta_keywords' => 'privacy, gdpr'],
                'el' => ['name' => 'Πολιτική Απορρήτου', 'content' => '<p>Τι συλλέγουμε.</p>'],
            ],
        ], $overrides);
    }

    private function create(array $overrides = []): string
    {
        $this->post('/admin/pages', $this->payload($overrides))->assertSessionHasNoErrors()->assertRedirect();

        return (string) DB::table('pages')->max('id');
    }

    private function translation(string $page, string $code): object
    {
        return DB::table('page_translations')->where('page_id', $page)->where('locale_id', DB::table('locales')->where('code', $code)->value('id'))->first();
    }

    public function test_a_page_is_created_with_generated_seo_names_sanitized_content_and_projected_for_editing(): void
    {
        $id = $this->create(['translations' => ['en' => ['content' => '<h1 style="text-align:center">Privacy</h1><p onclick="x()">Text<script>alert(1)</script></p><iframe src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ"></iframe><iframe src="https://evil.example"></iframe><table><tbody><tr><td colspan="2">A</td></tr></tbody></table>']]]);
        $en = $this->translation($id, 'en');
        self::assertSame('privacy-policy', $en->slug);
        self::assertSame('politiki-aporritoy', $this->translation($id, 'el')->slug);
        self::assertStringContainsString('<h1 style="text-align: center">Privacy</h1>', $en->content);
        self::assertStringContainsString('youtube-nocookie.com/embed/dQw4w9WgXcQ', $en->content);
        self::assertStringNotContainsString('script', $en->content);
        self::assertStringNotContainsString('onclick', $en->content);
        self::assertStringNotContainsString('evil.example', $en->content);
        self::assertStringContainsString('<td colspan="2">A</td>', $en->content);

        $this->get('/admin/pages/'.$id.'/edit')->assertInertia(fn (Assert $p) => $p->component('Admin/Pages/Form')
            ->where('page.translations.el.name', 'Πολιτική Απορρήτου')->where('page.translations.en.seo_title', 'Privacy')
            ->where('page.state', 'live')->where('facebookTypes.websites.0', 'website')->has('tree', 1));
        // A second page with the same name gets a unique SEO name; an explicit duplicate is refused.
        $second = $this->create();
        self::assertSame('privacy-policy-2', $this->translation($second, 'en')->slug);
        $this->put('/admin/pages/'.$second, [...$this->payload(['translations' => ['en' => ['slug' => 'privacy-policy']]]), 'version' => 1])->assertSessionHasErrors('translations.en.slug');
        $this->put('/admin/pages/'.$second, [...$this->payload(['translations' => ['en' => ['slug' => 'returns']]]), 'version' => 1])->assertSessionHasNoErrors();
        self::assertSame('returns', $this->translation($second, 'en')->slug);
        $this->put('/admin/pages/'.$second, [...$this->payload(), 'version' => 1])->assertSessionHasErrors('version');
    }

    public function test_validation_tree_rules_and_database_constraints(): void
    {
        $this->post('/admin/pages', $this->payload(['translations' => ['en' => ['name' => '']]]))->assertSessionHasErrors('translations.en.name');
        $this->post('/admin/pages', $this->payload(['translations' => ['el' => ['name' => '', 'content' => '<p>orphan text</p>']]]))->assertSessionHasErrors('translations.el.name');
        $this->post('/admin/pages', $this->payload(['translations' => ['fr' => ['name' => 'Inactive']]]))->assertSessionHasErrors('translations');
        $this->post('/admin/pages', $this->payload(['translations' => ['en' => ['slug' => 'Not Valid']]]))->assertSessionHasErrors('translations.en.slug');
        $this->post('/admin/pages', $this->payload(['status' => 'archived']))->assertSessionHasErrors('status');
        $this->post('/admin/pages', $this->payload(['facebook_type' => 'spaceship']))->assertSessionHasErrors('facebook_type');
        $this->post('/admin/pages', $this->payload(['parent_id' => 999999]))->assertSessionHasErrors('parent_id');
        $this->post('/admin/pages', $this->payload(['available' => '1', 'available_from' => '2026-11-28T00:00:00+02:00', 'available_until' => '2026-11-27T00:00:00+02:00']))->assertSessionHasErrors('available_until');
        self::assertSame(0, DB::table('pages')->count());

        $parent = $this->create(['translations' => ['en' => ['name' => 'Company'], 'el' => ['name' => 'Η εταιρεία']]]);
        $child = $this->create(['parent_id' => $parent, 'translations' => ['en' => ['name' => 'Our team'], 'el' => ['name' => 'Η ομάδα μας']]]);
        $grandchild = $this->create(['parent_id' => $child, 'translations' => ['en' => ['name' => 'Careers'], 'el' => ['name' => 'Καριέρα']]]);
        // A page cannot move under itself or its own descendants.
        $this->put('/admin/pages/'.$parent, [...$this->payload(['parent_id' => $grandchild]), 'version' => 1])->assertSessionHasErrors('parent_id');
        $this->put('/admin/pages/'.$parent, [...$this->payload(['parent_id' => $parent]), 'version' => 1])->assertSessionHasErrors('parent_id');
        $this->get('/admin/pages')->assertInertia(fn (Assert $p) => $p->where('pages.0.id', $parent)->where('pages.1.depth', 1)->where('pages.2.id', $grandchild)->where('pages.2.depth', 2)->where('pages.0.children', 1));
        // Deleting a page with sub-pages is refused; bulk delete of the whole branch works leaves-first.
        $this->delete('/admin/pages/'.$parent, ['version' => 1])->assertSessionHasErrors('page');
        $this->post('/admin/pages/bulk', ['ids' => [$parent], 'action' => 'delete'])->assertSessionHasErrors('page');
        $this->post('/admin/pages/bulk', ['ids' => [$child, $grandchild], 'action' => 'hidden'])->assertSessionHasNoErrors();
        self::assertSame(2, DB::table('pages')->where('status', 'hidden')->count());
        $this->post('/admin/pages/bulk', ['ids' => [$parent, $child, $grandchild], 'action' => 'delete'])->assertSessionHasNoErrors();
        self::assertSame(0, DB::table('pages')->count());
        self::assertTrue(DB::table('audit_logs')->where('entity_type', 'page')->where('action', 'page.created')->exists());

        $this->expectException(QueryException::class);
        $id = DB::table('pages')->insertGetId(['status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('page_translations')->insert(['page_id' => $id, 'locale_id' => DB::table('locales')->where('code', 'en')->value('id'), 'name' => 'X', 'slug' => 'Bad Slug']);
    }

    public function test_availability_states_and_customer_groups(): void
    {
        $id = $this->create(['status' => 'hidden', 'available' => '1', 'available_from' => '2026-11-27T00:00:00+02:00', 'available_until' => '2026-11-28T00:00:00+02:00']);
        $this->travelTo(now()->parse('2026-11-26T21:00:00Z'));
        $this->get('/admin/pages')->assertInertia(fn (Assert $p) => $p->where('pages.0.state', 'scheduled'));
        $this->travelTo(now()->parse('2026-11-27T10:00:00Z'));
        $this->get('/admin/pages')->assertInertia(fn (Assert $p) => $p->where('pages.0.state', 'hidden'));
        $this->travelTo(now()->parse('2026-11-27T22:00:00Z'));
        $this->get('/admin/pages/'.$id.'/edit')->assertInertia(fn (Assert $p) => $p->where('page.state', 'expired')->where('page.available_from', '2026-11-26T22:00:00Z'));
        $this->put('/admin/pages/'.$id, [...$this->payload(['customer_groups' => [999]]), 'version' => 1])->assertSessionHasErrors('customer_groups');
    }

    /** @return array<string, mixed> */
    private function form(array $overrides = []): array
    {
        return array_replace_recursive([
            'enabled' => '1', 'placement' => 'after', 'subject_mode' => 'field', 'subject_field' => 'f-name', 'recipients' => 'info@example.test, Sales@Example.test',
            'translations' => ['en' => ['submit_text' => '<p>Thank you!</p><script>x</script>', 'custom_subject' => ''], 'el' => ['submit_text' => '<p>Ευχαριστούμε!</p>']],
            'fields' => [
                ['key' => 'f-name', 'position' => 10, 'type' => 'input', 'required' => '1', 'status' => 'active', 'names' => ['en' => 'Name', 'el' => 'Όνομα']],
                ['key' => 'f-phone', 'position' => 30, 'type' => 'phone', 'required' => '1', 'status' => 'active', 'names' => ['en' => 'Phone', 'el' => 'Τηλέφωνο']],
                ['key' => 'f-topic', 'position' => 40, 'type' => 'select', 'required' => '0', 'status' => 'active', 'names' => ['en' => 'Topic', 'el' => 'Θέμα'], 'variants' => [
                    ['position' => 10, 'labels' => ['en' => 'Order', 'el' => 'Παραγγελία']], ['position' => 20, 'labels' => ['en' => 'Other', 'el' => 'Άλλο']], ['position' => 30, 'labels' => ['en' => '']],
                ]],
                ['key' => 'f-line', 'position' => 50, 'type' => 'separator', 'required' => '1', 'status' => 'active', 'names' => []],
            ],
        ], $overrides);
    }

    public function test_a_contact_form_is_built_on_the_page_and_edited_in_place(): void
    {
        $id = $this->create(['form' => $this->form()]);
        $form = DB::table('page_forms')->where('page_id', $id)->first();
        self::assertSame([true, 'after', 'field', 'info@example.test, sales@example.test'], [$form->enabled, $form->placement, $form->subject_mode, $form->recipients]);
        $fields = DB::table('page_form_fields')->where('page_id', $id)->orderBy('position')->get();
        self::assertSame(['input', 'phone', 'select', 'separator'], $fields->pluck('type')->all());
        self::assertSame((int) $fields[0]->id, (int) $form->subject_field_id);
        self::assertFalse((bool) $fields[3]->required);
        self::assertSame(2, DB::table('page_form_variants')->where('field_id', $fields[2]->id)->count());
        self::assertSame('<p>Thank you!</p>', DB::table('page_form_translations')->where('page_id', $id)->where('locale_id', DB::table('locales')->where('code', 'en')->value('id'))->value('submit_text'));

        $this->get('/admin/pages/'.$id.'/edit')->assertInertia(fn (Assert $p) => $p->where('page.form.subject_field', (string) $fields[0]->id)
            ->where('page.form.fields.2.variants.1.labels.el', 'Άλλο')->where('page.form.fields.0.names.el', 'Όνομα')->where('page.form.translations.el.submit_text', '<p>Ευχαριστούμε!</p>'));

        // Editing keeps field identities, removes dropped fields, and the subject can switch to a custom text.
        $edited = $this->form(['subject_mode' => 'custom', 'subject_field' => '', 'translations' => ['en' => ['custom_subject' => 'New contact message']], 'fields' => [
            ['key' => (string) $fields[0]->id, 'id' => (string) $fields[0]->id, 'position' => 10, 'type' => 'input', 'required' => '1', 'status' => 'active', 'names' => ['en' => 'Full name']],
            ['key' => 'f-msg', 'position' => 20, 'type' => 'textarea', 'required' => '0', 'status' => 'active', 'names' => ['en' => 'Message']],
        ]]);
        unset($edited['fields'][2], $edited['fields'][3]);
        $this->put('/admin/pages/'.$id, [...$this->payload(), 'form' => $edited, 'version' => 1])->assertSessionHasNoErrors();
        self::assertSame([(int) $fields[0]->id], DB::table('page_form_fields')->where('page_id', $id)->where('type', 'input')->pluck('id')->map(fn ($v) => (int) $v)->all());
        self::assertSame(['input', 'textarea'], DB::table('page_form_fields')->where('page_id', $id)->orderBy('position')->pluck('type')->all());
        self::assertSame(0, DB::table('page_form_variants')->count());
        self::assertSame(['custom', null], [DB::table('page_forms')->where('page_id', $id)->value('subject_mode'), DB::table('page_forms')->where('page_id', $id)->value('subject_field_id')]);
        // Saving without the form part leaves the stored form untouched.
        $this->put('/admin/pages/'.$id, [...$this->payload(), 'version' => 2])->assertSessionHasNoErrors();
        self::assertSame(2, DB::table('page_form_fields')->where('page_id', $id)->count());
        self::assertTrue(DB::table('audit_logs')->where('entity_type', 'page_form_field')->exists());
    }

    public function test_form_definitions_are_validated(): void
    {
        $this->post('/admin/pages', $this->payload(['form' => $this->form(['recipients' => ''])]))->assertSessionHasErrors('form.recipients');
        $this->post('/admin/pages', $this->payload(['form' => $this->form(['recipients' => 'info@example.test, not-an-address'])]))->assertSessionHasErrors('form.recipients');
        $this->post('/admin/pages', $this->payload(['form' => $this->form(['fields' => [0 => ['names' => ['en' => '']]]])]))->assertSessionHasErrors('form.fields.0.names.en');
        $this->post('/admin/pages', $this->payload(['form' => $this->form(['fields' => [2 => ['variants' => [['labels' => ['en' => '', 'el' => '']], ['labels' => ['en' => '', 'el' => '']], ['labels' => ['en' => '', 'el' => '']]]]]])]))->assertSessionHasErrors('form.fields.2.variants');
        $this->post('/admin/pages', $this->payload(['form' => $this->form(['subject_field' => 'f-phone'])]))->assertSessionHasErrors('form.subject_field');
        $this->post('/admin/pages', $this->payload(['form' => $this->form(['subject_mode' => 'custom'])]))->assertSessionHasErrors('form.translations.en.custom_subject');
        $this->post('/admin/pages', $this->payload(['form' => $this->form(['placement' => 'marker'])]))->assertSessionHasErrors('form.placement');
        $this->post('/admin/pages', $this->payload(['form' => $this->form(['fields' => [0 => ['type' => 'script']]])]))->assertSessionHasErrors('form.fields.0.type');
        self::assertSame(0, DB::table('pages')->count());
        // With the [form] marker in the default-language content the marker placement is accepted.
        $this->post('/admin/pages', $this->payload(['translations' => ['en' => ['content' => '<p>Write to us.</p><p>[form]</p>']], 'form' => $this->form(['placement' => 'marker'])]))->assertSessionHasNoErrors();
        // A disabled form may be incomplete (no recipients yet).
        $this->post('/admin/pages', $this->payload(['translations' => ['en' => ['name' => 'Draft'], 'el' => ['name' => 'Πρόχειρο']], 'form' => $this->form(['enabled' => '0', 'recipients' => ''])]))->assertSessionHasNoErrors();
        self::assertSame(2, DB::table('page_forms')->count());
    }

    public function test_sample_pages_are_created_once_in_both_languages(): void
    {
        $this->artisan('cms:pages-demo', ['actor' => (string) $this->actor->id])->assertSuccessful();
        $this->artisan('cms:pages-demo', ['actor' => (string) $this->actor->id])->assertSuccessful();
        self::assertSame(5, DB::table('pages')->where('status', 'active')->count());
        // Each sample page is linked to a layout, so it opens at its address.
        self::assertSame(5, DB::table('storefront_layouts')->whereNotNull('page_id')->count());
        $this->get('/about-us')->assertOk();
        $this->get('/contact-us')->assertOk();
        $privacy = (string) DB::table('pages')->where('position', 10)->value('id');
        self::assertSame('privacy-policy', $this->translation($privacy, 'en')->slug);
        self::assertSame('Πολιτική Απορρήτου', $this->translation($privacy, 'el')->name);
        self::assertStringContainsString('<h2>', $this->translation($privacy, 'el')->content);
        $returns = (string) DB::table('pages')->where('position', 20)->value('id');
        self::assertStringContainsString('<table>', $this->translation($returns, 'en')->content);
        self::assertStringContainsString('href="tel:+302101234567"', $this->translation($returns, 'el')->content);
        $contact = (string) DB::table('pages')->where('position', 30)->value('id');
        $form = DB::table('page_forms')->where('page_id', $contact)->first();
        self::assertSame([true, 'after', 'field'], [$form->enabled, $form->placement, $form->subject_mode]);
        self::assertSame(['input', 'input', 'phone', 'email', 'select', 'textarea'], DB::table('page_form_fields')->where('page_id', $contact)->orderBy('position')->pluck('type')->all());
        self::assertSame('select', DB::table('page_form_fields')->where('id', $form->subject_field_id)->value('type'));
        self::assertSame(4, DB::table('page_form_variants')->where('field_id', $form->subject_field_id)->count());
    }

    public function test_view_only_staff_read_and_others_are_refused(): void
    {
        $id = $this->create();
        $this->flushSession();
        $this->actingAs($this->staff(['admin.access', 'pages.view']));
        $this->get('/admin/pages')->assertOk();
        $this->get('/admin/pages/'.$id.'/edit')->assertOk();
        $this->get('/admin/pages/create')->assertForbidden();
        $this->put('/admin/pages/'.$id, [...$this->payload(), 'version' => 1])->assertForbidden();
        $this->post('/admin/pages/bulk', ['ids' => [$id], 'action' => 'disabled'])->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->staff(['admin.access']));
        $this->get('/admin/pages')->assertForbidden();
        $this->expectException(AuthorizationException::class);
        app(PageAdministration::class)->bulk((string) $this->staff(['admin.access', 'pages.view'])->id, [$id], 'disabled');
    }

    public function test_content_policy_keeps_editor_markup_and_removes_unsafe_markup(): void
    {
        $sanitizer = new PageContentSanitizer;
        $html = $sanitizer->sanitize('<p style="text-align:right;position:absolute">R</p><span style="color:#ABC;font-size:90px">c</span><a href="/returns" target="_blank">r</a><a href="javascript:alert(1)">x</a><img src="https://cdn.example/a.png" alt="A" onerror="x"><img src="data:image/png;base64,AAA"><div data-youtube-video="evil"><iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ?start=10"></iframe></div><form action="/x"><input name="a"></form><style>body{}</style>');
        self::assertStringContainsString('<p style="text-align: right">R</p>', $html);
        self::assertStringContainsString('<span style="color: #abc">c</span>', $html);
        self::assertStringContainsString('<a href="/returns" target="_blank" rel="noopener noreferrer">r</a>', $html);
        self::assertStringContainsString('<a>x</a>', $html);
        self::assertStringContainsString('<img src="https://cdn.example/a.png" alt="A">', $html);
        self::assertStringNotContainsString('data:image', $html);
        self::assertStringContainsString('<div data-youtube-video=""><iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ?start=10"', $html);
        self::assertStringNotContainsString('<form', $html);
        self::assertStringNotContainsString('<style', $html);
    }
}
