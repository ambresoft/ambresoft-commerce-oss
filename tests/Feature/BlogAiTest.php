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

final class BlogAiTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private UserRecord $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actor = $this->staff(['admin.access', 'blog.view', 'blog.manage', 'blog.generate']);
        $this->actingAs($this->actor);
        Http::preventStrayRequests();
    }

    private function configure(): void
    {
        app(AiSettings::class)->save((string) $this->staff(super: true)->id, ['version' => 1, 'enabled' => true, 'api_key' => 'test-only-anthropic-key', 'model' => 'claude-sonnet-5-5', 'max_tokens' => 8000, 'hourly_limit' => 50]);
    }

    /** @param array<string, mixed> $post */
    private function reply(array $post = []): array
    {
        $body = ['reply' => 'I wrote the guide. Add a cover image next.', 'post' => [
            'name' => 'Choosing running shoes', 'excerpt' => 'A short guide for beginners.',
            'content' => '<h2>Fit first</h2><p>Try shoes <strong>late in the day</strong>.</p><script>alert(1)</script><p onclick="x()">Walk around.</p>',
            'seo_title' => 'Running shoes guide', 'meta_description' => 'How to choose running shoes.', 'meta_keywords' => 'running, shoes',
            'tags' => ['Running', 'Guides, tips'], ...$post,
        ]];

        return ['stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 500, 'output_tokens' => 1500], 'content' => [['type' => 'text', 'text' => json_encode($body, JSON_THROW_ON_ERROR)]]];
    }

    /** @return array<string, mixed> */
    private function request(string $locale = 'el'): array
    {
        return ['locale' => $locale, 'messages' => [['role' => 'user', 'content' => 'A beginner guide to running shoes']], 'current' => ['name' => '', 'content' => '']];
    }

    public function test_a_post_is_written_for_one_language_sanitised_and_returned_without_saving(): void
    {
        $this->configure();
        Http::fake(['api.anthropic.com/v1/messages' => Http::response($this->reply())]);

        $this->postJson('/admin/blog-posts/assistant', $this->request())->assertOk()
            ->assertJsonPath('reply', 'I wrote the guide. Add a cover image next.')
            ->assertJsonPath('draft.name', 'Choosing running shoes')
            ->assertJsonPath('draft.content', '<h2>Fit first</h2><p>Try shoes <strong>late in the day</strong>.</p><p>Walk around.</p>')
            ->assertJsonPath('draft.tags', 'Running, Guides tips')
            ->assertJsonPath('draft.meta_keywords', 'running, shoes');

        self::assertSame(0, DB::table('blog_posts')->count());
        $log = DB::table('ai_calls')->sole();
        self::assertSame(['blog_generation', 'blog_post', 'success'], [$log->feature, $log->object_type, $log->status]);
        Http::assertSent(fn ($request): bool => json_decode($request['messages'][0]['content'], true)['language'] === 'el'
            && $request['output_config']['effort'] === 'low' && $request['output_config']['format']['schema']['required'] === ['reply', 'post']);
    }

    public function test_drafts_beyond_the_post_limits_are_rejected(): void
    {
        $this->configure();
        Http::fake(['api.anthropic.com/v1/messages' => Http::response($this->reply(['name' => str_repeat('A', 201)]))]);

        $this->postJson('/admin/blog-posts/assistant', $this->request())->assertUnprocessable()
            ->assertJsonPath('errors.ai.0', __('blogs.ai_error_invalid_response'));
        self::assertSame('invalid_response', DB::table('ai_calls')->value('error_code'));
    }

    public function test_only_posts_get_the_writer_and_only_with_the_capability_and_an_active_language(): void
    {
        Http::fake();
        $this->configure();
        $this->get('/admin/blog-posts/create')->assertInertia(fn (Assert $page) => $page->where('aiReady', true));
        $this->get('/admin/blogs/create')->assertInertia(fn (Assert $page) => $page->where('aiReady', false));
        $this->postJson('/admin/blog-posts/assistant', $this->request('xx'))->assertUnprocessable()->assertJsonValidationErrors('locale');

        $this->actingAs($limited = $this->staff(['admin.access', 'blog.view', 'blog.manage']));
        $this->withSession(['password_hash_web' => $limited->getAuthPassword()]);
        $this->get('/admin/blog-posts/create')->assertInertia(fn (Assert $page) => $page->where('aiReady', false));
        $this->postJson('/admin/blog-posts/assistant', $this->request())->assertForbidden();
        Http::assertNothingSent();
    }
}
