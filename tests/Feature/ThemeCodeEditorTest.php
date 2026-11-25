<?php

namespace Tests\Feature;

use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Storefront\Infrastructure\ThemeCodeEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class ThemeCodeEditorTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    private string $project;

    private string $root;

    private UserRecord $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        $this->actingAs($this->actor = $this->staff(super: true));
        $this->project = sys_get_temp_dir().'/theme-editor-'.bin2hex(random_bytes(8));
        $this->root = $this->project.'/'.ThemeCodeEditor::ROOT;
        mkdir($this->root.'/parts', 0700, true);
        file_put_contents($this->root.'/theme.css', "body { color: red; }\n");
        file_put_contents($this->root.'/parts/Card.tsx', 'export const Card = () => <div />;');
        $this->app->instance(ThemeCodeEditor::class, new ThemeCodeEditor($this->project, $this->project.'/drafts'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->project);
        parent::tearDown();
    }

    private function open(string $path = 'theme.css'): array
    {
        return $this->getJson('/admin/code-editor/file?'.http_build_query(['path' => $path]))->assertOk()->json();
    }

    public function test_lists_only_existing_approved_files_and_saves_private_audited_drafts(): void
    {
        foreach (['.env', 'backend.php', 'theme.scss', 'vite.config.ts', 'image.svg', 'types.d.ts'] as $file) {
            file_put_contents($this->root.'/'.$file, 'secret');
        }
        mkdir($this->root.'/config');
        file_put_contents($this->root.'/config/secret.ts', 'secret');
        $this->get('/admin/code-editor')->assertInertia(fn (Assert $page) => $page->component('Admin/CodeEditor/Index')->where('files', ['parts/Card.tsx', 'theme.css']));
        $file = $this->open();
        $content = "\n  body { color: blue; }\n\n";
        $this->postJson('/admin/code-editor/file', [...$file, 'content' => $content])->assertOk()->assertJsonPath('content', $content)->assertJsonPath('pending', true);
        $this->assertSame("body { color: red; }\n", file_get_contents($this->root.'/theme.css'));
        $this->assertSame($content, $this->open()['content']);
        $audit = DB::table('audit_logs')->where('action', 'theme_code.saved')->sole();
        $this->assertSame((string) $this->actor->id, (string) $audit->actor_id);
        $this->assertSame('theme.css', $audit->entity_id);
        $this->assertStringNotContainsString('blue', json_encode($audit));
    }

    public function test_rejects_traversal_disallowed_extensions_absolute_paths_and_symlinks(): void
    {
        file_put_contents($this->project.'/private.css', 'secret');
        symlink($this->project.'/private.css', $this->root.'/linked.css');
        symlink($this->root.'/parts', $this->root.'/linked');
        foreach (['../private.css', '%2e%2e/private.css', '/etc/passwd', 'foo\\theme.css', 'theme.css/../theme.css', 'linked.css', 'linked/Card.tsx', '.env', 'backend.php', 'config/secret.ts'] as $path) {
            $this->getJson('/admin/code-editor/file?'.http_build_query(['path' => $path]))->assertNotFound();
            $this->postJson('/admin/code-editor/file', ['path' => $path, 'content' => '', 'version' => str_repeat('a', 64)])->assertNotFound();
        }
    }

    public function test_all_endpoints_require_the_explicit_permission(): void
    {
        $this->actingAs($this->staff(['admin.access', 'layouts.manage', 'settings.manage']));
        $this->get('/admin/code-editor')->assertForbidden();
        $this->getJson('/admin/code-editor/file?path=theme.css')->assertForbidden();
        $this->postJson('/admin/code-editor/file', [])->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->staff(['admin.access', 'theme-code.manage']));
        $this->open();
    }

    public function test_rejects_stale_draft_versions_and_source_changes(): void
    {
        $file = $this->open();
        $saved = $this->postJson('/admin/code-editor/file', [...$file, 'content' => 'a{}'])->assertOk()->json();
        $this->postJson('/admin/code-editor/file', [...$file, 'content' => 'b{}'])->assertConflict();
        file_put_contents($this->root.'/theme.css', 'deployed{}');
        $this->postJson('/admin/code-editor/file', [...$saved, 'content' => 'c{}'])->assertConflict();
        $conflict = $this->open();
        $this->assertTrue($conflict['conflict']);
        $this->postJson('/admin/code-editor/file', [...$conflict, 'content' => 'd{}'])->assertConflict();
        $this->assertSame('a{}', $this->open()['content']);
    }

    public function test_missing_files_size_limits_and_save_failure_preserve_existing_data(): void
    {
        $file = $this->open();
        $this->postJson('/admin/code-editor/file', [...$file, 'content' => str_repeat('x', ThemeCodeEditor::LIMIT + 1)])->assertUnprocessable();
        $this->postJson('/admin/code-editor/file', [...$file, 'content' => "x\0y"])->assertUnprocessable();
        mkdir($this->project.'/drafts/lock');
        $this->postJson('/admin/code-editor/file', [...$file, 'content' => 'x{}'])->assertConflict();
        rmdir($this->project.'/drafts/lock');
        unlink($this->root.'/theme.css');
        $this->postJson('/admin/code-editor/file', [...$file, 'content' => 'x{}'])->assertNotFound();
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'theme_code.saved')->count());
    }

    public function test_cannot_cross_theme_scope_or_hardlinks_and_does_not_accept_large_source(): void
    {
        config(['storefront.theme' => 'another']);
        $this->get('/admin/code-editor')->assertInertia(fn (Assert $page) => $page->where('files', [])->where('problem', __('code_editor.theme_unavailable')));
        $this->getJson('/admin/code-editor/file?path=theme.css')->assertUnprocessable();
        config(['storefront.theme' => 'default']);
        link($this->root.'/theme.css', $this->root.'/hard.css');
        $this->getJson('/admin/code-editor/file?path=hard.css')->assertUnprocessable();
        file_put_contents($this->root.'/large.css', str_repeat('x', ThemeCodeEditor::LIMIT + 1));
        $this->getJson('/admin/code-editor/file?path=large.css')->assertUnprocessable();
    }

    public function test_storage_failure_is_actionable_and_source_paths_are_not_exposed(): void
    {
        $file = $this->open();
        file_put_contents($this->project.'/blocked', 'not a directory');
        $this->app->instance(ThemeCodeEditor::class, new ThemeCodeEditor($this->project, $this->project.'/blocked'));
        $response = $this->postJson('/admin/code-editor/file', [...$file, 'content' => 'changed{}'])->assertUnprocessable();
        $this->assertStringNotContainsString($this->project, $response->getContent());
        $this->assertSame("body { color: red; }\n", file_get_contents($this->root.'/theme.css'));
    }

    public function test_restoring_deployed_content_removes_the_pending_draft_and_csrf_is_enforced(): void
    {
        $file = $this->open();
        $saved = $this->postJson('/admin/code-editor/file', [...$file, 'content' => 'changed{}'])->assertOk()->json();
        $this->postJson('/admin/code-editor/file', [...$saved, 'content' => $file['content']])->assertOk()->assertJsonPath('pending', false);
        $this->assertSame([], glob($this->project.'/drafts/*.json'));
        $this->app['env'] = 'production';
        try {
            $this->postJson('/admin/code-editor/file', $file)->assertStatus(419);
        } finally {
            $this->app['env'] = 'testing';
        }
    }
}
