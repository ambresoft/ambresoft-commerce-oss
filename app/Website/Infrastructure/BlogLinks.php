<?php

namespace App\Website\Infrastructure;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Blog posts shown on a category page, in the merchant's order. */
final class BlogLinks
{
    /** @return list<string> */
    public function assigned(string $category): array
    {
        return DB::table('category_blog_posts')->where('category_id', $category)->orderBy('position')->orderBy('post_id')->pluck('post_id')->map(strval(...))->all();
    }

    /** @param list<string> $ids Already validated as distinct positive integers by the request. */
    public function sync(string $category, array $ids): void
    {
        if (DB::table('blog_posts')->whereIn('id', $ids)->count() !== count($ids)) {
            throw ValidationException::withMessages(['blog_post_ids' => __('blogs.post_missing')]);
        }
        DB::table('category_blog_posts')->where('category_id', $category)->delete();
        DB::table('category_blog_posts')->insert(array_map(fn (string $post, int $n): array => ['category_id' => $category, 'post_id' => $post, 'position' => $n + 1], $ids, array_keys($ids)));
    }

    /**
     * Every post with its blog, newest first, for the category editor's picker.
     *
     * @return list<array{id: string, name: string, blog: string, state: string}>
     */
    public function options(): array
    {
        // shortcut: loads at most 500 posts at once; switch to a searched endpoint when stores outgrow it.
        $rows = app(BlogQueries::class)->listing('post', ['per_page' => 500]);

        return array_map(fn (array $r): array => ['id' => $r['id'], 'name' => $r['name'], 'blog' => $r['blog'], 'state' => $r['state']], $rows['data']);
    }
}
