<?php

namespace Tests\Feature;

use App\Audit\Infrastructure\AuditTables;
use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\Persistence\LocaleRecord;
use App\Media\Infrastructure\Persistence\MediaFileRecord;
use App\Media\Infrastructure\SharedMediaDeletion;
use App\Website\Domain\Service\MenuRules;
use App\Website\Infrastructure\MenuAdministration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class MenuTest extends TestCase
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

    private function menu(string $source = 'manual', string $name = 'Main menu'): string
    {
        return app(MenuAdministration::class)->save((string) $this->actor->id, null, ['status' => 'active', 'placement' => 'header', 'source' => $source, 'translations' => ['en' => ['name' => $name], 'el' => ['name' => 'Κεντρικό μενού']]]);
    }

    /** @param array<string,mixed> $extra */
    private function item(string $menu, array $extra = []): string
    {
        return app(MenuAdministration::class)->item((string) $this->actor->id, $menu, null, [...$this->fields($menu), ...$extra]);
    }

    /** @return array<string,mixed> */
    private function fields(string $menu): array
    {
        return ['version' => (int) DB::table('website_menus')->where('id', $menu)->value('lock_version'), 'parent_id' => null, 'status' => 'active', 'position' => 10, 'kind' => 'url', 'url' => '/about', 'category_id' => null, 'generate_children' => false, 'new_window' => false, 'translations' => ['en' => ['name' => 'About us', 'description' => 'Our company', 'image_alt' => 'Company'], 'el' => ['name' => 'Η εταιρεία']]];
    }

    private function category(?CategoryRecord $parent = null, string $status = 'active'): CategoryRecord
    {
        $position = ((int) DB::table('categories')->where('parent_id', $parent?->id)->max('position')) + 1;
        $c = CategoryRecord::create(['parent_id' => $parent?->id, 'position' => $position, 'status' => $status]);
        $c->translations()->create(['locale_id' => LocaleRecord::where('code', 'en')->sole()->id, 'name' => 'Category '.$c->id, 'slug' => 'category-'.$c->id]);

        return $c;
    }

    public function test_navigation_and_lists_reuse_authorized_website_group_with_search_and_pagination(): void
    {
        $menu = $this->menu();
        $this->get('/admin/menus')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Menus/Index')->where('menus.data.0.name', 'Main menu')->where('menus.total', 1)->where('navigation', fn ($n) => collect($n)->contains(fn ($i) => $i['id'] === 'core.menus' && $i['group']['id'] === 'core.website')));
        $this->get('/admin/menus?search=missing')->assertInertia(fn (Assert $p) => $p->where('menus.total', 0));
        $this->get('/admin/menus?search='.urlencode('Κεντρικό').'&placement=header&status=active&source=manual')->assertInertia(fn (Assert $p) => $p->where('menus.total', 1));
        for ($i = 0; $i < 25; $i++) {
            $this->menu(name: 'Menu '.$i);
        }
        $this->get('/admin/menus?page=2')->assertInertia(fn (Assert $p) => $p->has('menus.data', 1)->where('menus.total', 26));
        $this->get('/admin/menus/'.$menu.'/edit')->assertInertia(fn (Assert $p) => $p->where('menu.translations.el.name', 'Κεντρικό μενού'));
    }

    public function test_default_name_is_required_other_languages_are_optional_and_inactive_languages_are_rejected(): void
    {
        $data = ['status' => 'active', 'placement' => 'footer', 'source' => 'manual', 'translations' => ['en' => ['name' => '']]];
        $this->post('/admin/menus', $data)->assertSessionHasErrors('translations.en.name');
        $data['translations'] = ['en' => ['name' => 'Footer']];
        $this->post('/admin/menus', $data)->assertSessionHasNoErrors();
        $data['translations']['fr'] = ['name' => 'Inactive'];
        $this->post('/admin/menus', $data)->assertSessionHasErrors('translations');
        self::assertSame(1, DB::table('website_menus')->count());
    }

    public function test_manual_items_form_deep_trees_and_can_move_and_order_without_changing_siblings(): void
    {
        $menu = $this->menu();
        $root = $this->item($menu, ['position' => 30]);
        $child = $this->item($menu, ['parent_id' => $root, 'position' => 20]);
        $grandchild = $this->item($menu, ['parent_id' => $child]);
        $second = $this->item($menu, ['position' => 10]);
        $this->get('/admin/menus/'.$menu)->assertInertia(fn (Assert $p) => $p->where('items.data.0.id', $second)->where('items.data.1.children_count', 1));
        $this->get('/admin/menus/'.$menu.'?parent='.$child)->assertInertia(fn (Assert $p) => $p->where('items.data.0.id', $grandchild));
        $this->post('/admin/menus/'.$menu.'/items/'.$child, [...$this->fields($menu), 'parent_id' => $second, 'position' => 5])->assertSessionHasNoErrors();
        self::assertSame((int) $second, (int) DB::table('website_menu_items')->where('id', $child)->value('parent_id'));
        self::assertSame(30, (int) DB::table('website_menu_items')->where('id', $root)->value('position'));
        $this->get('/admin/menus/'.$menu.'/items/'.$root.'/edit?parent_search=About')->assertInertia(fn (Assert $p) => $p->where('parents', fn ($rows) => ! collect($rows)->contains('id', $root)));
    }

    public function test_server_and_database_reject_cycles_and_cross_menu_parents(): void
    {
        $menu = $this->menu();
        $root = $this->item($menu);
        $child = $this->item($menu, ['parent_id' => $root]);
        $other = $this->item($this->menu());
        foreach ([$child, $root, $other] as $parent) {
            $this->post('/admin/menus/'.$menu.'/items/'.$root, [...$this->fields($menu), 'parent_id' => $parent])->assertSessionHasErrors('parent_id');
        }
        DB::beginTransaction();
        try {
            DB::table('website_menu_items')->where('id', $root)->update(['parent_id' => $child]);
            self::fail('Cycle must be rejected');
        } catch (QueryException $e) {
            self::assertSame('23514', $e->errorInfo[0]);
        } finally {
            DB::rollBack();
        }
        self::assertNull(DB::table('website_menu_items')->where('id', $root)->value('parent_id'));
    }

    public function test_link_group_menus_are_manual_two_level_lists_without_category_links(): void
    {
        $id = $this->menu();
        $version = fn () => (int) DB::table('website_menus')->where('id', $id)->value('lock_version');
        $data = ['status' => 'active', 'placement' => 'other', 'source' => 'manual', 'style' => 'link_groups', 'translations' => ['en' => ['name' => 'Directory']]];
        // A menu without category items or deep levels can become a link-groups menu.
        $this->post('/admin/menus/'.$id, [...$data, 'version' => $version()])->assertSessionHasNoErrors();
        self::assertSame('link_groups', DB::table('website_menus')->where('id', $id)->value('style'));
        // Category-mode (generated) menus cannot be link groups.
        $this->post('/admin/menus/'.$id, [...$data, 'source' => 'categories', 'version' => $version(), 'confirmed' => true])->assertSessionHasErrors('source');
        $group = $this->item($id, ['kind' => 'heading', 'url' => null]);
        $link = $this->item($id, ['parent_id' => $group, 'kind' => 'url', 'url' => '/e-books']);
        // No category links, no generated children, and nothing under a second-level link.
        $category = $this->category();
        $this->post('/admin/menus/'.$id.'/items', [...$this->fields($id), 'kind' => 'category', 'url' => null, 'category_id' => $category->id])->assertSessionHasErrors('parent_id');
        $this->post('/admin/menus/'.$id.'/items', [...$this->fields($id), 'parent_id' => $link])->assertSessionHasErrors('parent_id');
        // A group that has links cannot be moved under another group.
        $other = $this->item($id, ['kind' => 'heading', 'url' => null]);
        $this->post('/admin/menus/'.$id.'/items/'.$group, [...$this->fields($id), 'kind' => 'heading', 'url' => null, 'parent_id' => $other])->assertSessionHasErrors('parent_id');
        // The database enforces the same rules.
        $this->expectException(QueryException::class);
        DB::table('website_menu_items')->insert(['menu_id' => $id, 'parent_id' => $link, 'status' => 'active', 'position' => 1, 'kind' => 'url', 'url' => '/deep']);
    }

    public function test_a_menu_with_category_links_or_deep_levels_cannot_become_link_groups(): void
    {
        $id = $this->menu();
        $top = $this->item($id);
        $this->item($id, ['parent_id' => $top]);
        $this->item($id, ['parent_id' => $this->item($id, ['parent_id' => $top])]);
        $data = ['version' => (int) DB::table('website_menus')->where('id', $id)->value('lock_version'), 'status' => 'active', 'placement' => 'other', 'source' => 'manual', 'style' => 'link_groups', 'translations' => ['en' => ['name' => 'Directory']]];
        $this->post('/admin/menus/'.$id, $data)->assertSessionHasErrors('style');
        self::assertSame('standard', DB::table('website_menus')->where('id', $id)->value('style'));
    }

    public function test_contact_links_are_allowed_but_scripts_are_not(): void
    {
        $menu = $this->menu();
        $this->item($menu, ['url' => 'mailto:shop@example.com']);
        $this->item($menu, ['url' => 'tel:+302101234567']);
        foreach (['javascript:alert(1)', 'mailto:', 'mailto:a b@example.com', 'tel:abc', 'data:text/html,x', 'ftp://x.test'] as $bad) {
            $this->post('/admin/menus/'.$menu.'/items', [...$this->fields($menu), 'url' => $bad])->assertSessionHasErrors('url');
        }
    }

    public function test_stale_item_and_menu_edits_cannot_overwrite_newer_edits(): void
    {
        $menu = $this->menu();
        $old = $this->fields($menu);
        $item = $this->item($menu);
        $this->post('/admin/menus/'.$menu.'/items/'.$item, [...$old, 'url' => '/stale'])->assertSessionHasErrors('version');
        $this->post('/admin/menus/'.$menu, ['version' => 1, 'status' => 'disabled', 'placement' => 'footer', 'source' => 'manual', 'translations' => ['en' => ['name' => 'Stale']]])->assertSessionHasErrors('version');
        self::assertSame('/about', DB::table('website_menu_items')->where('id', $item)->value('url'));
    }

    public function test_category_mode_uses_the_live_tree_without_copying_items_and_excludes_disabled_branches(): void
    {
        $menu = $this->menu('categories');
        $root = $this->category();
        $child = $this->category($root);
        $disabled = $this->category(status: 'disabled');
        $this->category($disabled);
        $this->get('/admin/menus/'.$menu)->assertInertia(fn (Assert $p) => $p->where('generated', true)->where('items.total', 1)->where('items.data.0.id', (string) $root->id));
        $this->get('/admin/menus/'.$menu.'?category_parent='.$root->id)->assertInertia(fn (Assert $p) => $p->where('items.data.0.id', (string) $child->id));
        $this->category();
        $this->get('/admin/menus/'.$menu)->assertInertia(fn (Assert $p) => $p->where('items.total', 2));
        $this->get('/admin/menus/'.$menu.'?category_parent='.$disabled->id)->assertInertia(fn (Assert $p) => $p->where('items.total', 0));
        $this->post('/admin/menus/'.$menu.'/items', $this->fields($menu))->assertSessionHasErrors('source');
        self::assertSame(0, DB::table('website_menu_items')->where('menu_id', $menu)->count());
    }

    public function test_generated_submenus_preview_only_the_selected_subtree_and_reject_manual_children(): void
    {
        $menu = $this->menu();
        $cat = $this->category();
        $child = $this->category($cat);
        $other = $this->category();
        $item = $this->item($menu, ['kind' => 'category', 'url' => null, 'category_id' => $cat->id, 'generate_children' => true]);
        $this->get('/admin/menus/'.$menu.'?parent='.$item)->assertInertia(fn (Assert $p) => $p->where('generated', true)->where('items.data.0.id', (string) $child->id));
        $this->get('/admin/menus/'.$menu.'?generated_from='.$item.'&category_parent='.$other->id)->assertStatus(422);
        $this->post('/admin/menus/'.$menu.'/items', [...$this->fields($menu), 'parent_id' => $item])->assertSessionHasErrors('parent_id');
        $root = $this->item($menu);
        $this->item($menu, ['parent_id' => $root]);
        $this->post('/admin/menus/'.$menu.'/items/'.$root, [...$this->fields($menu), 'kind' => 'category', 'url' => null, 'category_id' => $cat->id, 'generate_children' => true])->assertSessionHasErrors('generate_children');
    }

    public function test_mode_changes_require_current_version_confirmation_and_preserve_manual_items(): void
    {
        $menu = $this->menu();
        $item = $this->item($menu);
        $data = ['version' => 2, 'status' => 'active', 'placement' => 'header', 'source' => 'categories', 'translations' => ['en' => ['name' => 'Main menu']]];
        $this->post('/admin/menus/'.$menu, $data)->assertSessionHasErrors('confirmed');
        $this->post('/admin/menus/'.$menu, [...$data, 'confirmed' => true])->assertSessionHasNoErrors();
        self::assertTrue(DB::table('website_menu_items')->where('id', $item)->exists());
        $this->post('/admin/menus/'.$menu, [...$data, 'version' => 3, 'source' => 'manual', 'confirmed' => true])->assertSessionHasNoErrors();
        $this->get('/admin/menus/'.$menu)->assertInertia(fn (Assert $p) => $p->where('generated', false)->where('items.data.0.id', $item));
    }

    public function test_url_validation_and_auditing_do_not_expose_private_query_parameters(): void
    {
        $menu = $this->menu();
        foreach (['javascript:alert(1)', '//evil.example', 'data:text/html,test', '/\\evil', 'https://user:secret@example.com'] as $url) {
            self::assertFalse(MenuRules::safeUrl($url));
            $this->post('/admin/menus/'.$menu.'/items', [...$this->fields($menu), 'url' => $url])->assertSessionHasErrors('url');
        }
        foreach (['/contact?from=footer', '#details', 'https://example.com/path', 'mailto:test@example.com', 'tel:+302101234567'] as $url) {
            self::assertTrue(MenuRules::safeUrl($url));
        }
        $item = $this->item($menu, ['url' => 'https://example.com/?token=private-token']);
        $log = DB::table('audit_logs')->where('entity_type', 'menu_item')->where('entity_id', $item)->where('status', 'success')->sole();
        self::assertStringNotContainsString('private-token', $log->changes);
        self::assertTrue(json_decode($log->changes, true)['url']['after']['redacted']);
        self::assertSame((string) $this->actor->id, (string) $log->actor_id);
        self::assertArrayHasKey('website_menus', AuditTables::inventory());
    }

    public function test_individual_deletion_requires_confirmation_and_removes_only_the_requested_subtree(): void
    {
        $menu = $this->menu();
        $root = $this->item($menu);
        $child = $this->item($menu, ['parent_id' => $root]);
        $sibling = $this->item($menu);
        $this->delete('/admin/menus/'.$menu.'/items/'.$root, ['version' => 4])->assertSessionHasErrors('confirmed');
        $this->delete('/admin/menus/'.$menu.'/items/'.$root, ['version' => 4, 'confirmed' => true])->assertSessionHasNoErrors();
        self::assertFalse(DB::table('website_menu_items')->whereIn('id', [$root, $child])->exists());
        self::assertTrue(DB::table('website_menu_items')->where('id', $sibling)->exists());
        $this->delete('/admin/menus/'.$menu, ['version' => 5, 'confirmed' => true])->assertSessionHasNoErrors();
        self::assertFalse(DB::table('website_menu_items')->where('menu_id', $menu)->exists());
        self::assertTrue(DB::table('audit_logs')->where('entity_type', 'menu_item')->where('entity_id', $child)->where('action', 'menu_item.deleted')->exists());
    }

    public function test_view_only_users_cannot_mutate_menus_and_unauthorized_users_cannot_read_them(): void
    {
        $menu = $this->menu();
        $this->actingAs($this->staff(['admin.access', 'menus.view']));
        $this->get('/admin/menus/'.$menu)->assertOk();
        $this->post('/admin/menus/'.$menu.'/items', $this->fields($menu))->assertForbidden();
        $this->delete('/admin/menus/'.$menu, ['version' => 1, 'confirmed' => true])->assertForbidden();
        $denied = $this->staff();
        $this->actingAs($denied)->withSession(['password_hash_web' => $denied->password]);
        $this->get('/admin/menus')->assertForbidden();
        $this->get('/admin')->assertInertia(fn (Assert $p) => $p->where('navigation', fn ($n) => ! collect($n)->contains('id', 'core.menus')));
    }

    public function test_mutation_and_success_audit_roll_back_together(): void
    {
        $menu = $this->menu();
        DB::beginTransaction();
        $item = $this->item($menu);
        DB::rollBack();
        self::assertFalse(DB::table('website_menu_items')->where('id', $item)->exists());
        self::assertFalse(DB::table('audit_logs')->where('entity_type', 'menu_item')->where('entity_id', $item)->exists());
        self::assertSame(1, (int) DB::table('website_menus')->where('id', $menu)->value('lock_version'));
    }

    public function test_referenced_categories_get_an_explanation_before_deletion(): void
    {
        $menu = $this->menu();
        $cat = $this->category();
        $item = $this->item($menu, ['kind' => 'category', 'url' => null, 'category_id' => $cat->id]);
        $this->delete('/admin/categories/'.$cat->id, ['version' => 1])->assertSessionHasErrors('category');
        self::assertTrue(DB::table('categories')->where('id', $cat->id)->exists());
        $this->delete('/admin/menus/'.$menu.'/items/'.$item, ['version' => 2, 'confirmed' => true])->assertSessionHasNoErrors();
        $this->delete('/admin/categories/'.$cat->id, ['version' => 1])->assertSessionHasNoErrors();
    }

    public function test_item_images_use_private_validated_storage_and_reference_guards(): void
    {
        Storage::fake('local');
        $menu = $this->menu();
        $this->post('/admin/menus/'.$menu.'/items', [...$this->fields($menu), 'image' => UploadedFile::fake()->createWithContent('icon.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aN1sAAAAASUVORK5CYII='))])->assertSessionHasNoErrors();
        $item = DB::table('website_menu_items')->where('menu_id', $menu)->sole();
        $media = MediaFileRecord::findOrFail($item->image_media_id);
        $this->get('/admin/menus/'.$menu.'/items/'.$item->id.'/image')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        self::assertTrue(app(SharedMediaDeletion::class)->referenced((string) $media->id));
        try {
            app(SharedMediaDeletion::class)->assertUnreferenced($media);
            self::fail('Referenced image');
        } catch (ValidationException) {
            self::assertTrue(true);
        }
        $this->post('/admin/menus/'.$menu.'/items', [...$this->fields($menu), 'image' => UploadedFile::fake()->create('bad.svg', 1, 'image/svg+xml')])->assertSessionHasErrors('image');
    }
}
