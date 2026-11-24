import { Link } from '@inertiajs/react';
import { useTranslation } from '../../../lib/useTranslation';
import type { BlogData, PublicBlock } from '../../types';
import { useDateFormat } from './AccountParts';
import { Pagination } from './CategoryListing';
import { Picture } from './parts';

/** A blog's main content block: the trail, its title and description, then one page of its posts. */
export function BlogLanding({ block }: { block: PublicBlock }) {
  const t = useTranslation();
  const blog = block.content.blog;
  if (!blog) return null;
  return (
    <article className="sf-page sf-blog">
      <nav
        tabIndex={0}
        className="sf-cl-trail"
        aria-label={t('storefront.breadcrumb')}
      >
        <ol>
          <li>
            <a href="/">{t('storefront.home')}</a>
          </li>
          <li aria-current="page">{blog.name}</li>
        </ol>
      </nav>
      {!/^\s*<h1[\s>]/i.test(blog.content) && <h1>{blog.name}</h1>}
      {blog.content && (
        <div
          className="sf-prose sf-page-content"
          dangerouslySetInnerHTML={{ __html: blog.content }}
        />
      )}
      <section className="sf-listing sf-blog-posts" aria-label={blog.name}>
        {blog.posts.length === 0 ? (
          <p className="sf-blog-empty">{t('storefront.blog_empty')}</p>
        ) : (
          <PostCards posts={blog.posts} />
        )}
        <Pagination page={blog.page} last={blog.last_page} />
      </section>
    </article>
  );
}

/** Post cards (cover, date, title, excerpt): a blog's post list, and the posts a category page links to. */
export function PostCards({ posts }: { posts: BlogData['posts'] }) {
  const date = useDateFormat();
  return (
    <ul>
      {posts.map((post) => (
        <li key={post.id} className="sf-blog-card">
          {post.cover && (
            <Link href={post.url} tabIndex={-1} aria-hidden="true">
              <Picture image={post.cover} mobile={post.cover_mobile} alt="" />
            </Link>
          )}
          <div>
            {post.published_at && (
              <time dateTime={post.published_at}>
                {date(post.published_at)}
              </time>
            )}
            <h2>
              <Link href={post.url}>{post.name}</Link>
            </h2>
            {post.excerpt && <p>{post.excerpt}</p>}
          </div>
        </li>
      ))}
    </ul>
  );
}

/** A post's main content block: the trail through its blog, the title, date, cover, content and tags. */
export function BlogPost({ block }: { block: PublicBlock }) {
  const t = useTranslation();
  const date = useDateFormat();
  const post = block.content.post;
  if (!post) return null;
  return (
    <article
      className={`sf-page sf-blog-post${post.cover ? ' has-cover' : ''}`}
    >
      <nav
        tabIndex={0}
        className="sf-cl-trail"
        aria-label={t('storefront.breadcrumb')}
      >
        <ol>
          <li>
            <a href="/">{t('storefront.home')}</a>
          </li>
          <li>
            <Link href={post.blog.url}>{post.blog.name}</Link>
          </li>
          <li aria-current="page">{post.name}</li>
        </ol>
      </nav>
      <div className="sf-blog-scene">
        {post.cover && (
          <>
            <div className="sf-blog-backdrop">
              <div className="sf-blog-cover-layer">
                <Picture
                  image={post.cover}
                  mobile={post.cover_mobile}
                  alt={post.cover_alt}
                  eager
                />
              </div>
            </div>
            <div className="sf-blog-cover-space" aria-hidden="true" />
          </>
        )}
        <div className="sf-blog-article">
          <div className="sf-blog-article-inner">
            <div className="sf-blog-source">
              <Link href={post.blog.url}>
                <svg
                  aria-hidden="true"
                  width="24"
                  height="24"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="1.7"
                  strokeLinecap="round"
                  strokeLinejoin="round"
                >
                  <path d="M12 5c-3-2-6-2-9-1v15c3-1 6-1 9 1 3-2 6-2 9-1V4c-3-1-6-1-9 1ZM12 5v15" />
                </svg>
                <span>{post.blog.name}</span>
              </Link>
            </div>
            <h1>{post.name}</h1>
            {post.published_at && (
              <time className="sf-blog-date" dateTime={post.published_at}>
                {date(post.published_at)}
              </time>
            )}
            {post.excerpt && <p className="sf-blog-excerpt">{post.excerpt}</p>}
            {post.content && (
              <div
                className="sf-prose sf-page-content"
                dangerouslySetInnerHTML={{ __html: post.content }}
              />
            )}
            {post.tags.length > 0 && (
              <ul
                className="sf-blog-tags"
                aria-label={t('storefront.blog_tags')}
              >
                {post.tags.map((tag) => (
                  <li key={tag}>{tag}</li>
                ))}
              </ul>
            )}
            <p className="sf-blog-back">
              <Link href={post.blog.url}>
                {t('storefront.blog_back', { name: post.blog.name })}
              </Link>
            </p>
          </div>
        </div>
      </div>
    </article>
  );
}
