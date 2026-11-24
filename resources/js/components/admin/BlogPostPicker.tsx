import { Link } from '@inertiajs/react';
import Select from './Select';
import StatusBadge from './list/StatusBadge';
import { blogStateLabel, blogStateTone } from '../../lib/blogLabels';
import { useTranslation } from '../../lib/useTranslation';
import type { BlogState } from '../../types/blogs';

export type BlogPostOption = {
  id: string;
  name: string;
  blog: string;
  state: BlogState;
};

/** Blog posts linked to a category, in display order (the same pattern as the FAQ picker). */
export default function BlogPostPicker({
  ids,
  options,
  onIds,
  error,
  disabled = false,
}: {
  ids: string[];
  options: BlogPostOption[];
  onIds: (ids: string[]) => void;
  error?: string;
  disabled?: boolean;
}) {
  const t = useTranslation();
  const byId = new Map(options.map((option) => [option.id, option]));
  const move = (index: number, step: number) => {
    const next = [...ids];
    [next[index], next[index + step]] = [next[index + step], next[index]];
    onIds(next);
  };
  return (
    <section className="space-y-5">
      <div>
        <h2 className="text-base font-semibold">{t('blogs.category_posts')}</h2>
        <p className="mt-1 text-xs leading-5 text-slate-500">
          {t('blogs.category_posts_help')}
        </p>
      </div>
      {!disabled && (
        <div className="max-w-xl">
          <Select
            aria-label={t('blogs.category_posts_add')}
            value=""
            search={{
              label: t('common.search_options'),
              emptyMessage: t('common.no_options'),
            }}
            options={[
              {
                value: '',
                label: t('blogs.category_posts_add'),
                disabled: true,
              },
              ...options
                .filter((option) => !ids.includes(option.id))
                .map((option) => ({
                  value: option.id,
                  label: option.name + ' · ' + option.blog,
                })),
            ]}
            onChange={(id) => id && onIds([...ids, id])}
          />
        </div>
      )}
      {error && (
        <p role="alert" className="text-sm text-red-700">
          {error}
        </p>
      )}
      {ids.length === 0 ? (
        <p className="text-sm text-slate-500">
          {t('blogs.category_posts_empty')}
        </p>
      ) : (
        <ol className="divide-y divide-slate-200">
          {ids.map((id, index) => {
            const post = byId.get(id);
            return (
              <li
                key={id}
                className="flex flex-wrap items-center justify-between gap-3 py-3"
              >
                <div className="flex min-w-0 flex-wrap items-center gap-2">
                  <Link
                    className="text-link text-sm"
                    href={'/admin/blog-posts/' + id + '/edit'}
                  >
                    {index + 1}. {post?.name ?? '#' + id}
                  </Link>
                  {post && (
                    <span className="text-xs text-slate-500">{post.blog}</span>
                  )}
                  {post && post.state !== 'live' && (
                    <StatusBadge tone={blogStateTone[post.state]}>
                      {t(blogStateLabel[post.state])}
                    </StatusBadge>
                  )}
                </div>
                {!disabled && (
                  <div className="flex gap-2">
                    <button
                      type="button"
                      className="button-secondary"
                      aria-label={
                        t('blogs.move_up') + ': ' + (post?.name ?? id)
                      }
                      disabled={index === 0}
                      onClick={() => move(index, -1)}
                    >
                      ↑
                    </button>
                    <button
                      type="button"
                      className="button-secondary"
                      aria-label={
                        t('blogs.move_down') + ': ' + (post?.name ?? id)
                      }
                      disabled={index === ids.length - 1}
                      onClick={() => move(index, 1)}
                    >
                      ↓
                    </button>
                    <button
                      type="button"
                      className="button-secondary"
                      onClick={() => onIds(ids.filter((value) => value !== id))}
                    >
                      {t('blogs.remove')}
                    </button>
                  </div>
                )}
              </li>
            );
          })}
        </ol>
      )}
    </section>
  );
}
