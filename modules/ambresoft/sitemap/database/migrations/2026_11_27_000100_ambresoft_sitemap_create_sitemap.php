<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ambresoft_sitemap_sections', function (Blueprint $t): void {
            $t->string('key', 30)->primary();
            $t->boolean('enabled')->default(true);
            $t->string('frequency', 10)->default('day');
            $t->string('status', 12)->default('idle');
            $t->string('error', 500)->nullable();
            $t->uuid('generation_token')->nullable();
            $t->integer('eligible_count')->default(0);
            $t->timestampTz('last_refresh_at')->nullable();
            $t->timestampTz('last_attempt_at')->nullable();
            $t->timestampTz('next_refresh_at')->nullable()->index();
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE ambresoft_sitemap_sections ADD CHECK (key IN ('homepage','products','categories','pages','brands','blogs','posts')), ADD CHECK (eligible_count >= 0), ADD CHECK (frequency IN ('hour','day','week','month','year','never')), ADD CHECK (status IN ('idle','queued','completed','failed'))");
        foreach (['homepage', 'products', 'categories', 'pages', 'brands', 'blogs', 'posts'] as $key) {
            DB::table('ambresoft_sitemap_sections')->insert(['key' => $key, 'created_at' => now(), 'updated_at' => now()]);
        }
        Schema::create('ambresoft_sitemap_versions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('origin', 255);
            $t->jsonb('sections');
            $t->unsignedBigInteger('url_count')->default(0);
            $t->timestampTz('published_at');
        });
        DB::statement("ALTER TABLE ambresoft_sitemap_versions ADD CHECK (url_count >= 0), ADD CHECK (jsonb_typeof(sections) = 'object')");
        Schema::create('ambresoft_sitemap_state', function (Blueprint $t): void {
            $t->smallInteger('id')->primary();
            $t->unsignedBigInteger('draft_version')->default(1);
            $t->boolean('draft_pending')->default(false);
            $t->foreignUuid('published_version')->nullable()->constrained('ambresoft_sitemap_versions')->restrictOnDelete();
            $t->timestampsTz();
        });
        DB::statement('ALTER TABLE ambresoft_sitemap_state ADD CHECK (id = 1), ADD CHECK (draft_version > 0)');
        DB::table('ambresoft_sitemap_state')->insert(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        Schema::create('ambresoft_sitemap_draft_urls', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $this->urlColumns($t);
            $t->timestampsTz();
            $t->unique(['section', 'source_id']);
        });
        Schema::create('ambresoft_sitemap_version_urls', function (Blueprint $t): void {
            $t->foreignUuid('version_id')->constrained('ambresoft_sitemap_versions')->cascadeOnDelete();
            $this->urlColumns($t);
            $t->primary(['version_id', 'section', 'source_id']);
            $t->index('section');
        });
        Schema::create('ambresoft_sitemap_files', function (Blueprint $t): void {
            $t->foreignUuid('version_id')->constrained('ambresoft_sitemap_versions')->cascadeOnDelete();
            $t->string('name', 100);
            $t->text('xml');
            $t->integer('url_count');
            $t->integer('bytes');
            $t->primary(['version_id', 'name']);
        });
        DB::statement('ALTER TABLE ambresoft_sitemap_files ADD CHECK (url_count BETWEEN 0 AND 50000), ADD CHECK (bytes BETWEEN 1 AND 52428800), ADD CHECK (bytes = octet_length(xml))');
    }

    private function urlColumns(Blueprint $t): void
    {
        $t->string('section', 30);
        $t->foreign('section')->references('key')->on('ambresoft_sitemap_sections')->restrictOnDelete();
        $t->string('source_id', 100);
        $t->text('canonical_url');
        $t->string('url_override', 2048)->nullable();
        $t->boolean('inclusion_override')->nullable();
        $t->boolean('available')->default(true);
        $t->timestampTz('lastmod')->nullable();
        $t->string('warning', 100)->nullable();
    }

    public function down(): void
    {
        Schema::dropIfExists('ambresoft_sitemap_files');
        Schema::dropIfExists('ambresoft_sitemap_version_urls');
        Schema::dropIfExists('ambresoft_sitemap_draft_urls');
        Schema::dropIfExists('ambresoft_sitemap_state');
        Schema::dropIfExists('ambresoft_sitemap_versions');
        Schema::dropIfExists('ambresoft_sitemap_sections');
    }
};
