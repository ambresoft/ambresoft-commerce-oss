<?php

namespace Tests\Feature;

use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Settings\Infrastructure\AiSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class NewsletterAiTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private UserRecord $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actor = $this->staff(['admin.access', 'newsletters.view', 'newsletters.manage', 'newsletters.generate']);
        $this->actingAs($this->actor);
        Http::preventStrayRequests();
    }

    private function configure(): void
    {
        app(AiSettings::class)->save((string) $this->staff(super: true)->id, ['version' => 1, 'enabled' => true, 'api_key' => 'test-only-anthropic-key', 'model' => 'claude-opus-5-5', 'max_tokens' => 8000, 'hourly_limit' => 50]);
    }

    /** @param array<string, mixed> $email */
    private function reply(array $email = []): array
    {
        $body = ['reply' => 'Here is your sale email. Send yourself a test next.', 'email' => [
            'name' => 'Winter sale', 'subject' => 'Winter   sale: 20% off coats', 'random_subjects' => ['Coats are 20% off', '<b>Last</b> days of winter'],
            'body_html' => '<h2>Hello</h2><p>Coats are 20% off until Sunday at {{company_name}}.</p><p><a href="https://example.com/sale" onclick="x()">Shop the sale</a></p><script>x()</script><p>{{unsubscribe_link}}</p>',
            ...$email,
        ]];

        return ['stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 400, 'output_tokens' => 900], 'content' => [['type' => 'text', 'text' => json_encode($body, JSON_THROW_ON_ERROR)]]];
    }

    /** @return array<string, mixed> */
    private function request(string $type = 'newsletter'): array
    {
        return ['locale' => 'en', 'type' => $type, 'messages' => [['role' => 'user', 'content' => 'Winter sale, 20% off coats until Sunday']], 'current' => ['name' => '', 'subject' => '', 'random_subjects' => '', 'body_html' => '']];
    }

    public function test_an_email_is_written_with_placeholders_kept_and_its_body_sanitised_without_saving(): void
    {
        $this->configure();
        Http::fake(['api.anthropic.com/v1/messages' => Http::response($this->reply())]);

        $this->postJson('/admin/newsletters/assistant', $this->request())->assertOk()
            ->assertJsonPath('draft.name', 'Winter sale')
            ->assertJsonPath('draft.subject', 'Winter sale: 20% off coats')
            ->assertJsonPath('draft.random_subjects', "Coats are 20% off\nLast days of winter")
            ->assertJsonPath('draft.body_html', '<h2>Hello</h2><p>Coats are 20% off until Sunday at {{company_name}}.</p><p><a href="https://example.com/sale">Shop the sale</a></p><p>{{unsubscribe_link}}</p>');

        self::assertSame(0, DB::table('newsletters')->count());
        $log = DB::table('ai_calls')->sole();
        self::assertSame(['newsletter_generation', 'newsletter', 'success'], [$log->feature, $log->object_type, $log->status]);
        Http::assertSent(fn ($request): bool => json_decode($request['messages'][0]['content'], true)['type'] === 'newsletter'
            && $request['fallbacks'] === 'default' && $request['output_config']['format']['schema']['required'] === ['reply', 'email']);
    }

    public function test_templates_drop_subjects_and_unknown_placeholders_are_rejected(): void
    {
        $this->configure();
        Http::fakeSequence('api.anthropic.com/v1/messages')
            ->push($this->reply())
            ->push($this->reply(['body_html' => '<p>Hi {{first_name}}</p>']));

        $this->postJson('/admin/newsletters/assistant', $this->request('template'))->assertOk()
            ->assertJsonPath('draft.subject', '')->assertJsonPath('draft.random_subjects', '');
        $this->postJson('/admin/newsletters/assistant', $this->request())->assertUnprocessable()
            ->assertJsonPath('errors.ai.0', __('newsletters.ai_error_invalid_response'));
    }

    public function test_the_writer_needs_its_capability(): void
    {
        Http::fake();
        $this->configure();
        $this->get('/admin/newsletters/create')->assertInertia(fn (Assert $page) => $page->where('aiReady', true));
        $this->actingAs($limited = $this->staff(['admin.access', 'newsletters.view', 'newsletters.manage']));
        $this->withSession(['password_hash_web' => $limited->getAuthPassword()]);
        $this->get('/admin/newsletters/create')->assertInertia(fn (Assert $page) => $page->where('aiReady', false));
        $this->postJson('/admin/newsletters/assistant', $this->request())->assertForbidden();
        Http::assertNothingSent();
    }
}
