<?php

namespace Tests\Feature;

use App\Catalog\Application\Command\ManageCategoriesHandler;
use App\Catalog\Application\Command\SaveCategoryData;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Storefront\Application\Service\StorefrontRegistry;
use App\Storefront\Infrastructure\LayoutRenderer;
use App\Storefront\Infrastructure\LayoutStore;
use App\Storefront\Presentation\Http\StorefrontLocaleController;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class BlogTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aN1sAAAAASUVORK5CYII=';

    private UserRecord $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::flush();
        Storage::fake('local');
        app()->setLocale('en');
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actor = $this->staff(super: true);
    }

    private function locale(string $code): int
    {
        return (int) DB::table('locales')->where('code', $code)->value('id');
    }

    /** @return array<string, mixed> */
    private function blogPayload(array $overrides = []): array
    {
        return array_replace_recursive(['status' => 'active', 'position' => 1, 'facebook_type' => 'blog', 'translations' => [
            'en' => ['name' => 'Company news', 'content' => '<p>What we are up to.<script>x()</script></p>', 'seo_title' => 'News SEO', 'meta_description' => 'News from us', 'meta_keywords' => 'news'],
            'el' => ['name' => 'Νέα εταιρείας', 'content' => '<p>Τα νέα μας.</p>'],
        ]], $overrides);
    }

    /** @return array<string, mixed> */
    private function postPayload(string $blog, array $overrides = []): array
    {
        return array_replace_recursive(['blog_id' => $blog, 'status' => 'active', 'published_at' => now()->subDay()->toIso8601String(), 'facebook_type' => 'article', 'translations' => [
            'en' => ['name' => 'Summer launch', 'content' => '<p>Long story</p>', 'excerpt' => 'Short story', 'cover_alt' => 'A beach', 'tags' => 'Summer, news, summer', 'seo_title' => 'Launch SEO', 'meta_description' => 'Launch details'],
            'el' => ['name' => 'Καλοκαιρινή κυκλοφορία', 'content' => '<p>Ιστορία</p>', 'tags' => 'Καλοκαίρι'],
        ]], $overrides);
    }

    private function blog(array $overrides = []): string
    {
        $this->actingAs($this->actor)->post('/admin/blogs', $this->blogPayload($overrides))->assertSessionHasNoErrors()->assertRedirect();

        return (string) DB::table('blogs')->max('id');
    }

    private function createPost(string $blog, array $overrides = []): string
    {
        $this->actingAs($this->actor)->post('/admin/blog-posts', $this->postPayload($blog, $overrides))->assertSessionHasNoErrors()->assertRedirect();

        return (string) DB::table('blog_posts')->max('id');
    }

    private function slug(string $table, string $owner, string $id, string $code): string
    {
        return (string) DB::table($table)->where($owner, $id)->where('locale_id', $this->locale($code))->value('slug');
    }

    /** @return array<string, mixed> The main content block of the rendered storefront page. */
    private function main(TestResponse $response): array
    {
        foreach (json_decode((string) json_encode($response->viewData('page')), true)['props']['storefront']['regions']['content'] as $grid) {
            foreach ($grid['blocks'] as $block) {
                if ($block['template'] === 'main_content') {
                    return $block['content'];
                }
            }
        }
        self::fail('No main content block.');
    }

    public function test_the_database_keeps_posts_tied_to_one_blog_with_valid_translated_texts(): void
    {
        $blog = $this->blog();
        $post = $this->createPost($blog);
        self::assertSame($blog, (string) DB::table('blog_posts')->where('id', $post)->value('blog_id'));
        self::assertSame(['Summer', 'news'], DB::table('blog_post_tags')->where('post_id', $post)->where('locale_id', $this->locale('en'))->orderBy('name', 'desc')->pluck('name')->all());

        // A blog with posts cannot be deleted; a post needs an existing blog.
        $this->assertQueryFails(fn () => DB::table('blogs')->where('id', $blog)->delete());
        $this->assertQueryFails(fn () => DB::table('blog_posts')->insert(['blog_id' => 999999]));
        // Required, well-formed texts and statuses.
        $this->assertQueryFails(fn () => DB::table('blog_translations')->where('blog_id', $blog)->update(['name' => ' ']));
        $this->assertQueryFails(fn () => DB::table('blog_post_translations')->where('post_id', $post)->update(['slug' => 'Not Valid']));
        $this->assertQueryFails(fn () => DB::table('blog_posts')->where('id', $post)->update(['status' => 'draft']));
        $this->assertQueryFails(fn () => DB::table('blog_posts')->where('id', $post)->update(['available_from' => now(), 'available_until' => now()->subDay()]));
        $this->assertQueryFails(fn () => DB::table('blog_post_tags')->insert(['post_id' => $post, 'locale_id' => $this->locale('en'), 'name' => 'SUMMER']));
        // One SEO name per language.
        $other = $this->createPost($blog, ['translations' => ['en' => ['slug' => 'other']]]);
        $this->assertQueryFails(fn () => DB::table('blog_post_translations')->where('post_id', $other)->where('locale_id', $this->locale('en'))->update(['slug' => $this->slug('blog_post_translations', 'post_id', $post, 'en')]));

        // Tags go with their language's translation, and everything goes with the post.
        DB::table('blog_post_translations')->where('post_id', $post)->where('locale_id', $this->locale('el'))->delete();
        self::assertSame(0, DB::table('blog_post_tags')->where('post_id', $post)->where('locale_id', $this->locale('el'))->count());
        DB::table('blog_posts')->where('id', $post)->delete();
        self::assertSame(0, DB::table('blog_post_tags')->where('post_id', $post)->count());
        self::assertSame(0, DB::table('blog_post_translations')->where('post_id', $post)->count());
    }

    private function assertQueryFails(callable $query): void
    {
        DB::beginTransaction();
        try {
            $query();
            self::fail('The database accepted an invalid change.');
        } catch (QueryException) {
            self::assertTrue(true);
        } finally {
            DB::rollBack();
        }
    }

    public function test_blogs_are_listed_created_edited_validated_and_deleted_in_the_admin(): void
    {
        // Marketing → Blog holds the blogs and their posts.
        $navigation = json_encode($this->actingAs($this->actor)->get('/admin/blogs')->viewData('page')['props']['navigation']);
        self::assertStringContainsString('admin\\/blogs', $navigation);
        self::assertStringContainsString('admin\\/blog-posts', $navigation);
        $blog = $this->blog();
        self::assertSame('company-news', $this->slug('blog_translations', 'blog_id', $blog, 'en'));
        self::assertSame('nea-etairias', $this->slug('blog_translations', 'blog_id', $blog, 'el'));
        self::assertStringNotContainsString('script', (string) DB::table('blog_translations')->where('blog_id', $blog)->where('locale_id', $this->locale('en'))->value('content'));

        $this->get('/admin/blogs')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Blogs/Index')->where('kind', 'blog')
            ->where('rows.total', 1)->where('rows.data.0.name', 'Company news')->where('rows.data.0.posts_count', 0)->where('rows.data.0.state', 'live'));
        $this->get('/admin/blogs?search=nothing')->assertInertia(fn (Assert $p) => $p->where('rows.total', 0));
        $this->get('/admin/blogs?status=hidden')->assertInertia(fn (Assert $p) => $p->where('rows.total', 0));
        $this->get('/admin/blogs/'.$blog.'/edit')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Blogs/Form')
            ->where('item.translations.el.name', 'Νέα εταιρείας')->where('item.translations.en.seo_title', 'News SEO')->where('item.translations.en.meta_description', 'News from us')
            ->where('item.preview_url', '/blog/company-news?preview=1'));

        // Edit: status, availability and a chosen SEO name are saved and reloaded.
        $this->post('/admin/blogs/'.$blog, $this->blogPayload(['version' => 1, 'status' => 'hidden', 'available' => true, 'available_from' => '2026-01-01T00:00:00Z', 'translations' => ['en' => ['slug' => 'news']]]))->assertSessionHasNoErrors();
        $this->get('/admin/blogs/'.$blog.'/edit')->assertInertia(fn (Assert $p) => $p->where('item.status', 'hidden')->where('item.translations.en.slug', 'news')->where('item.available_from', '2026-01-01T00:00:00Z')->where('item.state', 'hidden'));

        // Validation: a default-language name, valid choices, ordered dates, free SEO names, current versions.
        $this->post('/admin/blogs', $this->blogPayload(['status' => 'draft', 'facebook_type' => 'nope', 'translations' => ['en' => ['name' => ''], 'el' => ['name' => '']]]))
            ->assertSessionHasErrors(['status', 'facebook_type', 'translations.'.app(LocaleCatalog::class)->defaultCode().'.name']);
        $this->post('/admin/blogs', $this->blogPayload(['available' => true]))->assertSessionHasErrors('available_until');
        $this->post('/admin/blogs', $this->blogPayload(['translations' => ['en' => ['slug' => 'news']]]))->assertSessionHasErrors('translations.en.slug');
        $this->post('/admin/blogs/'.$blog, $this->blogPayload(['version' => 1]))->assertSessionHasErrors('version');

        // A blog with posts is not deleted; an empty one is.
        $this->createPost($blog);
        $this->delete('/admin/blogs/'.$blog, ['version' => 2])->assertSessionHasErrors('blog');
        $this->post('/admin/blogs/bulk', ['ids' => [$blog], 'action' => 'delete'])->assertSessionHasErrors('blog');
        $this->post('/admin/blogs/bulk', ['ids' => [$blog], 'action' => 'disabled'])->assertSessionHasNoErrors();
        self::assertSame('disabled', DB::table('blogs')->where('id', $blog)->value('status'));
        $empty = $this->blog(['translations' => ['en' => ['name' => 'Empty'], 'el' => ['name' => 'Άδειο']]]);
        $this->delete('/admin/blogs/'.$empty, ['version' => 1])->assertRedirect('/admin/blogs');
        self::assertFalse(DB::table('blogs')->where('id', $empty)->exists());

        // Viewing needs blog.view; changing needs blog.manage.
        $this->flushSession();
        $this->actingAs($this->staff(['admin.access', 'blog.view']));
        $this->get('/admin/blogs')->assertOk();
        $this->get('/admin/blogs/'.$blog.'/edit')->assertOk();
        $this->get('/admin/blogs/create')->assertForbidden();
        $this->post('/admin/blogs', $this->blogPayload())->assertForbidden();
        $this->post('/admin/blog-posts/bulk', ['ids' => [$blog], 'action' => 'disabled'])->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->staff(['admin.access']));
        $this->get('/admin/blogs')->assertForbidden();
    }

    public function test_posts_save_and_reload_their_blog_cover_tags_translations_and_publication(): void
    {
        $blog = $this->blog();
        $other = $this->blog(['translations' => ['en' => ['name' => 'Guides'], 'el' => ['name' => 'Οδηγοί']]]);
        $this->actingAs($this->actor)->post('/admin/blog-posts', [...$this->postPayload($blog), 'cover' => UploadedFile::fake()->createWithContent('cover.png', base64_decode(self::PNG))])->assertSessionHasNoErrors();
        $post = (string) DB::table('blog_posts')->max('id');
        $media = DB::table('blog_posts')->where('id', $post)->value('cover_media_id');
        self::assertNotNull($media);
        self::assertSame('summer-launch', $this->slug('blog_post_translations', 'post_id', $post, 'en'));

        $this->get('/admin/blog-posts/'.$post.'/edit')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Blogs/Form')->where('kind', 'post')
            ->where('item.blog_id', $blog)->where('item.translations.en.tags', 'news, Summer')->where('item.translations.el.tags', 'Καλοκαίρι')
            ->where('item.translations.en.excerpt', 'Short story')->where('item.translations.en.cover_alt', 'A beach')->where('item.translations.el.name', 'Καλοκαιρινή κυκλοφορία')
            ->where('item.cover.url', '/admin/blog-posts/'.$post.'/cover?v='.$media)->where('item.state', 'live')->where('item.preview_url', '/blog/company-news/summer-launch?preview=1')
            ->has('blogs', 2));
        $this->get('/admin/blog-posts/'.$post.'/cover')->assertOk();
        $this->get('/admin/blog-posts?blog='.$blog)->assertInertia(fn (Assert $p) => $p->where('rows.total', 1)->where('rows.data.0.blog', 'Company news'));
        $this->get('/admin/blog-posts?blog='.$other)->assertInertia(fn (Assert $p) => $p->where('rows.total', 0));

        // Moving to another blog, a draft (no date), new tags; the cover stays until removed.
        $this->post('/admin/blog-posts/'.$post, $this->postPayload($other, ['version' => 1, 'published_at' => '', 'translations' => ['en' => ['tags' => 'Guides']]]))->assertSessionHasNoErrors();
        $this->get('/admin/blog-posts/'.$post.'/edit')->assertInertia(fn (Assert $p) => $p->where('item.blog_id', $other)->where('item.published_at', null)->where('item.state', 'draft')->where('item.translations.en.tags', 'Guides'));
        self::assertSame($media, DB::table('blog_posts')->where('id', $post)->value('cover_media_id'));
        $this->post('/admin/blog-posts/'.$post, $this->postPayload($other, ['version' => 2, 'remove_cover' => '1']))->assertSessionHasNoErrors();
        self::assertNull(DB::table('blog_posts')->where('id', $post)->value('cover_media_id'));

        // The optional phone cover saves, serves and is removed on its own, leaving the main cover alone.
        $png = fn (): UploadedFile => UploadedFile::fake()->createWithContent('cover.png', base64_decode(self::PNG));
        $this->post('/admin/blog-posts/'.$post, [...$this->postPayload($other, ['version' => 3]), 'cover' => $png(), 'cover_mobile' => $png()])->assertSessionHasNoErrors();
        $mobile = DB::table('blog_posts')->where('id', $post)->value('cover_mobile_media_id');
        self::assertNotNull($mobile);
        $this->get('/admin/blog-posts/'.$post.'/edit')->assertInertia(fn (Assert $p) => $p->where('item.cover_mobile.url', '/admin/blog-posts/'.$post.'/cover-mobile?v='.$mobile));
        $this->get('/admin/blog-posts/'.$post.'/cover-mobile')->assertOk();
        $this->post('/admin/blog-posts/'.$post, $this->postPayload($other, ['version' => 4, 'remove_cover_mobile' => '1']))->assertSessionHasNoErrors();
        self::assertNull(DB::table('blog_posts')->where('id', $post)->value('cover_mobile_media_id'));
        self::assertNotNull(DB::table('blog_posts')->where('id', $post)->value('cover_media_id'));
        $this->get('/admin/blog-posts/'.$post.'/cover-mobile')->assertNotFound();
        $this->post('/admin/blog-posts/'.$post, [...$this->postPayload($other, ['version' => 5]), 'cover_mobile' => UploadedFile::fake()->createWithContent('m.png', 'not an image')])->assertSessionHasErrors('cover_mobile');

        // The parent blog and the texts are checked on the server.
        $this->post('/admin/blog-posts', $this->postPayload('999999'))->assertSessionHasErrors('blog_id');
        $this->post('/admin/blog-posts', $this->postPayload(''))->assertSessionHasErrors('blog_id');
        $this->post('/admin/blog-posts', $this->postPayload($blog, ['published_at' => 'soon', 'translations' => ['en' => ['tags' => str_repeat('x', 61)], 'el' => ['name' => '', 'tags' => 'orphan']]]))
            ->assertSessionHasErrors(['published_at', 'translations.en.tags', 'translations.el.name']);
        $this->post('/admin/blog-posts', [...$this->postPayload($blog), 'cover' => UploadedFile::fake()->createWithContent('cover.png', 'not an image')])->assertSessionHasErrors('cover');
        $this->delete('/admin/blog-posts/'.$post, ['version' => 5])->assertRedirect('/admin/blog-posts');
        self::assertFalse(DB::table('blog_posts')->where('id', $post)->exists());
    }

    public function test_the_blog_page_lists_only_visible_posts_in_the_visitors_language_with_its_seo(): void
    {
        $blog = $this->blog();
        $live = $this->createPost($blog);
        $this->createPost($blog, ['translations' => ['en' => ['name' => 'Draft post'], 'el' => ['name' => 'Πρόχειρο']], 'published_at' => '']);
        $this->createPost($blog, ['translations' => ['en' => ['name' => 'Future post'], 'el' => ['name' => 'Μελλοντικό']], 'published_at' => now()->addDay()->toIso8601String()]);
        $this->createPost($blog, ['translations' => ['en' => ['name' => 'Disabled post'], 'el' => ['name' => 'Ανενεργό']], 'status' => 'disabled']);
        $hidden = $this->createPost($blog, ['translations' => ['en' => ['name' => 'Hidden post'], 'el' => ['name' => 'Κρυφό']], 'status' => 'hidden']);
        $this->createPost($blog, ['translations' => ['en' => ['name' => 'Expired post'], 'el' => ['name' => 'Ληγμένο']], 'available' => true, 'available_until' => now()->subHour()->toIso8601String()]);
        auth()->logout();

        $response = $this->get('/blog/company-news')->assertOk();
        $main = $this->main($response);
        self::assertSame(['Summer launch'], array_column($main['blog']['posts'], 'name'));
        self::assertSame('/blog/company-news/summer-launch', $main['blog']['posts'][0]['url']);
        self::assertSame('Short story', $main['blog']['posts'][0]['excerpt']);
        self::assertStringNotContainsString('script', $main['blog']['content']);
        $html = $response->getContent();
        self::assertStringContainsString('<link rel="canonical" href="'.rtrim((string) config('app.url'), '/').'/blog/company-news" data-inertia="seo-canonical">', $html);
        self::assertStringContainsString('<meta name="description" content="News from us" data-inertia="seo-description">', $html);
        self::assertStringContainsString('<title>News SEO', $html);
        self::assertStringNotContainsString('Draft post', $html);
        self::assertStringNotContainsString('Hidden post', $html);
        $this->get('/blog/company-news?page=2')->assertNotFound();
        $this->get('/blog/company-news?page=x')->assertNotFound();
        // Blog addresses live under /blog only.
        $this->get('/company-news')->assertNotFound();
        $this->get('/company-news/summer-launch')->assertNotFound();

        // Greek visitors get the Greek texts; a post without Greek falls back to the default language.
        DB::table('blog_post_translations')->where('post_id', $live)->where('locale_id', $this->locale('el'))->update(['excerpt' => '']);
        $greek = $this->main($this->withSession([StorefrontLocaleController::SESSION_KEY => 'el'])->get('/blog/nea-etairias')->assertOk());
        self::assertSame('Νέα εταιρείας', $greek['blog']['name']);
        self::assertSame('Καλοκαιρινή κυκλοφορία', $greek['blog']['posts'][0]['name']);
        self::assertSame('/blog/nea-etairias/kalokairini-kikloforia', $greek['blog']['posts'][0]['url']);

        $this->withSession([StorefrontLocaleController::SESSION_KEY => 'en']);
        // Hidden posts are reachable by address; hidden blogs too, but they leave the sitemap; disabled ones are gone.
        $this->get('/blog/company-news/'.$this->slug('blog_post_translations', 'post_id', $hidden, 'en'))->assertOk();
        $this->get('/sitemap')->assertOk()->assertSee('\/blog\/company-news', false);
        DB::table('blogs')->where('id', $blog)->update(['status' => 'hidden']);
        $this->get('/blog/company-news')->assertOk();
        $this->get('/sitemap')->assertOk()->assertDontSee('blog\/company-news', false);
        DB::table('blogs')->where('id', $blog)->update(['status' => 'disabled']);
        $this->get('/blog/company-news')->assertNotFound();
        $this->get('/blog/company-news/summer-launch')->assertNotFound();
        DB::table('blogs')->where('id', $blog)->update(['status' => 'active', 'available_from' => now()->addDay()]);
        $this->get('/blog/company-news')->assertNotFound();
    }

    public function test_a_post_page_shows_its_content_parent_and_social_metadata_and_never_leaks_unpublished_posts(): void
    {
        $blog = $this->blog();
        $this->blog(['translations' => ['en' => ['name' => 'Guides'], 'el' => ['name' => 'Οδηγοί']]]);
        $png = fn (): UploadedFile => UploadedFile::fake()->createWithContent('cover.png', base64_decode(self::PNG));
        $this->actingAs($this->actor)->post('/admin/blog-posts', [...$this->postPayload($blog), 'cover' => $png(), 'cover_mobile' => $png()])->assertSessionHasNoErrors();
        $post = (string) DB::table('blog_posts')->max('id');
        auth()->logout();

        $response = $this->get('/blog/company-news/summer-launch')->assertOk();
        $data = $this->main($response)['post'];
        self::assertSame('Summer launch', $data['name']);
        self::assertSame('<p>Long story</p>', $data['content']);
        self::assertSame(['Company news', '/blog/company-news'], [$data['blog']['name'], $data['blog']['url']]);
        self::assertSame(['news', 'Summer'], $data['tags']);
        self::assertSame('A beach', $data['cover_alt']);
        $base = rtrim((string) config('app.url'), '/');
        $html = $response->getContent();
        self::assertStringContainsString('<link rel="canonical" href="'.$base.'/blog/company-news/summer-launch" data-inertia="seo-canonical">', $html);
        self::assertStringContainsString('<title>Launch SEO', $html);
        self::assertStringContainsString('<meta name="description" content="Launch details" data-inertia="seo-description">', $html);
        self::assertStringContainsString('<meta property="og:type" content="article" data-inertia="seo-og-type">', $html);
        self::assertStringContainsString('<meta property="og:url" content="'.$base.'/blog/company-news/summer-launch" data-inertia="seo-og-url">', $html);
        self::assertStringContainsString('<meta property="og:image" content="'.$base.$data['cover']['url'].'" data-inertia="seo-og-image">', $html);
        $this->get($data['cover']['url'])->assertOk();
        // The phone cover is public with the post, and is a different picture.
        self::assertNotSame($data['cover']['url'], $data['cover_mobile']['url']);
        $this->get($data['cover_mobile']['url'])->assertOk();

        // Greek address and texts.
        $greek = $this->main($this->withSession([StorefrontLocaleController::SESSION_KEY => 'el'])->get('/blog/nea-etairias/kalokairini-kikloforia')->assertOk())['post'];
        self::assertSame(['Καλοκαιρινή κυκλοφορία', ['Καλοκαίρι'], '/blog/nea-etairias'], [$greek['name'], $greek['tags'], $greek['blog']['url']]);

        // Wrong blog, missing post, or a post that is not public: not found, and neither is its cover.
        $this->get('/blog/guides/summer-launch')->assertNotFound();
        $this->get('/blog/company-news/missing')->assertNotFound();
        $this->get('/blog/company-news/summer-launch/extra')->assertNotFound();
        foreach ([['published_at' => null], ['published_at' => now()->addDay()], ['status' => 'disabled'], ['available_until' => now()->subMinute()], ['available_from' => now()->addMinute()]] as $state) {
            DB::table('blog_posts')->where('id', $post)->update(['status' => 'active', 'published_at' => now()->subDay(), 'available_from' => null, 'available_until' => null]);
            DB::table('blog_posts')->where('id', $post)->update($state);
            $this->get('/blog/company-news/summer-launch')->assertNotFound();
            $this->get($data['cover']['url'])->assertNotFound();
            $this->get($data['cover_mobile']['url'])->assertNotFound();
        }
        // Staff may preview it (never indexed); visitors may not.
        $this->get('/blog/company-news/summer-launch?preview=1')->assertNotFound();
        $this->actingAs($this->actor)->get('/blog/company-news/summer-launch?preview=1')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_demo_posts_feed_the_blog_posts_block_newest_first_by_blog_or_by_hand(): void
    {
        $this->artisan('cms:blog-demo', ['actor' => $this->actor->id])->expectsOutputToContain('Created 2 sample blogs and 8 posts')->assertSuccessful();
        $this->artisan('cms:blog-demo', ['actor' => $this->actor->id])->expectsOutputToContain('Created 0 sample blogs and 0 posts')->assertSuccessful();
        $id = fn (string $name): string => (string) DB::table('blog_post_translations')->where('name', $name)->value('post_id');
        $draft = $id('Our new online store is live');
        DB::table('blog_posts')->where('id', $draft)->update(['published_at' => null]);

        $definition = app(StorefrontRegistry::class)->type('blog_posts')->definition();
        self::assertSame(['blog_carousel', 'blog_single'], $definition['templates']);
        $layout = app(LayoutStore::class)->read('homepage');
        $block = ['id' => (string) Str::uuid(), 'type' => 'blog_posts', 'name' => 'Blog', 'template' => 'blog_carousel', 'css_class' => '', 'settings' => $definition['defaults'], 'selections' => [], 'translations' => ['en' => ['title' => 'From the blog', 'html' => ''], 'el' => ['title' => 'Από το blog', 'html' => '']]];
        $placement = (string) Str::uuid();
        $save = function (array $block) use (&$layout, $placement): array {
            $layout['placements'] = [['id' => $placement, 'grid_id' => $layout['grids'][0]['id'], 'position' => 1, 'enabled' => true, 'mobile' => true, 'tablet' => true, 'desktop' => true, 'block' => $block]];
            $this->actingAs($this->actor)->put('/admin/layouts/homepage', $layout)->assertSessionHasNoErrors()->assertRedirect();
            $layout = app(LayoutStore::class)->read('homepage');
            $page = json_decode((string) json_encode(app(LayoutRenderer::class)->page('homepage', 'en', null)), true);

            return $page['regions']['content'][0]['blocks'][0]['content'] ?? [];
        };

        // Latest: the newest published posts of every blog, drafts left out, with links, covers and the card settings.
        $content = $save($block);
        self::assertSame(['How to choose a smartphone in 2026', 'Free shipping on orders over €50', 'Laptop buying guide for students', 'Black Friday is coming', 'Tablet or laptop? Picking the right one', 'Gift cards are now available'], array_column($content['items'], 'name'));
        self::assertStringStartsWith('/blog/news/', collect($content['items'])->firstWhere('name', 'Free shipping on orders over €50')['url']);
        self::assertStringStartsWith('/storefront/media/blog-post/', $content['items'][0]['image']['url']);
        self::assertSame([true, true, 3], [$content['display']['show_date'], $content['display']['show_excerpt'], $content['display']['per_row']]);
        $this->get($content['items'][0]['image']['url'])->assertOk();

        // One blog only.
        $guides = (string) DB::table('blog_translations')->where('name', 'Buying guides')->value('blog_id');
        $content = $save([...$block, 'selections' => ['blog' => [$guides]]]);
        self::assertSame(['How to choose a smartphone in 2026', 'Laptop buying guide for students', 'Tablet or laptop? Picking the right one', 'Wireless headphones: what to look for'], array_column($content['items'], 'name'));

        // By hand: the merchant's order; a post that is not public is skipped; the single template shows one.
        $content = $save([...$block, 'settings' => [...$definition['defaults'], 'source' => 'manual'], 'selections' => ['items' => [$id('Laptop buying guide for students'), $draft, $id('Gift cards are now available')]]]);
        self::assertSame(['Laptop buying guide for students', 'Gift cards are now available'], array_column($content['items'], 'name'));
        $content = $save([...$block, 'template' => 'blog_single', 'settings' => [...$definition['defaults'], 'source' => 'manual'], 'selections' => ['items' => [$id('Gift cards are now available')]]]);
        self::assertCount(1, $content['items']);
        $this->actingAs($this->actor)->put('/admin/layouts/homepage', [...$layout, 'placements' => [[...$layout['placements'][0], 'block' => [...$layout['placements'][0]['block'], 'selections' => ['items' => ['999999']]]]]])->assertSessionHasErrors();
    }

    public function test_a_category_links_blog_posts_in_order_and_shows_the_public_ones_above_its_questions(): void
    {
        $blog = $this->blog();
        $first = $this->createPost($blog);
        $second = $this->createPost($blog, ['translations' => ['en' => ['name' => 'Second post'], 'el' => ['name' => 'Δεύτερο']]]);
        $draft = $this->createPost($blog, ['translations' => ['en' => ['name' => 'Draft post'], 'el' => ['name' => 'Πρόχειρο']], 'published_at' => '']);
        $category = app(ManageCategoriesHandler::class)->save((string) $this->actor->id, null, new SaveCategoryData(null, 1, 'active', ['en' => ['name' => 'Shoes', 'slug' => 'shoes', 'description' => '<p>Shoes</p>', 'seo_title' => 'Shoes', 'seo_description' => 'Shoes for everyone', 'image_alt' => 'Shoes', 'meta_keywords' => 'shoes'], 'el' => ['name' => 'Παπούτσια', 'slug' => 'papoutsia', 'description' => '<p>Παπούτσια</p>', 'seo_title' => 'Παπούτσια', 'seo_description' => 'Παπούτσια για όλους', 'image_alt' => 'Παπούτσια', 'meta_keywords' => 'παπούτσια']]));
        $update = fn (array $fields, int $version) => $this->actingAs($this->actor)->put('/admin/categories/'.$category, ['parent_id' => '', 'position' => 1, 'status' => 'active', 'version' => $version, ...$fields]);

        $update(['blog_posts_submitted' => '1', 'blog_post_ids' => [$second, $draft, $first]], 1)->assertSessionHasNoErrors();
        $this->get('/admin/categories/'.$category.'/edit')->assertInertia(fn (Assert $p) => $p->where('category.blogPostIds', [$second, $draft, $first])->where('blogPostOptions', fn ($rows) => count($rows) === 3));
        // The page shows the public posts in the merchant's order; the draft stays hidden.
        auth()->logout();
        $main = $this->main($this->get('/shoes')->assertOk());
        $posts = $main['listing']['category']['posts'];
        self::assertSame(['Second post', 'Summer launch'], array_column($posts, 'name'));
        self::assertStringStartsWith('/blog/company-news/', $posts[0]['url']);

        // A save without the field keeps them; an empty submitted list clears them; unknown posts are refused.
        $update([], 2)->assertSessionHasNoErrors();
        self::assertSame(3, DB::table('category_blog_posts')->where('category_id', $category)->count());
        $update(['blog_post_ids' => ['999999']], 3)->assertSessionHasErrors('blog_post_ids');
        $update(['blog_posts_submitted' => '1'], 3)->assertSessionHasNoErrors();
        self::assertSame(0, DB::table('category_blog_posts')->where('category_id', $category)->count());
        // Staff who may not see blogs cannot change the links.
        DB::table('category_blog_posts')->insert(['category_id' => $category, 'post_id' => $first, 'position' => 1]);
        $editor = $this->staff(['admin.access', 'categories.view', 'categories.update']);
        $this->actingAs($editor)->put('/admin/categories/'.$category, ['parent_id' => '', 'position' => 1, 'status' => 'active', 'version' => 4, 'blog_posts_submitted' => '1']);
        self::assertSame(1, DB::table('category_blog_posts')->where('category_id', $category)->count());
    }
}
