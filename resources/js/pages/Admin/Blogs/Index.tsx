import EditorHeader from '../../../components/admin/EditorHeader';
import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import FormErrors from '../../../components/admin/FormErrors';
import Pagination from '../../../components/admin/Pagination';
import BulkBar from '../../../components/admin/list/BulkBar';
import FilterBar, {
  FilterChip,
  SearchField,
} from '../../../components/admin/list/FilterBar';
import IconButton, { Icon } from '../../../components/admin/list/IconButton';
import StatusBadge from '../../../components/admin/list/StatusBadge';
import { blogStateLabel, blogStateTone } from '../../../lib/blogLabels';
import { pageLabels } from '../../../lib/pageLabels';
import { useListQuery } from '../../../lib/useListQuery';
import { usePreferences } from '../../../lib/usePreferences';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell, Pagination as Page } from '../../../types/admin';
import type { BlogKind, BlogRow } from '../../../types/blogs';
import type { SharedProps } from '../../../types/page';

type Props = AdminShell & {
  kind: BlogKind;
  rows: Page<BlogRow>;
  filters: Record<string, string>;
  blogs: { id: string; name: string }[];
};

/** Website → Blog (blogs) and Blog posts: the same list, with the blog column and filter for posts. */
export default function BlogIndex({
  kind,
  rows,
  filters,
  blogs,
  ...shell
}: Props) {
  const t = useTranslation();
  const { timezone: timeZone } = usePreferences();
  const { capabilities, errors } = usePage<SharedProps>().props;
  const manage = !!capabilities['blog.manage'];
  const base = kind === 'blog' ? '/admin/blogs' : '/admin/blog-posts';
  const title = t(kind === 'blog' ? 'blogs.title' : 'blogs.posts_title');
  const apply = useListQuery(base, filters);
  const [search, setSearch] = useState(filters.search ?? '');
  const [picked, setPicked] = useState<string[]>([]);
  const ids = rows.data.map((row) => row.id);
  const selected = picked.filter((id) => ids.includes(id));
  const allSelected =
    ids.length > 0 && ids.every((id) => selected.includes(id));
  const create =
    kind === 'post' && filters.blog
      ? base + '/create?blog=' + filters.blog
      : base + '/create';

  const bulk = (action: string) => {
    if (
      action === 'delete' &&
      !window.confirm(
        t('blogs.bulk_delete_confirm', { count: selected.length }),
      )
    )
      return;
    router.post(
      base + '/bulk',
      { ids: selected, action },
      { preserveScroll: true, onSuccess: () => setPicked([]) },
    );
  };
  const remove = (row: BlogRow) => {
    if (window.confirm(t('blogs.delete_confirm', { name: row.name })))
      router.delete(base + '/' + row.id, {
        data: { version: row.version },
        preserveScroll: true,
      });
  };
  const columns = manage ? 7 : 6;

  return (
    <AdminLayout title={title} {...shell}>
      <EditorHeader
        heading={title}
        iconKey="blog"
        description={
          <>
            <p className="mt-1 max-w-3xl text-sm text-slate-500">
              {t(
                kind === 'blog'
                  ? 'blogs.description'
                  : 'blogs.posts_description',
              )}
            </p>
          </>
        }
        actions={
          <>
            {manage && (kind === 'blog' || blogs.length > 0) && (
              <Link href={create} className="button-primary gap-2">
                <Icon name="plus" />
                {t(kind === 'blog' ? 'blogs.create' : 'blogs.create_post')}
              </Link>
            )}
          </>
        }
      />
      <FormErrors errors={errors ?? {}} />
      <section className="list-card" aria-label={title}>
        <FilterBar
          resetHref={base}
          active={!!(filters.search || filters.status || filters.blog)}
          search={
            <SearchField
              id="blogs-search"
              value={search}
              onChange={setSearch}
              placeholder={t('blogs.search')}
              onSubmit={() => apply({ search })}
            />
          }
          chips={
            <>
              {kind === 'post' && (
                <FilterChip
                  label={t('blogs.blog')}
                  value={filters.blog ?? ''}
                  options={[
                    { value: '', label: t('common.all') },
                    ...blogs.map((b) => ({ value: b.id, label: b.name })),
                  ]}
                  onChange={(blog) => apply({ blog })}
                />
              )}
              <FilterChip
                label={t('common.status')}
                value={filters.status ?? ''}
                options={[
                  { value: '', label: t('common.all') },
                  ...(['active', 'hidden', 'disabled'] as const).map(
                    (status) => ({
                      value: status,
                      label: t(pageLabels.status[status]),
                    }),
                  ),
                ]}
                onChange={(status) => apply({ status })}
              />
            </>
          }
        />
        {manage && selected.length > 0 && (
          <BulkBar count={selected.length} onClear={() => setPicked([])}>
            {(['active', 'hidden', 'disabled'] as const).map((action) => (
              <button
                key={action}
                type="button"
                className="button-secondary"
                onClick={() => bulk(action)}
              >
                {t(pageLabels.status[action])}
              </button>
            ))}
            <button
              type="button"
              className="button-secondary text-red-700"
              onClick={() => bulk('delete')}
            >
              {t('blogs.delete')}
            </button>
          </BulkBar>
        )}
        <div className="overflow-x-auto">
          <table className="data-table">
            <thead>
              <tr>
                {manage && (
                  <th className="w-10">
                    <input
                      type="checkbox"
                      aria-label={t('blogs.select_all')}
                      checked={allSelected}
                      onChange={() => setPicked(allSelected ? [] : ids)}
                    />
                  </th>
                )}
                <th>{t('blogs.name')}</th>
                <th>{t('pages.col_address')}</th>
                <th>
                  {t(kind === 'blog' ? 'blogs.posts_title' : 'blogs.blog')}
                </th>
                <th>
                  {t(kind === 'blog' ? 'pages.position' : 'blogs.published_at')}
                </th>
                <th>{t('pages.col_status')}</th>
                {manage && (
                  <th className="text-right">{t('common.actions')}</th>
                )}
              </tr>
            </thead>
            <tbody>
              {rows.data.length === 0 && (
                <tr>
                  <td
                    colSpan={columns}
                    className="py-10 text-center text-slate-500"
                  >
                    {t(
                      filters.search || filters.status || filters.blog
                        ? 'common.no_results'
                        : kind === 'blog'
                          ? 'blogs.empty'
                          : blogs.length > 0
                            ? 'blogs.posts_empty'
                            : 'blogs.posts_need_blog',
                    )}
                  </td>
                </tr>
              )}
              {rows.data.map((row) => (
                <tr
                  key={row.id}
                  className={row.state === 'live' ? '' : 'opacity-75'}
                >
                  {manage && (
                    <td>
                      <input
                        type="checkbox"
                        aria-label={t('blogs.select_row', { name: row.name })}
                        checked={selected.includes(row.id)}
                        onChange={() =>
                          setPicked((current) =>
                            current.includes(row.id)
                              ? current.filter((id) => id !== row.id)
                              : [...current, row.id],
                          )
                        }
                      />
                    </td>
                  )}
                  <td>
                    <Link
                      href={base + '/' + row.id + '/edit'}
                      className="flex items-center gap-3 font-semibold hover:underline"
                    >
                      {row.cover && (
                        <img
                          src={row.cover}
                          alt=""
                          className="size-10 rounded-md object-cover"
                        />
                      )}
                      {row.name}
                    </Link>
                  </td>
                  <td className="font-mono text-xs text-slate-600">
                    /{row.slug}
                  </td>
                  <td>
                    {kind === 'blog' ? (
                      <Link
                        className="text-link"
                        href={'/admin/blog-posts?blog=' + row.id}
                      >
                        {row.posts_count}
                      </Link>
                    ) : (
                      row.blog
                    )}
                  </td>
                  <td className="text-sm tabular-nums">
                    {kind === 'blog'
                      ? row.position
                      : row.published_at
                        ? new Date(row.published_at).toLocaleString(undefined, {
                            timeZone,
                            dateStyle: 'medium',
                            timeStyle: 'short',
                          })
                        : '—'}
                  </td>
                  <td>
                    <StatusBadge tone={blogStateTone[row.state]}>
                      {t(blogStateLabel[row.state])}
                    </StatusBadge>
                  </td>
                  {manage && (
                    <td>
                      <div className="flex justify-end gap-1">
                        {kind === 'blog' && (
                          <IconButton
                            icon="plus"
                            label={t('blogs.create_post') + ': ' + row.name}
                            href={'/admin/blog-posts/create?blog=' + row.id}
                          />
                        )}
                        <IconButton
                          icon="edit"
                          label={t('blogs.edit') + ': ' + row.name}
                          href={base + '/' + row.id + '/edit'}
                        />
                        <IconButton
                          icon="trash"
                          danger
                          label={t('blogs.delete') + ': ' + row.name}
                          onClick={() => remove(row)}
                        />
                      </div>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <Pagination
          page={rows}
          perPage={{
            value: filters.per_page ?? '25',
            options: ['25', '50', '100'],
            onChange: (per_page) => apply({ per_page }),
          }}
        />
      </section>
    </AdminLayout>
  );
}
