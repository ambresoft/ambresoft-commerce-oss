<?php

namespace Tests\Feature;

use App\Catalog\Infrastructure\Jobs\CleanupProductFile;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Catalog\Infrastructure\ProductFiles;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ProductFileConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['database.connections.product_race' => config('database.connections.pgsql')]);
        DB::connection('product_race')->statement("SET lock_timeout = '150ms'");
        self::assertSame('cms_test', DB::connection('product_race')->selectOne('select current_database() as name')->name);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection('pgsql');
        DB::purge('product_race');
        parent::tearDown();
    }

    public function test_cleanup_waits_for_a_concurrent_association_then_preserves_the_file(): void
    {
        $fileId = app(ProductFiles::class)->stage(UploadedFile::fake()->createWithContent('guide.txt', 'Guide'));
        app(ProductFiles::class)->schedule([$fileId]);
        $p = ProductRecord::create([]);
        $primary = DB::connection('pgsql');
        $primary->beginTransaction();
        try {
            $primary->table('private_product_files')->where('id', $fileId)->lockForUpdate()->first();
            $primary->table('product_files')->insert(['product_id' => $p->id, 'private_file_id' => $fileId, 'display_name' => 'Guide.txt', 'position' => 1]);
            DB::setDefaultConnection('product_race');
            try {
                (new CleanupProductFile($fileId))->handle();
                self::fail('Cleanup must wait for the associating transaction');
            } catch (QueryException $e) {
                self::assertSame('55P03', $e->getCode());
            } finally {
                DB::setDefaultConnection('pgsql');
            }
            $primary->commit();
        } finally {
            if ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
        }
        (new CleanupProductFile($fileId))->handle();
        $this->assertDatabaseHas('private_product_files', ['id' => $fileId]);
        $this->assertDatabaseHas('product_files', ['private_file_id' => $fileId]);
        Storage::disk('local')->assertExists(DB::table('private_product_files')->where('id', $fileId)->value('storage_key'));
    }

    public function test_association_cannot_race_physical_cleanup_and_missing_bytes_are_retry_safe(): void
    {
        $fileId = app(ProductFiles::class)->stage(UploadedFile::fake()->createWithContent('guide.txt', 'Guide'));
        app(ProductFiles::class)->schedule([$fileId]);
        $p = ProductRecord::create([]);
        $disk = Storage::disk('local');
        $proxy = \Mockery::mock($disk)->makePartial();
        $proxy->shouldReceive('delete')->once()->andReturnUsing(function ($key) use ($p, $fileId, $disk): bool {
            try {
                DB::connection('product_race')->table('product_files')->insert(['product_id' => $p->id, 'private_file_id' => $fileId, 'display_name' => 'Guide.txt', 'position' => 1]);
                self::fail('A new reference must wait while cleanup owns the file row');
            } catch (QueryException $e) {
                self::assertSame('55P03', $e->getCode());
            }

            return $disk->delete($key);
        });
        Storage::set('local', $proxy);
        try {
            (new CleanupProductFile($fileId))->handle();
        } finally {
            Storage::set('local', $disk);
        }
        (new CleanupProductFile($fileId))->handle();
        $this->assertDatabaseMissing('private_product_files', ['id' => $fileId]);
        $this->assertDatabaseCount('product_files', 0);
    }
}
