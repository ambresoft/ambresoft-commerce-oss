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

final class BannerAiTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private UserRecord $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actor = $this->staff(['admin.access', 'banners.view', 'banners.manage', 'banners.generate']);
        $this->actingAs($this->actor);
        Http::preventStrayRequests();
    }

    private function configure(string $model = 'claude-sonnet-4-6'): void
    {
        app(AiSettings::class)->save((string) $this->staff(super: true)->id, ['version' => (int) DB::table('ai_settings')->value('lock_version'), 'enabled' => true, 'api_key' => 'test-only-anthropic-key', 'model' => $model, 'max_tokens' => 16000, 'hourly_limit' => 50]);
    }

    /** @param array<string, mixed> $override */
    private function banner(array $override = []): array
    {
        $texts = fn (string $title): array => ['title' => $title, 'description' => 'All shoes, this weekend only.', 'button_text' => 'Shop now'];

        return array_replace_recursive([
            'names' => ['en' => 'Black Friday', 'el' => 'Black Friday'],
            'settings' => ['color_scheme' => 'dark', 'background_color' => '#111', 'title_color' => '#FFFFFF', 'title_weight' => 'heavy', 'title_tag' => 'h2', 'description_color' => '', 'description_background' => '', 'button_style' => 'standard', 'button_hover' => 'lift', 'url' => '/sale'],
            'layout' => ['valign' => 'center', 'halign' => 'left', 'paddings' => '40px 32', 'full_width' => true, 'title_size' => 48, 'title_shadow' => false, 'description_size' => 18, 'button_enabled' => true, 'button_text_color' => '#000000', 'button_color' => '#ffcc00', 'mask_type' => 'gradient', 'mask_position' => 'banner', 'mask_opacity' => 60, 'mask_direction' => 'left', 'mask_color' => ''],
            'contents' => ['en' => $texts('<b>30% off</b> shoes'), 'el' => $texts('30% έκπτωση')],
        ], $override);
    }

    /** @param array<string, mixed> $banner */
    private function reply(array $banner, string $stop = 'end_turn'): array
    {
        $body = ['reply' => 'I made a dark banner. Upload a photo in the General tab.', 'banner' => $banner];

        return ['stop_reason' => $stop, 'usage' => ['input_tokens' => 900, 'output_tokens' => 400], 'content' => [['type' => 'text', 'text' => json_encode($body, JSON_THROW_ON_ERROR)]]];
    }

    /** @return array<string, mixed> */
    private function request(): array
    {
        return ['messages' => [['role' => 'user', 'content' => 'A dark Black Friday banner, 30% off shoes, button to /sale']], 'current' => ['names' => ['en' => 'Draft']]];
    }

    public function test_a_reply_is_checked_normalised_and_returned_without_saving_anything(): void
    {
        $this->configure();
        Http::fake(['api.anthropic.com/v1/messages' => Http::response($this->reply($this->banner()))]);

        $response = $this->postJson('/admin/banners/generate', $this->request())->assertOk();

        $response->assertJsonPath('reply', 'I made a dark banner. Upload a photo in the General tab.')
            ->assertJsonPath('draft.settings.background_color', '#111111')
            ->assertJsonPath('draft.settings.title_color', '#ffffff')
            ->assertJsonPath('draft.settings.description_color', null)
            ->assertJsonPath('draft.layout.paddings', '40px 32px')
            ->assertJsonPath('draft.contents.en.title', '30% off shoes')
            ->assertJsonPath('draft.names.el', 'Black Friday')
            ->assertJsonMissingPath('draft.settings.status');
        self::assertSame(0, DB::table('banners')->count());
        $log = DB::table('ai_calls')->sole();
        self::assertSame(['banner_generation', 'banner', 'success'], [$log->feature, $log->object_type, $log->status]);
        Http::assertSent(function ($request): bool {
            $context = json_decode($request['messages'][0]['content'], true);

            return $request->hasHeader('x-api-key', 'test-only-anthropic-key') && $request['model'] === 'claude-sonnet-4-6'
                && $context['request'] === 'A dark Black Friday banner, 30% off shoes, button to /sale' && $context['current']['names']['en'] === 'Draft'
                && $context['languages'] === ['en', 'el'] && $request['output_config']['format']['schema']['required'] === ['reply', 'banner'];
        });
    }

    public function test_earlier_turns_are_sent_as_the_conversation(): void
    {
        $this->configure();
        Http::fake(['api.anthropic.com/v1/messages' => Http::response($this->reply($this->banner()))]);

        $this->postJson('/admin/banners/generate', ['messages' => [
            ['role' => 'user', 'content' => 'A summer banner'], ['role' => 'assistant', 'content' => 'Done.'], ['role' => 'user', 'content' => 'Make the title bigger'],
        ]])->assertOk();

        Http::assertSent(fn ($request): bool => count($request['messages']) === 3 && $request['messages'][1] === ['role' => 'assistant', 'content' => 'Done.']
            && json_decode($request['messages'][2]['content'], true)['request'] === 'Make the title bigger');
    }

    public function test_a_banner_that_fails_the_save_rules_is_rejected_and_logged(): void
    {
        $this->configure();
        Http::fake(['api.anthropic.com/v1/messages' => Http::response($this->reply($this->banner(['layout' => ['title_size' => 900], 'settings' => ['url' => 'javascript:alert(1)']])))]);

        $this->postJson('/admin/banners/generate', $this->request())->assertUnprocessable()
            ->assertJsonPath('errors.ai.0', __('banners.ai_error_invalid_response'));
        self::assertSame('invalid_response', DB::table('ai_calls')->value('error_code'));
    }

    public function test_a_truncated_reply_is_reported_as_the_token_limit(): void
    {
        $this->configure();
        Http::fake(['api.anthropic.com/v1/messages' => Http::response($this->reply($this->banner(), 'max_tokens'))]);

        $this->postJson('/admin/banners/generate', $this->request())->assertUnprocessable()
            ->assertJsonPath('errors.ai.0', __('banners.ai_error_token_limit'));
    }

    public function test_new_models_are_offered_and_run_at_low_effort_with_refusal_fallback(): void
    {
        $this->configure('claude-opus-5-5');
        Http::fake(['api.anthropic.com/v1/messages' => Http::response($this->reply($this->banner()))]);

        $this->postJson('/admin/banners/generate', $this->request())->assertOk();
        $this->configure('claude-sonnet-4-6');
        $this->postJson('/admin/banners/generate', $this->request())->assertOk();

        $sent = Http::recorded()->map(fn (array $pair) => $pair[0]);
        self::assertSame(['claude-opus-5-5', 16000, 'low', 'default', 'server-side-fallback-2026-07-01'], [$sent[0]['model'], $sent[0]['max_tokens'], $sent[0]['output_config']['effort'], $sent[0]['fallbacks'], $sent[0]->header('anthropic-beta')[0]]);
        // Older models keep their original request: no effort, no fallback.
        self::assertArrayNotHasKey('effort', $sent[1]['output_config']);
        self::assertArrayNotHasKey('fallbacks', $sent[1]->data());
        self::assertFalse($sent[1]->hasHeader('anthropic-beta'));

        $this->actingAs($admin = $this->staff(super: true));
        $this->withSession(['password_hash_web' => $admin->getAuthPassword()]);
        $this->get('/admin/settings/general?tab=ai')->assertInertia(fn (Assert $page) => $page
            ->where('aiModels.0', ['value' => 'claude-opus-5-5', 'label' => 'Claude Opus 5.5'])
            ->where('aiModels', fn ($models) => collect($models)->pluck('value')->contains('claude-sonnet-4-6')));
    }

    public function test_without_a_key_or_the_capability_nothing_is_sent(): void
    {
        Http::fake();
        $this->get('/admin/banners/create')->assertInertia(fn (Assert $page) => $page->where('aiReady', false));
        $this->postJson('/admin/banners/generate', $this->request())->assertUnprocessable()->assertJsonValidationErrors('ai');

        $this->configure();
        $this->get('/admin/banners/create')->assertInertia(fn (Assert $page) => $page->where('aiReady', true));
        $this->postJson('/admin/banners/generate', ['messages' => [['role' => 'assistant', 'content' => 'Hi']]])->assertUnprocessable()->assertJsonValidationErrors('messages');
        $this->actingAs($limited = $this->staff(['admin.access', 'banners.view', 'banners.manage']));
        $this->withSession(['password_hash_web' => $limited->getAuthPassword()]);
        $this->postJson('/admin/banners/generate', $this->request())->assertForbidden();
        Http::assertNothingSent();
    }
}
