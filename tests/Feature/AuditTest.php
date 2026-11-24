<?php

namespace Tests\Feature;

use App\Admin\Navigation\AdminNavigation;
use App\Audit\Domain\Model\AuditEvent;
use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Audit\Infrastructure\AuditTables;
use App\Audit\Infrastructure\AuditWriteFailure;
use App\Authorization\Infrastructure\AccessChecker;
use App\Catalog\Application\Command\SaveFeatureData;
use App\Catalog\Application\Contract\FeatureAdministration;
use App\Catalog\Application\Contract\ProductAdministration;
use App\Catalog\Infrastructure\Jobs\CleanupProductFile;
use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\Concerns\StaffIdentities;
use Tests\TestCase;

final class AuditTest extends TestCase
{
    use RefreshDatabase, StaffIdentities;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->artisan('cms:permissions-sync')->assertSuccessful();
        app(AuditContext::class)->clear();
    }

    public function test_audit_reading_is_super_only_and_does_not_create_view_events(): void
    {
        $super = $this->staff(super: true);
        $ordinary = $this->staff(['admin.access', 'audit.view']);
        $id = DB::table('audit_logs')->orderBy('id')->value('id');
        $this->actingAs($super);
        $before = DB::table('audit_logs')->count();
        $this->get('/admin/audit-logs')->assertOk();
        $this->get('/admin/audit-logs/'.$id)->assertOk();
        self::assertSame($before, DB::table('audit_logs')->count());
        self::assertTrue(app(AccessChecker::class)->allows($super, 'audit.view'));
        self::assertFalse(app(AccessChecker::class)->allows($ordinary, 'audit.view'));
        $this->actingAs($ordinary)->withSession(['password_hash_web' => $ordinary->password]);
        $this->get('/admin/audit-logs')->assertForbidden();
        $this->get('/admin/audit-logs/'.$id)->assertForbidden();
        self::assertNotContains('core.audit-log', array_column(app(AdminNavigation::class)->forUser($ordinary), 'id'));
        self::assertContains('core.audit-log', array_column(app(AdminNavigation::class)->forUser($super), 'id'));
        $this->actingAs($super)->withSession(['password_hash_web' => $super->password]);
        $this->get('/admin/roles/create')->assertInertia(fn (Assert $page) => $page->where('permissions', fn ($items) => ! collect($items)->contains('name', 'audit.view')));
        $this->post('/admin/roles', ['name' => 'Audit reader', 'slug' => 'audit-reader', 'description' => '', 'permissions' => ['audit.view']])->assertSessionHasErrors('permissions.0');
        DB::table('users')->where('id', $super->id)->update(['is_active' => false]);
        self::assertFalse(app(AccessChecker::class)->allows($super, 'audit.view'));
    }

    public function test_idle_scheduled_cleanup_does_not_generate_activity_noise(): void
    {
        $this->artisan('cms:product-files-cleanup')->assertSuccessful();
        self::assertSame(0, DB::table('audit_logs')->where('entity_type', 'command')->whereRaw("metadata->>'command' = ?", ['cms:product-files-cleanup'])->count());
        Queue::fake();
        $file = DB::table('private_product_files')->insertGetId(['disk' => 'local', 'storage_key' => 'product-files/pending.txt', 'original_name' => 'pending.txt', 'mime_type' => 'text/plain', 'byte_size' => 3, 'ready' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('product_file_cleanups')->insert(['private_file_id' => $file, 'available_at' => now()->subMinute()]);
        $this->artisan('cms:product-files-cleanup')->assertSuccessful();
        Queue::assertPushed(CleanupProductFile::class, 1);
        $event = DB::table('audit_logs')->where('action', 'command.completed')->whereRaw("metadata->>'command' = ?", ['cms:product-files-cleanup'])->sole();
        self::assertSame(1, json_decode($event->metadata, true)['rows']);
        self::assertNull($event->actor_id);
        Event::dispatch(new CommandStarting('cms:product-files-cleanup', new ArrayInput([]), new NullOutput));
        Event::dispatch(new CommandFinished('cms:product-files-cleanup', new ArrayInput([]), new NullOutput, 1));
        $this->assertDatabaseHas('audit_logs', ['action' => 'command.failed', 'source' => 'system', 'actor_id' => null]);
    }

    public function test_editor_openings_record_authenticated_actor_and_ip_separately_from_changes(): void
    {
        $user = $this->staff(super: true);
        $product = app(ProductAdministration::class)->draft((string) $user->id);
        $category = CategoryRecord::create(['parent_id' => null, 'position' => 1, 'status' => 'disabled']);
        $feature = app(FeatureAdministration::class)->save((string) $user->id, null, new SaveFeatureData(['type' => 'text', 'required' => false, 'filterable' => false, 'comparison' => false, 'categories' => [], 'translations' => ['en' => ['name' => 'Notes']]]));
        $this->actingAs($user);
        foreach (['product' => $product, 'category' => (string) $category->id, 'feature' => $feature] as $type => $id) {
            $url = '/admin/'.['product' => 'products', 'category' => 'categories', 'feature' => 'features'][$type].'/'.$id.'/edit';
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.12'])->get($url)->assertOk();
            $event = DB::table('audit_logs')->where('action', $type.'.opened')->where('entity_id', $id)->sole();
            self::assertSame((string) $user->id, $event->actor_id);
            self::assertSame($user->email, $event->actor_email);
            self::assertSame('admin_ui', $event->source);
            self::assertSame('192.0.2.12', $event->ip_address);
            self::assertSame([], json_decode($event->changes, true));
            if ($type === 'feature') {
                self::assertSame('Notes', $event->entity_label);
            }
        }
        $this->get('/admin/audit-logs?source=admin_ui&action=product.opened')->assertInertia(fn (Assert $page) => $page->where('logs.total', 1)->where('logs.data.0.ip_address', '192.0.2.12')->has('actions')->has('entities'));
        $count = DB::table('audit_logs')->where('action', 'product.opened')->count();
        $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create('/')), 'X-Inertia-Partial-Component' => 'Admin/Products/Form', 'X-Inertia-Partial-Data' => 'categories'])->get('/admin/products/'.$product.'/edit')->assertOk();
        self::assertSame($count, DB::table('audit_logs')->where('action', 'product.opened')->count());
        $denied = $this->staff(['admin.access']);
        $this->actingAs($denied)->withSession(['password_hash_web' => $denied->password])->withHeaders(['X-Inertia' => '', 'X-Inertia-Partial-Data' => ''])->get('/admin/products/'.$product.'/edit')->assertForbidden();
        self::assertSame($count, DB::table('audit_logs')->where('action', 'product.opened')->count());
    }

    public function test_authentication_outcomes_and_sessions_never_record_credentials(): void
    {
        $user = $this->staff(super: true);
        $this->post('/admin/login', ['email' => $user->email, 'password' => 'wrong secret'])->assertSessionHasErrors('email');
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login_failed', 'status' => 'failure']);
        self::assertSame($user->email, json_decode(DB::table('audit_logs')->where('action', 'auth.login_failed')->value('metadata'), true)['attempted_email']);
        $this->post('/admin/login', ['email' => $user->email, 'password' => 'a long test passphrase'])->assertRedirect('/admin');
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'actor_id' => (string) $user->id]);
        self::assertSame(1, DB::table('audit_logs')->where('action', 'auth.session_created')->count());
        $this->post('/admin/logout')->assertRedirect('/admin/login');
        self::assertSame(1, DB::table('audit_logs')->where('action', 'auth.logout')->where('status', 'success')->count());
        $json = json_encode(DB::table('audit_logs')->get(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('wrong secret', $json);
        self::assertStringNotContainsString('a long test passphrase', $json);
        self::assertStringNotContainsString($user->password, $json);
    }

    public function test_atomic_persisted_changes_actor_context_and_noop_updates(): void
    {
        $user = $this->staff(super: true);
        $products = app(ProductAdministration::class);
        $id = $products->draft((string) $user->id);
        $created = DB::table('audit_logs')->where('action', 'product.created')->where('entity_id', $id)->sole();
        self::assertSame((string) $user->id, $created->actor_id);
        self::assertSame($user->email, $created->actor_email);
        self::assertContains('super-admin', json_decode($created->actor_roles, true));
        DB::transaction(fn () => DB::table('products')->where('id', $id)->update(['mpn' => '001-MODEL', 'price' => '49.99']));
        $update = DB::table('audit_logs')->where('action', 'product.updated')->where('entity_id', $id)->orderByDesc('id')->first();
        $changes = json_decode($update->changes, true);
        self::assertSame(['mpn', 'price'], array_keys($changes));
        self::assertSame('49.990000', $changes['price']['after']);
        $count = DB::table('audit_logs')->where('entity_type', 'product')->where('entity_id', $id)->count();
        DB::table('products')->where('id', $id)->update(['mpn' => '001-MODEL', 'lock_version' => 2]);
        self::assertSame($count, DB::table('audit_logs')->where('entity_type', 'product')->where('entity_id', $id)->count());
        try {
            DB::transaction(function () use ($id): void {
                DB::table('products')->where('id', $id)->update(['mpn' => 'ROLLBACK']);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }
        self::assertSame('001-MODEL', DB::table('products')->where('id', $id)->value('mpn'));
        self::assertSame($count, DB::table('audit_logs')->where('entity_type', 'product')->where('entity_id', $id)->count());
    }

    public function test_category_relationship_translation_and_deleted_entity_history(): void
    {
        app(AuditContext::class)->start();
        $category = CategoryRecord::create(['parent_id' => null, 'position' => 1, 'status' => 'disabled']);
        $category->translations()->create(['locale_id' => 1, 'name' => 'History', 'slug' => 'history', 'description' => str_repeat('private content', 100)]);
        app(AuditContext::class)->start();
        $category->update(['status' => 'active']);
        app(AuditContext::class)->start();
        $category->delete();
        $this->assertDatabaseHas('audit_logs', ['action' => 'category.created', 'entity_id' => (string) $category->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'category.status_changed', 'entity_id' => (string) $category->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'category.deleted', 'entity_id' => (string) $category->id]);
        $translation = DB::table('audit_logs')->where('action', 'category_translation.created')->sole();
        self::assertSame('History', $translation->entity_label);
        self::assertStringNotContainsString('private content', $translation->changes);
        self::assertTrue(json_decode($translation->changes, true)['description']['after']['redacted']);
    }

    public function test_permission_links_secret_changes_and_redaction_are_persisted(): void
    {
        $user = $this->staff();
        app(AuditContext::class)->actor((string) $user->id);
        $role = DB::table('roles')->insertGetId(['name' => 'Auditor', 'slug' => 'auditor', 'description' => '', 'is_super_admin' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('permission_role')->insert(['role_id' => $role, 'permission_id' => DB::table('permissions')->where('name', 'audit.view')->value('id')]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'role_permission.created']);
        $user->password = 'changed super secret';
        $user->save();
        $event = DB::table('audit_logs')->where('action', 'user.updated')->where('entity_id', (string) $user->id)->orderByDesc('id')->first();
        self::assertSame('[REDACTED]', json_decode($event->changes, true)['password']['after']);
        app(AuditLogger::class)->record(new AuditEvent('operation.failed', 'user', status: 'failure', metadata: ['password' => 'never persist', 'access_token' => 'never persist', 'route' => "admin.test\nINJECT"]));
        $metadata = DB::table('audit_logs')->where('action', 'operation.failed')->value('metadata');
        self::assertStringNotContainsString('never persist', $metadata);
        self::assertStringNotContainsString('\n', $metadata);
    }

    public function test_read_authorization_append_only_guards_pagination_and_filters(): void
    {
        $reader = $this->staff(super: true);
        $this->actingAs($reader);
        for ($i = 0; $i < 35; $i++) {
            app(AuditLogger::class)->record(new AuditEvent('operation.failed', 'product', (string) $i, 'failure'));
        }
        $this->get('/admin/audit-logs?entity=product&status=failure&sort=asc')->assertInertia(fn (Assert $page) => $page->has('logs.data', 30)->where('logs.total', 35)->where('logs.data.0.entity_id', '0'));
        $this->get('/admin/audit-logs?entity=product&status=failure&page=2')->assertInertia(fn (Assert $page) => $page->has('logs.data', 5));
        $this->get('/admin/audit-logs?from=2099-01-01&to=2099-01-02')->assertInertia(fn (Assert $page) => $page->where('logs.total', 0));
        $id = DB::table('audit_logs')->where('action', 'operation.failed')->value('id');
        $this->get('/admin/audit-logs/'.$id)->assertInertia(fn (Assert $page) => $page->where('log.id', (string) $id)->has('log.changes'));
        foreach (['update', 'delete'] as $operation) {
            try {
                DB::transaction(fn () => $operation === 'update' ? DB::table('audit_logs')->where('id', $id)->update(['status' => 'success']) : DB::table('audit_logs')->where('id', $id)->delete());
                self::fail('Expected immutable audit guard');
            } catch (QueryException $e) {
                self::assertSame('42501', $e->getCode());
            }
        }
    }

    public function test_denied_requests_and_failed_mutations_do_not_claim_success(): void
    {
        $user = $this->staff();
        $this->actingAs($user);
        $this->get('/admin/audit-logs')->assertForbidden();
        $this->assertDatabaseHas('audit_logs', ['action' => 'security.denied', 'actor_id' => (string) $user->id, 'status' => 'denied']);
        $this->post('/admin/products')->assertForbidden();
        self::assertSame(0, DB::table('audit_logs')->where('action', 'product.created')->count());
    }

    public function test_audit_failure_rolls_back_mutation_and_security_failure_is_explicit(): void
    {
        $user = $this->staff(super: true);
        $id = app(ProductAdministration::class)->draft((string) $user->id);
        DB::statement('ALTER TABLE audit_logs ADD CONSTRAINT test_audit_outage CHECK (false) NOT VALID');
        try {
            try {
                DB::transaction(fn () => DB::table('products')->where('id', $id)->update(['mpn' => 'BLOCKED']));
                self::fail('Expected failed audit persistence');
            } catch (QueryException) {
                self::assertNull(ProductRecord::findOrFail($id)->mpn);
            }
            try {
                DB::transaction(fn () => app(AuditLogger::class)->record(new AuditEvent('auth.login', 'user')));
                self::fail('Expected explicit audit failure');
            } catch (AuditWriteFailure) {
                self::assertSame(0, DB::table('audit_logs')->where('action', 'auth.login')->count());
            }
        } finally {
            DB::statement('ALTER TABLE audit_logs DROP CONSTRAINT test_audit_outage');
        }
    }

    public function test_background_deletion_has_system_identity_and_no_storage_paths(): void
    {
        Storage::fake('local');
        app(AuditContext::class)->actor((string) $this->staff(super: true)->id);
        $file = DB::table('private_product_files')->insertGetId(['disk' => 'local', 'storage_key' => 'product-files/private.txt', 'original_name' => 'private.txt', 'mime_type' => 'text/plain', 'byte_size' => 3, 'ready' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('product_file_cleanups')->insert(['private_file_id' => $file, 'available_at' => now()->subMinute()]);
        $job = $this->createMock(Job::class);
        $job->method('resolveName')->willReturn(CleanupProductFile::class);
        Event::dispatch(new JobProcessing('sync', $job));
        (new CleanupProductFile((string) $file))->handle();
        Event::dispatch(new JobProcessed('sync', $job));
        $event = DB::table('audit_logs')->where('action', 'private_file.deleted')->sole();
        self::assertNull($event->actor_id);
        self::assertSame('background_job', $event->source);
        $completed = DB::table('audit_logs')->where('action', 'job.completed')->sole();
        self::assertSame($event->request_id, $completed->request_id);
        self::assertNull($completed->actor_id);
        self::assertStringNotContainsString('product-files/private.txt', $event->changes);
    }

    public function test_direct_denial_and_failed_product_activation_have_single_outcomes_and_bulk_counts(): void
    {
        $actor = $this->staff(super: true);
        $products = app(ProductAdministration::class);
        $id = $products->draft((string) $actor->id);
        try {
            $products->lifecycle((string) $actor->id, $id, 1, 'active');
            self::fail('Incomplete activation should fail');
        } catch (ValidationException) {
        }
        self::assertSame(1, DB::table('audit_logs')->where('action', 'operation.failed')->where('entity_type', 'product')->count());
        self::assertSame(0, DB::table('audit_logs')->where('action', 'product.status_changed')->count());
        $results = $products->bulk((string) $actor->id, [$id, '999999'], 'hidden');
        self::assertCount(2, $results);
        $bulk = DB::table('audit_logs')->where('action', 'product.bulk_completed')->sole();
        self::assertSame('failure', $bulk->status);
        self::assertEquals(['success_count' => 1, 'failure_count' => 1], json_decode($bulk->metadata, true));
        $denied = $this->staff();
        try {
            $products->draft((string) $denied->id);
            self::fail('Unauthorized direct service should fail');
        } catch (AuthorizationException) {
        }
        $this->assertDatabaseHas('audit_logs', ['action' => 'security.denied', 'actor_id' => (string) $denied->id, 'entity_type' => 'product']);
    }

    public function test_history_survives_actor_deletion_and_untrusted_forwarded_ip_is_ignored(): void
    {
        $actor = $this->staff(super: true);
        app(AuditContext::class)->actor((string) $actor->id);
        app(AuditLogger::class)->record(new AuditEvent('operation.failed', 'audit'));
        $actor->delete();
        $this->assertDatabaseHas('audit_logs', ['action' => 'operation.failed', 'actor_id' => (string) $actor->id, 'actor_email' => $actor->email]);
        $this->withHeader('X-Forwarded-For', '203.0.113.42')->post('/admin/login', ['email' => 'missing@example.test', 'password' => 'not a real password'])->assertSessionHasErrors('email');
        $event = DB::table('audit_logs')->where('action', 'auth.login_failed')->sole();
        self::assertNotSame('203.0.113.42', $event->ip_address);
        self::assertNull($event->actor_id);
        self::assertNotNull($event->request_id);
    }

    public function test_transactional_capture_consolidates_real_final_differences_and_ignores_reinserted_links(): void
    {
        $actor = $this->staff(super: true);
        app(AuditContext::class)->start();
        app(AuditContext::class)->actor((string) $actor->id);
        $original = DB::table('locales')->where('code', 'el')->value('is_active');
        DB::table('locales')->where('code', 'el')->update(['is_active' => ! $original]);
        DB::table('locales')->where('code', 'el')->update(['is_active' => $original]);
        self::assertSame(0, DB::table('audit_logs')->where('entity_type', 'language')->count());
        $role = DB::table('role_user')->where('user_id', $actor->id)->value('role_id');
        DB::table('role_user')->where('user_id', $actor->id)->delete();
        DB::table('role_user')->insert(['user_id' => $actor->id, 'role_id' => $role]);
        self::assertSame(0, DB::table('audit_logs')->where('action', 'user_role.deleted')->count());
        app(AuditContext::class)->start();
        $product = ProductRecord::create(['mpn' => 'BEFORE']);
        app(AuditContext::class)->start();
        $product->update(['mpn' => 'INTERMEDIATE']);
        $product->update(['mpn' => 'AFTER']);
        $event = DB::table('audit_logs')->where('action', 'product.updated')->where('entity_id', (string) $product->id)->sole();
        self::assertEquals(['before' => 'BEFORE', 'after' => 'AFTER'], json_decode($event->changes, true)['mpn']);
        self::assertSame(1, DB::table('audit_logs')->where('action', 'product.updated')->where('entity_id', (string) $product->id)->count());
    }

    public function test_consolidation_migration_preserves_existing_history_on_down_and_up(): void
    {
        app(AuditLogger::class)->record(new AuditEvent('operation.failed', 'audit'));
        $id = DB::table('audit_logs')->where('action', 'operation.failed')->value('id');
        $migration = require database_path('migrations/2026_10_10_001400_consolidate_transactional_audit_changes.php');
        $migration->down();
        $this->assertDatabaseHas('audit_logs', ['id' => $id, 'action' => 'operation.failed']);
        $migration->up();
        $this->assertDatabaseHas('audit_logs', ['id' => $id, 'action' => 'operation.failed', 'transaction_id' => null]);
        app(AuditLogger::class)->record(new AuditEvent('operation.failed', 'audit'));
        self::assertSame(2, DB::table('audit_logs')->where('action', 'operation.failed')->count());
    }

    public function test_every_reviewed_table_has_capture_and_retention_is_explicit(): void
    {
        foreach (AuditTables::inventory() as $table => [$entity, $fields]) {
            foreach (explode(',', $fields) as $field) {
                self::assertTrue(Schema::hasColumn($table, $field), $table.'.'.$field);
            }
            self::assertTrue(DB::table('pg_trigger')->join('pg_class', 'pg_class.oid', '=', 'pg_trigger.tgrelid')->where('relname', $table)->where('tgname', 'cms_audit_mutation')->exists(), $table);
        }
        config(['audit.retention_days' => null]);
        $this->artisan('cms:audit-prune')->assertFailed();
        $id = DB::table('audit_logs')->insertGetId(['occurred_at' => now()->subDays(40), 'action' => 'operation.failed', 'entity_type' => 'request', 'description' => 'old failure', 'status' => 'failure', 'source' => 'system']);
        config(['audit.retention_days' => 30]);
        $this->artisan('cms:audit-prune')->assertSuccessful();
        $this->assertDatabaseMissing('audit_logs', ['id' => $id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'audit.retention_purged']);
    }
}
