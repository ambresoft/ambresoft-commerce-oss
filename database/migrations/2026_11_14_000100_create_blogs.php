<?php

use App\Audit\Infrastructure\AuditTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A blog is a landing page listing its posts; statuses, availability and texts follow Website → Pages.
        Schema::create('blogs', function (Blueprint $t): void {
            $t->id();
            $t->integer('position')->default(0);
            // active: listed and reachable; hidden: reachable by its address only; disabled: not reachable.
            $t->string('status', 10)->default('active');
            $t->timestampTz('available_from')->nullable();
            $t->timestampTz('available_until')->nullable();
            $t->string('facebook_type', 20)->default('blog');
            $t->bigInteger('lock_version')->default(1);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
        });
        Schema::create('blog_translations', function (Blueprint $t): void {
            $t->foreignId('blog_id')->constrained('blogs')->cascadeOnDelete();
            $t->foreignId('locale_id')->constrained('locales')->restrictOnDelete();
            $t->string('name', 200);
            // Sanitized HTML (server page-content policy), shown above the posts.
            $t->text('content')->default('');
            $t->string('slug', 200);
            $t->string('seo_title', 200)->default('');
            $t->string('meta_description', 500)->default('');
            $t->string('meta_keywords', 500)->default('');
            $t->primary(['blog_id', 'locale_id']);
            $t->unique(['locale_id', 'slug']);
        });
        Schema::create('blog_posts', function (Blueprint $t): void {
            $t->id();
            // A blog with posts cannot be deleted; its posts are moved or deleted first.
            $t->foreignId('blog_id')->constrained('blogs')->restrictOnDelete();
            $t->string('status', 10)->default('active');
            // Null: a draft, never shown. In the future: scheduled.
            $t->timestampTz('published_at')->nullable();
            $t->timestampTz('available_from')->nullable();
            $t->timestampTz('available_until')->nullable();
            $t->string('facebook_type', 20)->default('article');
            $t->foreignId('cover_media_id')->nullable()->constrained('media_files')->restrictOnDelete();
            $t->bigInteger('lock_version')->default(1);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->index(['blog_id', 'published_at', 'id']);
            $t->index('cover_media_id');
        });
        Schema::create('blog_post_translations', function (Blueprint $t): void {
            $t->foreignId('post_id')->constrained('blog_posts')->cascadeOnDelete();
            $t->foreignId('locale_id')->constrained('locales')->restrictOnDelete();
            $t->string('name', 200);
            $t->text('content')->default('');
            $t->string('excerpt', 1000)->default('');
            $t->string('cover_alt', 200)->default('');
            $t->string('slug', 200);
            $t->string('seo_title', 200)->default('');
            $t->string('meta_description', 500)->default('');
            $t->string('meta_keywords', 500)->default('');
            $t->primary(['post_id', 'locale_id']);
            $t->unique(['locale_id', 'slug']);
        });
        // Tags are merchant text, so they belong to a post's language and go with it.
        Schema::create('blog_post_tags', function (Blueprint $t): void {
            $t->unsignedBigInteger('post_id');
            $t->unsignedBigInteger('locale_id');
            $t->string('name', 60);
            $t->foreign(['post_id', 'locale_id'])->references(['post_id', 'locale_id'])->on('blog_post_translations')->cascadeOnDelete();
            $t->index(['locale_id', 'name']);
        });
        DB::statement('CREATE UNIQUE INDEX blog_post_tags_unique ON blog_post_tags (post_id, locale_id, lower(name))');
        $values = "status IN ('active','hidden','disabled') AND lock_version > 0 AND facebook_type ~ '^[a-z_]{2,20}$'
            AND (available_from IS NULL OR available_until IS NULL OR available_until > available_from)";
        DB::statement("ALTER TABLE blogs ADD CONSTRAINT blog_values CHECK ($values AND position BETWEEN -99999 AND 99999)");
        DB::statement("ALTER TABLE blog_posts ADD CONSTRAINT blog_post_values CHECK ($values)");
        $texts = "btrim(name) <> '' AND slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$' AND length(content) <= 500000";
        DB::statement("ALTER TABLE blog_translations ADD CONSTRAINT blog_translation_values CHECK ($texts)");
        DB::statement("ALTER TABLE blog_post_translations ADD CONSTRAINT blog_post_translation_values CHECK ($texts)");
        DB::statement("ALTER TABLE blog_post_tags ADD CONSTRAINT blog_post_tag_values CHECK (name = btrim(name) AND name <> '')");
        foreach (AuditTables::blogs() as $table => [$entity, $fields]) {
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
    }

    public function down(): void
    {
        foreach (['blog_post_tags', 'blog_post_translations', 'blog_posts', 'blog_translations', 'blogs'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
