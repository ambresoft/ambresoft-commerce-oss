<?php

namespace Tests\Feature;

use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\Persistence\LocaleRecord;
use App\Settings\Infrastructure\AiSettings;
use App\Website\Infrastructure\FaqAiGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class FaqAiTest extends TestCase
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
        Http::preventStrayRequests();
    }

    private function saveAiSettings(array $values): TestResponse
    {
        return $this->put('/admin/settings/general', ['version' => (int) DB::table('general_settings')->value('lock_version'), 'out_of_stock_action' => 'N', 'ai_settings' => $values]);
    }

    public function test_invalid_shared_ai_settings_roll_back_general_changes_and_never_flash_the_key(): void
    {
        $version = (int) DB::table('general_settings')->value('lock_version');
        $this->saveAiSettings(['version' => 1, 'enabled' => true, 'api_key' => 'secret key with spaces', 'model' => 'claude-sonnet-4-6', 'max_tokens' => 1000, 'hourly_limit' => 50])
            ->assertSessionHasErrors('ai_settings.api_key');
        self::assertNull(session('_old_input.ai_settings.api_key'));
        self::assertSame($version, (int) DB::table('general_settings')->value('lock_version'));
        self::assertNull(DB::table('ai_settings')->value('api_key'));
    }

    public function test_shared_ai_migration_preserves_keys_history_and_log_grants_on_round_trip(): void
    {
        $this->configure();
        $staff = $this->staff(['admin.access', 'ai.logs.view']);
        Http::fake(['api.anthropic.com/v1/messages' => Http::response($this->response())]);
        app(FaqAiGenerator::class)->generate((string) $this->actor->id, $this->request());
        $encrypted = DB::table('ai_settings')->value('api_key');
        $migration = require database_path('migrations/2026_10_15_000700_share_ai_settings_and_history.php');
        $migration->down();
        self::assertSame($encrypted, DB::table('faq_ai_settings')->value('api_key'));
        self::assertSame(1, DB::table('faq_ai_calls')->count());
        $migration->up();
        self::assertSame($encrypted, DB::table('ai_settings')->value('api_key'));
        self::assertSame('faq_generation', DB::table('ai_calls')->value('feature'));
        self::assertTrue(Gate::forUser($staff)->allows('ai.logs.view'));
    }

    public function test_the_assistant_drafts_one_faq_in_a_chat_with_escaped_paragraph_answers(): void
    {
        $this->configure();
        $body = ['reply' => 'Here is the FAQ.', 'translations' => ['en' => ['question' => 'How long is delivery?', 'answer' => "Usually 3-5 days.\n\nIslands take <b>longer</b> & more.\nCall us."]]];
        Http::fake(['api.anthropic.com/v1/messages' => Http::response(['stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 10, 'output_tokens' => 10], 'content' => [['type' => 'text', 'text' => json_encode($body, JSON_THROW_ON_ERROR)]]])]);

        $this->postJson('/admin/faqs/assistant', ['messages' => [['role' => 'user', 'content' => 'A delivery FAQ']], 'current' => ['translations' => ['en' => ['question' => 'Old?', 'answer' => '']]]])
            ->assertOk()
            ->assertJsonPath('reply', 'Here is the FAQ.')
            ->assertJsonPath('draft.translations.en.question', 'How long is delivery?')
            ->assertJsonPath('draft.translations.en.answer', '<p>Usually 3-5 days.</p><p>Islands take longer &amp; more.<br>Call us.</p>');

        self::assertSame(0, DB::table('faqs')->count());
        self::assertSame(['faq_generation', 'success'], [DB::table('ai_calls')->value('feature'), DB::table('ai_calls')->value('status')]);
        Http::assertSent(function ($request): bool {
            $context = json_decode($request['messages'][0]['content'], true);

            return $context['request'] === 'A delivery FAQ' && $context['current']['translations']['en']['question'] === 'Old?' && isset($context['store_contact_details']) && $context['languages'] === ['en'];
        });
    }

    private function configure(int $limit = 50): void
    {
        app(AiSettings::class)->save((string) $this->actor->id, ['version' => 1, 'enabled' => true, 'api_key' => 'test-only-anthropic-key', 'model' => 'claude-sonnet-4-6', 'max_tokens' => 1000, 'hourly_limit' => $limit]);
    }

    /** @return array<string, mixed> */
    private function request(): array
    {
        return ['owner' => 'general', 'locale' => 'auto', 'count' => 1, 'source_name' => 'Example store', 'source_text' => 'A shop selling leather shoes.'];
    }

    /** @return array<string, mixed> */
    private function response(string $stop = 'end_turn'): array
    {
        $entries = ['entries' => [['translations' => ['en' => ['question' => 'How do I clean leather shoes?', 'answer' => 'Use a soft cloth and the manufacturer’s care instructions.']]]]];

        return ['stop_reason' => $stop, 'usage' => ['input_tokens' => 120, 'output_tokens' => 80], 'content' => [['type' => 'text', 'text' => json_encode($entries, JSON_THROW_ON_ERROR)]]];
    }

    public function test_manual_work_is_available_without_a_key_and_generation_never_calls_the_provider(): void
    {
        Http::fake();
        $this->get('/admin/faqs/create')->assertOk();
        $this->postJson('/admin/faqs/generate', $this->request())->assertUnprocessable()->assertJsonValidationErrors('ai');
        Http::assertNothingSent();
        self::assertSame(0, DB::table('ai_calls')->count());
    }

    public function test_the_key_is_write_only_encrypted_and_not_present_in_props_or_audit(): void
    {
        $this->configure();
        $encrypted = DB::table('ai_settings')->where('id', 1)->value('api_key');
        self::assertNotSame('test-only-anthropic-key', $encrypted);
        self::assertSame('test-only-anthropic-key', Crypt::decryptString($encrypted));
        $this->get('/admin/settings/general?tab=ai')->assertInertia(fn (Assert $page) => $page->component('Admin/Settings/General')->where('aiSettings.has_key', true)->missing('aiSettings.api_key')->where('aiSettings.max_tokens', 1000));
        $this->get('/admin/faqs/ai-settings')->assertRedirect('/admin/settings/general?tab=ai');
        self::assertFalse(DB::table('audit_logs')->where('changes', 'like', '%test-only-anthropic-key%')->exists());
        $this->saveAiSettings(['version' => 2, 'enabled' => true, 'api_key' => '', 'model' => 'claude-sonnet-4-6', 'max_tokens' => 1500, 'hourly_limit' => 50])->assertSessionHasNoErrors();
        self::assertSame($encrypted, DB::table('ai_settings')->where('id', 1)->value('api_key'));
        $this->saveAiSettings(['version' => 3, 'enabled' => false, 'clear_key' => true, 'model' => 'claude-sonnet-4-6', 'max_tokens' => 1500, 'hourly_limit' => 50])->assertSessionHasNoErrors();
        self::assertNull(DB::table('ai_settings')->where('id', 1)->value('api_key'));
    }

    public function test_claude_requests_use_bounded_structured_output_and_log_usage_without_creating_faqs(): void
    {
        $this->configure();
        DB::table('company_settings')->where('id', 1)->update(['name' => 'Demo Shop', 'phone' => '2101234567', 'email_admin' => 'private@example.test', 'email_support' => 'support@example.test']);
        Http::fake(['api.anthropic.com/v1/messages' => Http::response($this->response(), 200, ['request-id' => 'req_test_123'])]);
        $this->postJson('/admin/faqs/generate', $this->request())->assertOk()->assertJsonPath('entries.0.translations.en.question', 'How do I clean leather shoes?');
        Http::assertSent(function ($request): bool {
            $context = json_decode($request['messages'][0]['content'], true);
            self::assertSame('Demo Shop', $context['store_contact_details']['name']);
            self::assertArrayNotHasKey('email_admin', $context['store_contact_details']);

            return $request->url() === 'https://api.anthropic.com/v1/messages' && $request->hasHeader('x-api-key', 'test-only-anthropic-key') && $request['max_tokens'] === 1000 && $request['output_config']['format']['type'] === 'json_schema';
        });
        self::assertSame(0, DB::table('faqs')->count());
        $log = DB::table('ai_calls')->sole();
        self::assertSame(['success', 120, 80, 'req_test_123'], [$log->status, $log->input_tokens, $log->output_tokens, $log->provider_request_id]);
        self::assertSame($this->actor->id, $log->user_id);
        $this->get('/admin/settings/ai-log')->assertOk()->assertInertia(fn (Assert $page) => $page->where('logs.data.0.output_tokens', 80));
    }

    public function test_rate_limit_is_per_user_and_regeneration_consumes_the_same_budget(): void
    {
        $this->configure(1);
        Http::fake(['api.anthropic.com/v1/messages' => Http::response($this->response())]);
        $this->postJson('/admin/faqs/generate', $this->request())->assertOk();
        $this->postJson('/admin/faqs/generate', [...$this->request(), 'question' => 'Replace this question'])->assertTooManyRequests();
        Http::assertSentCount(1);
        self::assertSame(1, DB::table('ai_calls')->count());
        $other = $this->staff(super: true);
        $result = app(FaqAiGenerator::class)->generate((string) $other->id, $this->request());
        self::assertCount(1, $result['entries']);
        Http::assertSentCount(2);
    }

    public function test_provider_errors_and_truncation_are_logged_without_automatic_retries_or_response_leaks(): void
    {
        $this->configure();
        Http::fake(['api.anthropic.com/v1/messages' => Http::sequence()->push(['error' => ['message' => 'Secret provider body test-only-anthropic-key']], 401)->push($this->response('max_tokens'))->push($this->response('refusal'))->push(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'invalid JSON']]])]);
        foreach (['provider_error', 'token_limit', 'refused', 'invalid_response'] as $error) {
            $response = $this->postJson('/admin/faqs/generate', $this->request())->assertUnprocessable()->assertJsonValidationErrors('ai');
            self::assertStringNotContainsString('test-only-anthropic-key', $response->getContent());
            self::assertSame($error, DB::table('ai_calls')->orderByDesc('id')->first()->error_code);
        }
        Http::assertSentCount(4);
        self::assertSame(0, DB::table('faqs')->count());
        self::assertSame(4, DB::table('ai_calls')->where('status', 'error')->count());
    }

    public function test_connection_failures_are_scrubbed_and_logged(): void
    {
        $this->configure();
        Http::fake(fn () => throw new ConnectionException('Do not expose test-only-anthropic-key'));
        $response = $this->postJson('/admin/faqs/generate', $this->request())->assertUnprocessable();
        self::assertStringNotContainsString('test-only-anthropic-key', $response->getContent());
        self::assertSame('connection_error', DB::table('ai_calls')->sole()->error_code);
    }

    public function test_generation_settings_and_logs_have_independent_server_capabilities(): void
    {
        $this->configure();
        Http::fake();
        $this->actingAs($this->staff(['admin.access', 'faqs.view']));
        $this->get('/admin/faqs')->assertOk();
        $this->get('/admin/settings/general?tab=ai')->assertForbidden();
        $this->saveAiSettings(['version' => 1])->assertForbidden();
        $this->get('/admin/settings/ai-log')->assertForbidden();
        $this->postJson('/admin/faqs/generate', $this->request())->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_generating_from_a_product_requires_product_read_permission(): void
    {
        $this->configure();
        Http::fake();
        $this->actingAs($this->staff(['admin.access', 'faqs.generate', 'faqs.manage']));
        $this->postJson('/admin/faqs/generate', [...$this->request(), 'owner' => 'product', 'object_id' => 999])->assertForbidden();
        Http::assertNothingSent();
    }
}
