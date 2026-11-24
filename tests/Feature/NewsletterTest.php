<?php

namespace Tests\Feature;

use App\Admin\Navigation\AdminNavigation;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Marketing\Infrastructure\NewsletterDelivery;
use App\Marketing\Infrastructure\NewsletterSubscribers;
use App\Media\Infrastructure\Persistence\MediaFileRecord;
use App\Order\Application\Contract\OrderMailTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\Fakes\RecordingMailTransport;
use Tests\TestCase;

/** Marketing → Newsletters: lists, campaigns, templates, autoresponders, newsletters, subscribers and delivery. */
final class NewsletterTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private UserRecord $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // Throttles are per user and shared by routes; this test makes many quick requests.
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actor = $this->staff(super: true);
        $this->actingAs($this->actor);
        DB::table('general_settings')->where('id', 1)->update(['order_email_from' => 'shop@store.example', 'order_email_from_name' => 'Test Store']);
        DB::table('company_settings')->where('id', 1)->update(['name' => 'Test Store', 'phone' => '+30 210 0000000']);
    }

    public function test_newsletter_template_and_autoresponder_emails_and_previews_use_the_email_logo_with_store_fallback(): void
    {
        Storage::fake('local');
        foreach (['store', 'email'] as $kind) {
            Storage::disk('local')->put("images/$kind.png", $kind);
            $media = MediaFileRecord::create(['disk' => 'local', 'storage_key' => "images/$kind.png", 'mime_type' => 'image/png', 'byte_size' => strlen($kind), 'width' => 1, 'height' => 1]);
            DB::table('company_settings')->where('id', 1)->update([$kind === 'store' ? 'logo_media_id' : 'logo_email_media_id' => $media->id]);
        }
        $delivery = app(NewsletterDelivery::class);
        foreach (['email', 'store'] as $expected) {
            foreach (['newsletter', 'template', 'autoresponder'] as $type) {
                $id = $this->content($type);
                $delivery->sendTest((string) $this->actor->id, $id, 'test@example.test', 'en');
                $mail = $this->mail()->sent[array_key_last($this->mail()->sent)];
                self::assertStringContainsString('src="cid:store-logo"', $mail->html);
                self::assertSame($expected, $mail->inline[0]['content']);
            }
            $preview = $delivery->preview((string) $this->actor->id, 'Subject', '<p>Body</p>', 'en');
            self::assertStringContainsString('data:image/png;base64,'.base64_encode($expected), $preview['html']);
            DB::table('company_settings')->where('id', 1)->update(['logo_email_media_id' => null]);
        }
        $auto = $this->content('autoresponder');
        $list = $this->list('Confirm', ['confirmation_id' => $auto]);
        $this->subscriber('confirm@example.test', [$list], false);
        $mail = $this->mail()->sent[array_key_last($this->mail()->sent)];
        self::assertSame('confirm@example.test', $mail->to);
        self::assertSame('store', $mail->inline[0]['content']);
    }

    public function test_confirmation_delivery_and_preview_share_invitation_branding_and_keep_signed_links(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('images/email.png', 'email-logo');
        $logo = MediaFileRecord::create(['disk' => 'local', 'storage_key' => 'images/email.png', 'mime_type' => 'image/png', 'byte_size' => 10, 'width' => 1, 'height' => 1]);
        DB::table('company_settings')->where('id', 1)->update(['logo_email_media_id' => $logo->id]);
        DB::table('storefront_theme_settings')->where('id', 1)->update(['primary_color' => '#123456', 'on_primary_color' => '#fafafa', 'text_color' => '#222222', 'background_color' => '#f4f4f4']);
        $body = '<h2>Our welcome</h2><p>Join us, {{subscriber_email}}</p><p>{{activation_link}}</p><p>{{company_name}} · {{company_address}}</p><p>{{unsubscribe_link}}</p>';
        $auto = $this->content('autoresponder', 'Welcome', ['translations' => [
            'en' => ['name' => 'Welcome', 'subject' => 'Please confirm', 'body_html' => $body],
            'el' => ['name' => 'Καλώς ήρθατε', 'subject' => 'Επιβεβαίωση', 'body_html' => $body],
        ]]);
        $list = $this->list('Weekly', ['confirmation_id' => $auto]);
        foreach (['en', 'el'] as $locale) {
            app(NewsletterSubscribers::class)->subscribe($locale.'@example.test', [$list], 'admin', false, $locale);
            $mail = $this->mail()->sent[array_key_last($this->mail()->sent)];
            foreach (['width="600"', 'font-family:Arial,Helvetica,sans-serif', 'border-top:6px solid #123456', 'color:#fafafa; background:#123456', 'Our welcome', 'Join us, '.$locale.'@example.test', __('newsletters.page_button_confirm', [], $locale), __('newsletters.page_button_unsubscribe', [], $locale)] as $expected) {
                self::assertStringContainsString($expected, $mail->html);
            }
            self::assertSame('email-logo', $mail->inline[0]['content']);
            preg_match('/href="([^"]*\/newsletter\/confirm\/[^\"]+)"/', $mail->html, $match);
            $confirmation = html_entity_decode($match[1]);
            self::assertStringContainsString($confirmation, $mail->text);
            self::assertStringNotContainsString(e($confirmation).'</a>', $mail->html, 'The visible action is a button label, not the raw URL');
            $this->get($confirmation)->assertOk();
            self::assertNull(DB::table('newsletter_list_subscribers')->where('subscriber_id', DB::table('newsletter_subscribers')->where('email', $locale.'@example.test')->value('id'))->value('confirmed_at'));
            $preview = $this->postJson('/admin/newsletters/preview', ['type' => 'autoresponder', 'locale' => $locale, 'subject' => 'Preview', 'body_html' => $body.'<script>bad()</script>'])->assertOk()->json('html');
            self::assertStringContainsString('border-top:6px solid #123456', $preview);
            self::assertStringContainsString('data:image/png;base64,'.base64_encode('email-logo'), $preview);
            self::assertStringContainsString(__('newsletters.page_button_confirm', [], $locale), $preview);
            self::assertStringNotContainsString('<script', $preview);
            app(NewsletterDelivery::class)->sendTest((string) $this->actor->id, $auto, 'test@example.test', $locale);
            self::assertStringContainsString('border-top:6px solid #123456', $this->mail()->sent[array_key_last($this->mail()->sent)]->html);
        }
        $this->postJson('/admin/newsletters/preview', ['type' => 'invalid', 'locale' => 'en'])->assertUnprocessable()->assertJsonValidationErrors('type');
    }

    private function mail(): RecordingMailTransport
    {
        $transport = app(OrderMailTransport::class);
        self::assertInstanceOf(RecordingMailTransport::class, $transport);

        return $transport;
    }

    /** @param array<string, mixed> $changes */
    private function list(string $name = 'Weekly', array $changes = []): string
    {
        $this->post('/admin/newsletter-lists', [...['translations' => ['en' => ['name' => $name, 'description' => '']], 'status' => 'active'], ...$changes])->assertSessionHasNoErrors();

        return (string) DB::table('newsletter_lists')->orderByDesc('id')->value('id');
    }

    /** @param array<string, mixed> $changes */
    private function content(string $type, string $name = 'Spring', array $changes = []): string
    {
        $path = ['newsletter' => 'newsletters', 'template' => 'newsletter-templates', 'autoresponder' => 'newsletter-autoresponders'][$type];
        $this->post('/admin/'.$path, [...['translations' => ['en' => ['name' => $name, 'subject' => $type === 'template' ? '' : 'Hello', 'body_html' => '<p>Hi {{subscriber_email}} from {{company_name}}</p><p>{{unsubscribe_link}}</p>']], 'is_active' => true], ...$changes])->assertSessionHasNoErrors();

        return (string) DB::table('newsletters')->where('type', $type)->orderByDesc('id')->value('id');
    }

    private function subscriber(string $email, array $lists, bool $confirmed = true): string
    {
        return app(NewsletterSubscribers::class)->subscribe($email, $lists, 'admin', $confirmed)['id'];
    }

    public function test_the_marketing_menu_and_capabilities(): void
    {
        $this->get('/admin/newsletters')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Newsletters/Index')->where('type', 'newsletter'));
        $menu = json_encode(app(AdminNavigation::class)->forUser($this->actor));
        foreach (['newsletters', 'newsletter-templates', 'newsletter-autoresponders', 'newsletter-campaigns', 'newsletter-lists', 'newsletter-subscribers'] as $entry) {
            self::assertStringContainsString('\/admin\/'.$entry.'"', (string) $menu);
        }
        foreach (['newsletter-templates', 'newsletter-autoresponders', 'newsletter-campaigns', 'newsletter-lists', 'newsletter-subscribers'] as $page) {
            $this->get('/admin/'.$page)->assertOk();
        }
        // Viewing, managing, sending and subscriber access are separate capabilities.
        $this->flushSession();
        $this->actingAs($this->staff(['admin.access', 'newsletters.view']));
        $this->get('/admin/newsletters')->assertOk();
        $this->get('/admin/newsletters/create')->assertForbidden();
        $this->post('/admin/newsletters', [])->assertForbidden();
        $this->get('/admin/newsletter-subscribers')->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->staff(['admin.access', 'subscribers.view']));
        $this->get('/admin/newsletter-subscribers')->assertOk();
        $this->get('/admin/newsletter-subscribers/export')->assertOk();
        $this->get('/admin/newsletter-subscribers/create')->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->staff(['admin.access', 'newsletters.manage']));
        $id = $this->content('newsletter');
        $this->post('/admin/newsletters/'.$id.'/send')->assertForbidden();
    }

    public function test_content_is_sanitized_versioned_and_deleted_safely(): void
    {
        $id = $this->content('newsletter', 'Spring', ['translations' => ['en' => ['name' => 'Spring', 'subject' => 'Hello', 'body_html' => '<p onclick="x()">Hi</p><script>alert(1)</script><form action="/x"><input></form>']]]);
        $body = (string) DB::table('newsletter_translations')->where('newsletter_id', $id)->value('body_html');
        self::assertStringContainsString('<p>Hi</p>', $body);
        foreach (['<script', 'onclick', '<form'] as $bad) {
            self::assertStringNotContainsString($bad, $body);
        }
        // The default language needs a name, subject and body; names stay in translations.
        $this->post('/admin/newsletters', ['translations' => ['en' => ['name' => '', 'subject' => '', 'body_html' => '']]])->assertSessionHasErrors(['translations.en.name', 'translations.en.subject', 'translations.en.body_html']);
        $this->post('/admin/newsletters', ['translations' => ['en' => ['name' => 'Bad', 'subject' => 'S {{nope}}', 'body_html' => '<p>{{subscriber_email}} {{ other }}</p>']]])
            ->assertSessionHasErrors(['translations.en.body_html' => __('newsletters.unknown_variables', ['variables' => '{{nope}}, {{other}}'])]);
        $this->post('/admin/newsletter-templates', ['translations' => ['en' => ['name' => 'Layout', 'body_html' => '<p>x</p>']]])->assertSessionHasNoErrors();
        // Versions guard concurrent edits.
        $version = (int) DB::table('newsletters')->where('id', $id)->value('lock_version');
        $this->put('/admin/newsletters/'.$id, ['version' => $version + 5, 'translations' => ['en' => ['name' => 'X', 'subject' => 'S', 'body_html' => 'b']]])->assertSessionHasErrors('version');
        $this->patch('/admin/newsletters/'.$id.'/active', ['version' => $version, 'active' => false])->assertSessionHasNoErrors();
        self::assertFalse((bool) DB::table('newsletters')->where('id', $id)->value('is_active'));
        // A type cannot be edited through another type's URL.
        $this->get('/admin/newsletter-templates/'.$id.'/edit')->assertNotFound();
        // Autoresponders used as a list's confirmation e-mail cannot be deleted.
        $auto = $this->content('autoresponder', 'Welcome');
        $list = $this->list('News', ['confirmation_id' => $auto]);
        $this->delete('/admin/newsletter-autoresponders/'.$auto, ['version' => (int) DB::table('newsletters')->where('id', $auto)->value('lock_version')])->assertSessionHasErrors('newsletter');
        $this->put('/admin/newsletter-lists/'.$list, ['version' => 1, 'translations' => ['en' => ['name' => 'News']], 'confirmation_id' => '']);
        $this->delete('/admin/newsletter-autoresponders/'.$auto, ['version' => (int) DB::table('newsletters')->where('id', $auto)->value('lock_version')])->assertSessionHasNoErrors();
        self::assertFalse(DB::table('newsletters')->where('id', $auto)->exists());
        // Campaigns have unique names; deleting one keeps its newsletters.
        $this->post('/admin/newsletter-campaigns', ['name' => 'Spring sale'])->assertSessionHasNoErrors();
        $this->post('/admin/newsletter-campaigns', ['name' => 'spring SALE'])->assertSessionHasErrors('name');
        $campaign = (string) DB::table('newsletter_campaigns')->value('id');
        $second = $this->content('newsletter', 'Grouped', ['campaign_id' => $campaign]);
        $this->delete('/admin/newsletter-campaigns/'.$campaign, ['version' => 1])->assertSessionHasNoErrors();
        self::assertNull(DB::table('newsletters')->where('id', $second)->value('campaign_id'));
    }

    public function test_subscribers_are_added_imported_edited_exported_and_confirmed_by_link(): void
    {
        $auto = $this->content('autoresponder', 'Welcome', ['translations' => ['en' => ['name' => 'Welcome', 'subject' => 'Please confirm', 'body_html' => '<p>{{activation_link}}</p>']]]);
        $list = $this->list('Weekly', ['confirmation_id' => $auto, 'from_name' => 'Store News', 'from_email' => 'news@store.example', 'reply_to' => 'help@store.example']);
        $other = $this->list('Offers');

        $this->post('/admin/newsletter-subscribers', ['email' => 'not-an-email', 'list_ids' => [$list]])->assertSessionHasErrors('email');
        $this->post('/admin/newsletter-subscribers', ['email' => 'Anna@Example.test', 'list_ids' => [$list], 'confirmed' => true])->assertSessionHasNoErrors();
        $this->post('/admin/newsletter-subscribers', ['email' => 'anna@example.test', 'list_ids' => [$list]])->assertSessionHasErrors('email');
        self::assertSame(0, DB::table('newsletter_deliveries')->count(), 'Confirmed admin additions send no confirmation e-mail');

        // Unconfirmed memberships trigger the list's confirmation autoresponder, once.
        $this->post('/admin/newsletter-subscribers', ['email' => 'bob@example.test', 'list_ids' => [$list], 'confirmed' => false])->assertSessionHasNoErrors();
        self::assertCount(1, $this->mail()->sent);
        $mail = $this->mail()->sent[0];
        self::assertSame(['bob@example.test', 'Please confirm', 'news@store.example', 'Store News', 'help@store.example'], [$mail->to, $mail->subject, $mail->fromAddress, $mail->fromName, $mail->replyTo]);
        preg_match('/href="([^"]+)"/', $mail->html, $m);
        $link = html_entity_decode($m[1]);
        self::assertStringContainsString('/newsletter/confirm/', $link);
        self::assertStringNotContainsString('{{activation_link}}', $mail->html);

        // The signed link only confirms on POST; an altered link is refused.
        $this->flushSession();
        $this->get($link)->assertOk()->assertSee('Confirm subscription');
        self::assertNull(DB::table('newsletter_list_subscribers')->where('list_id', $list)->whereNotNull('confirmed_at')->where('subscriber_id', DB::table('newsletter_subscribers')->where('email', 'bob@example.test')->value('id'))->value('list_id'));
        $this->get($link.'1')->assertForbidden();
        $this->post($link)->assertOk()->assertSee('Subscription confirmed');
        self::assertSame(2, DB::table('newsletter_list_subscribers')->whereNotNull('confirmed_at')->count());

        // Import counts new, existing and invalid addresses.
        $this->actingAs($this->actor);
        $this->post('/admin/newsletter-subscribers/import', ['emails' => "carl@example.test, anna@example.test\nbroken;carl@example.test", 'list_ids' => [$other]])->assertSessionHas('status', __('newsletters.import_done', ['added' => 1, 'existing' => 1, 'invalid' => 1]));
        self::assertSame('import', DB::table('newsletter_subscribers')->where('email', 'carl@example.test')->value('source'));
        $this->post('/admin/newsletter-subscribers/import', ['emails' => 'x', 'list_ids' => [999999]])->assertSessionHasErrors('list_ids');

        // Editing memberships, status and address.
        $id = (string) DB::table('newsletter_subscribers')->whereRaw("lower(email) = 'anna@example.test'")->value('id');
        $version = (int) DB::table('newsletter_subscribers')->where('id', $id)->value('lock_version');
        $this->put('/admin/newsletter-subscribers/'.$id, ['version' => $version, 'email' => 'anna@example.test', 'status' => 'active', 'locale' => 'el', 'memberships' => [$other => false]])->assertSessionHasNoErrors();
        self::assertSame([[$other, false]], DB::table('newsletter_list_subscribers')->where('subscriber_id', $id)->get()->map(fn ($r) => [(string) $r->list_id, $r->confirmed_at !== null])->all());
        $this->put('/admin/newsletter-subscribers/'.$id, ['version' => $version, 'email' => 'anna@example.test', 'status' => 'active'])->assertSessionHasErrors('version');
        $this->get('/admin/newsletter-subscribers?search=ann&list='.$other)->assertInertia(fn (Assert $p) => $p->has('subscribers.data', 1)->where('subscribers.data.0.lists.0.confirmed', false)->where('subscribers.data.0.locale', 'el'));

        // CSV export neutralizes spreadsheet formulas.
        DB::table('newsletter_subscribers')->where('email', 'carl@example.test')->update(['email' => '=cmd@example.test']);
        $csv = $this->get('/admin/newsletter-subscribers/export')->assertOk()->streamedContent();
        self::assertStringContainsString("'=cmd@example.test", $csv);
        self::assertStringContainsString('email,status,language', $csv);

        $this->delete('/admin/newsletter-subscribers/'.$id, ['version' => $version + 1])->assertSessionHasNoErrors();
        self::assertFalse(DB::table('newsletter_subscribers')->where('id', $id)->exists());
    }

    public function test_sending_reaches_each_recipient_once_with_placeholders_unsubscribe_and_retries(): void
    {
        $weekly = $this->list('Weekly', ['from_name' => 'Store News', 'from_email' => 'news@store.example']);
        $disabled = $this->list('Old', ['status' => 'disabled']);
        $a = $this->subscriber('a@example.test', [$weekly]);
        $b = $this->subscriber('b@example.test', [$weekly, $disabled]);
        $this->subscriber('unconfirmed@example.test', [$weekly], false);
        $this->subscriber('old@example.test', [$disabled]);
        $gone = $this->subscriber('gone@example.test', [$weekly]);
        app(NewsletterSubscribers::class)->unsubscribe($gone, null);
        DB::table('customers')->insert(['email' => 'cust@example.test', 'first_name' => 'Cy', 'last_name' => 'Customer', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $customer = (string) DB::table('customers')->value('id');
        $this->mail()->sent = [];

        $news = $this->content('newsletter', 'Spring', ['list_ids' => [$weekly], 'customer_ids' => [$customer], 'translations' => ['en' => ['name' => 'Spring', 'subject' => 'Main', 'random_subjects' => "Alt one\nAlt two", 'body_html' => '<p>Hi {{subscriber_email}}, {{company_name}}, {{company_phone}}</p>{{unsubscribe_link}}']]]);
        $this->post('/admin/newsletters/'.$news.'/send')->assertSessionHasNoErrors()->assertSessionHas('status', __('newsletters.send_queued', ['count' => 3]));
        // Queue connection is sync in tests: everything was delivered.
        $to = collect($this->mail()->sent)->pluck('to')->sort()->values()->all();
        self::assertSame(['a@example.test', 'b@example.test', 'cust@example.test'], $to);
        self::assertSame('sent', DB::table('newsletters')->where('id', $news)->value('state'));
        foreach ($this->mail()->sent as $mail) {
            self::assertContains($mail->subject, ['Main', 'Alt one', 'Alt two']);
            self::assertStringContainsString('Hi '.$mail->to.', Test Store, +30 210 0000000', $mail->html);
            self::assertStringNotContainsString('{{', strip_tags($mail->html));
        }
        $byTo = collect($this->mail()->sent)->keyBy('to');
        self::assertSame(['news@store.example', 'Store News'], [$byTo['a@example.test']->fromAddress, $byTo['a@example.test']->fromName]);
        self::assertSame('shop@store.example', $byTo['cust@example.test']->fromAddress);
        self::assertStringContainsString('/newsletter/unsubscribe/', $byTo['a@example.test']->html);
        self::assertStringNotContainsString('/newsletter/unsubscribe/', $byTo['cust@example.test']->html, 'Customers without a list membership get no list unsubscribe link');

        // Sending again reaches nobody who was already addressed, only new subscribers.
        $this->post('/admin/newsletters/'.$news.'/send')->assertSessionHas('status', __('newsletters.send_nothing_new'));
        $this->subscriber('late@example.test', [$weekly]);
        $this->post('/admin/newsletters/'.$news.'/send')->assertSessionHas('status', __('newsletters.send_queued', ['count' => 1]));
        self::assertCount(4, $this->mail()->sent);
        self::assertSame(4, DB::table('newsletter_deliveries')->where('newsletter_id', $news)->where('state', 'sent')->count());

        // The unsubscribe link removes the membership on POST only; with no list left the subscriber is unsubscribed.
        preg_match('/href="([^"]+unsubscribe[^"]+)"/', $byTo['a@example.test']->html, $m);
        $link = html_entity_decode($m[1]);
        $this->flushSession();
        $this->get($link)->assertOk()->assertSee('Unsubscribe');
        self::assertTrue(DB::table('newsletter_list_subscribers')->where('subscriber_id', $a)->exists());
        $this->post($link)->assertOk()->assertSee('You are unsubscribed');
        self::assertFalse(DB::table('newsletter_list_subscribers')->where('subscriber_id', $a)->exists());
        self::assertSame('unsubscribed', DB::table('newsletter_subscribers')->where('id', $a)->value('status'));
        // A subscriber of several lists only leaves the list the link came from.
        $this->subscriber('b@example.test', [$weekly, $this->list('More')]);
        $this->post(URL::signedRoute('newsletter.unsubscribe', ['subscriber' => $b, 'list' => $weekly]))->assertOk();
        self::assertSame('active', DB::table('newsletter_subscribers')->where('id', $b)->value('status'));
        $this->get('/newsletter/unsubscribe/'.$b)->assertForbidden();
    }

    public function test_failed_deliveries_retry_up_to_three_times_and_blocked_sends_explain_why(): void
    {
        $weekly = $this->list();
        $this->subscriber('a@example.test', [$weekly]);
        $news = $this->content('newsletter', 'Spring', ['list_ids' => [$weekly]]);
        $this->actingAs($this->actor);

        $this->mail()->accept = false;
        $this->post('/admin/newsletters/'.$news.'/send')->assertSessionHasNoErrors();
        $delivery = DB::table('newsletter_deliveries')->sole();
        self::assertSame(['failed', 1, 'mail_rejected'], [$delivery->state, (int) $delivery->attempts, $delivery->error]);
        self::assertSame('sending', DB::table('newsletters')->where('id', $news)->value('state'), 'Retries are still pending');
        foreach ([2, 3] as $attempt) {
            DB::table('newsletter_deliveries')->update(['last_attempt_at' => now()->subMinutes(10)]);
            self::assertSame(1, app(NewsletterDelivery::class)->dispatchPending());
            self::assertSame($attempt, (int) DB::table('newsletter_deliveries')->value('attempts'));
        }
        DB::table('newsletter_deliveries')->update(['last_attempt_at' => now()->subMinutes(10)]);
        self::assertSame(0, app(NewsletterDelivery::class)->dispatchPending(), 'No fourth attempt');
        self::assertSame('sent', DB::table('newsletters')->where('id', $news)->value('state'));
        $this->get('/admin/newsletters')->assertInertia(fn (Assert $p) => $p->where('items.data.0.stats.failed', 1));

        // Sending is refused with a reason: no content, no audience, disabled, no sender.
        $empty = $this->content('newsletter', 'No audience');
        $this->post('/admin/newsletters/'.$empty.'/send')->assertSessionHasErrors(['newsletter' => __('newsletters.send_no_recipients')]);
        DB::table('newsletters')->where('id', $empty)->update(['is_active' => false]);
        $this->post('/admin/newsletters/'.$empty.'/send')->assertSessionHasErrors(['newsletter' => __('newsletters.send_inactive')]);
        DB::table('newsletters')->where('id', $empty)->update(['is_active' => true]);
        DB::table('general_settings')->where('id', 1)->update(['order_email_from' => null]);
        $this->post('/admin/newsletters/'.$empty.'/send')->assertSessionHasErrors(['newsletter' => __('newsletters.sender_missing')]);
    }

    public function test_test_messages_are_sent_without_recording_deliveries(): void
    {
        $id = $this->content('newsletter', 'Spring');
        $this->post('/admin/newsletters/'.$id.'/test', ['email' => 'bad'])->assertSessionHasErrors('email');
        $this->post('/admin/newsletters/'.$id.'/test', ['email' => 'me@example.test', 'locale' => 'en'])->assertSessionHasNoErrors();
        $mail = $this->mail()->sent[0];
        self::assertSame(['me@example.test', '[Test] Hello'], [$mail->to, $mail->subject]);
        self::assertSame(0, DB::table('newsletter_deliveries')->count());
        $this->get('/admin/newsletters/customers?term=a')->assertOk()->assertJson(['customers' => []]);
    }

    public function test_the_demo_command_creates_sample_data_once_without_sending(): void
    {
        $this->artisan('cms:newsletters-demo', ['actor' => (string) $this->actor->id])->assertSuccessful();
        self::assertSame([2, 1, 1, 1, 9], [DB::table('newsletter_lists')->count(), DB::table('newsletters')->where('type', 'template')->count(), DB::table('newsletters')->where('type', 'autoresponder')->count(),
            DB::table('newsletters')->where('type', 'newsletter')->count(), DB::table('newsletter_subscribers')->count()]);
        self::assertSame([], $this->mail()->sent);
        $this->artisan('cms:newsletters-demo', ['actor' => (string) $this->actor->id])->assertSuccessful();
        self::assertSame(9, DB::table('newsletter_subscribers')->count());
        $this->get('/admin/newsletter-subscribers')->assertInertia(fn (Assert $p) => $p->has('subscribers.data', 9));
        // Sending the sample newsletter reaches only confirmed subscribers of its active lists.
        $id = (string) DB::table('newsletters')->where('type', 'newsletter')->value('id');
        $this->post('/admin/newsletters/'.$id.'/send')->assertSessionHasNoErrors();
        self::assertCount(6, $this->mail()->sent);
    }

    public function test_the_live_preview_renders_unsaved_content_with_sample_values(): void
    {
        $response = $this->postJson('/admin/newsletters/preview', ['locale' => 'en', 'subject' => 'Hi {{company_name}}', 'body_html' => '<p onclick="x()">Dear {{subscriber_email}}</p><script>alert(1)</script>{{unsubscribe_link}}'])->assertOk();
        $response->assertJsonPath('subject', 'Hi Test Store');
        $html = (string) $response->json('html');
        self::assertStringContainsString('Dear subscriber@example.test', $html);
        self::assertStringContainsString('example.test/newsletter/unsubscribe/sample', $html);
        foreach (['<script', 'onclick', '{{'] as $bad) {
            self::assertStringNotContainsString($bad, $html);
        }
        self::assertSame(0, DB::table('newsletter_deliveries')->count());
        self::assertSame([], $this->mail()->sent);
        $this->postJson('/admin/newsletters/preview', ['locale' => 'en', 'subject' => '', 'body_html' => '{{nope}}'])->assertStatus(422)->assertJsonValidationErrors('body_html');
        $this->flushSession();
        $this->actingAs($this->staff(['admin.access', 'newsletters.view']));
        $this->postJson('/admin/newsletters/preview', ['locale' => 'en', 'body_html' => 'x'])->assertForbidden();
    }
}
