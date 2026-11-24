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
        // Blog posts a category page shows above its questions, in the merchant's order.
        Schema::create('category_blog_posts', function (Blueprint $t): void {
            $t->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $t->foreignId('post_id')->constrained('blog_posts')->cascadeOnDelete();
            $t->integer('position');
            $t->primary(['category_id', 'post_id']);
            $t->index('post_id');
        });
        DB::statement('ALTER TABLE category_blog_posts ADD CONSTRAINT category_blog_post_position CHECK (position > 0)');
        foreach (AuditTables::categoryBlogPosts() as $table => [$entity, $fields]) {
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('category_blog_posts');
    }
};
