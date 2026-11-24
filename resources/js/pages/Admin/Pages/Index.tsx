import EditorHeader from '../../../components/admin/EditorHeader';
import { Link, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import BulkBar from '../../../components/admin/list/BulkBar';
import IconButton, { Icon } from '../../../components/admin/list/IconButton';
import StatusBadge from '../../../components/admin/list/StatusBadge';
import { pageLabels, pageStateTone } from '../../../lib/pageLabels';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';
import type { PageNode } from '../../../types/pages';
import type { SharedProps } from '../../../types/page';

type Props = AdminShell & { pages: PageNode[] };

export default function PagesIndex({ pages, ...shell }: Props) {
  const t = useTranslation();
  const { capabilities } = usePage<SharedProps>().props;
  const manage = !!capabilities['pages.manage'];
  const [search, setSearch] = useState('');
  const [picked, setPicked] = useState<string[]>([]);
  const term = search.trim().toLocaleLowerCase();
  // Filtering keeps the matching pages; the tree indentation is kept for orientation.
  const visible = useMemo(
    () =>
      term
        ? pages.filter(
            (p) =>
              p.name.toLocaleLowerCase().includes(term) ||
              p.slug.includes(term),
          )
        : pages,
    [pages, term],
  );
  const ids = visible.map((p) => p.id);
  const selected = picked.filter((id) => ids.includes(id));
  const allSelected =
    ids.length > 0 && ids.every((id) => selected.includes(id));

  const bulk = (action: string) => {
    if (
      action === 'delete' &&
      !window.confirm(
        t('pages.bulk_delete_confirm', { count: selected.length }),
      )
    )
      return;
    router.post(
      '/admin/pages/bulk',
      { ids: selected, action },
      { preserveScroll: true, onSuccess: () => setPicked([]) },
    );
  };
  const remove = (page: PageNode) => {
    if (window.confirm(t('pages.delete_confirm', { name: page.name })))
      router.delete('/admin/pages/' + page.id, {
        data: { version: page.version },
        preserveScroll: true,
      });
  };

  return (
    <AdminLayout title={t('pages.title')} {...shell}>
      <EditorHeader
        heading={t('pages.title')}
        iconKey="content"
        description={
          <>
            <p className="mt-1 max-w-3xl text-sm text-slate-500">
              {t('pages.description')}
            </p>
          </>
        }
        actions={
          <>
            {manage && (
              <Link href="/admin/pages/create" className="button-primary gap-2">
                <Icon name="plus" />
                {t('pages.create')}
              </Link>
            )}
          </>
        }
      />
      <section className="list-card" aria-label={t('pages.title')}>
        <div className="p-4 sm:p-5">
          <input
            type="search"
            className="field max-w-md"
            aria-label={t('pages.search')}
            placeholder={t('pages.search')}
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
        </div>
        {manage && selected.length > 0 && (
          <BulkBar count={selected.length} onClear={() => setPicked([])}>
            <button
              type="button"
              className="button-secondary"
              onClick={() => bulk('active')}
            >
              {t('pages.bulk_active')}
            </button>
            <button
              type="button"
              className="button-secondary"
              onClick={() => bulk('hidden')}
            >
              {t('pages.bulk_hidden')}
            </button>
            <button
              type="button"
              className="button-secondary"
              onClick={() => bulk('disabled')}
            >
              {t('pages.bulk_disabled')}
            </button>
            <button
              type="button"
              className="button-secondary text-red-700"
              onClick={() => bulk('delete')}
            >
              {t('pages.bulk_delete')}
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
                      aria-label={t('pages.select_all')}
                      checked={allSelected}
                      onChange={() => setPicked(allSelected ? [] : ids)}
                    />
                  </th>
                )}
                <th>{t('pages.col_page')}</th>
                <th>{t('pages.col_address')}</th>
                <th>{t('pages.col_position')}</th>
                <th>{t('pages.col_status')}</th>
                {manage && (
                  <th className="text-right">{t('common.actions')}</th>
                )}
              </tr>
            </thead>
            <tbody>
              {visible.length === 0 && (
                <tr>
                  <td
                    colSpan={manage ? 6 : 4}
                    className="py-10 text-center text-slate-500"
                  >
                    {t(term ? 'pages.no_matches' : 'pages.empty')}
                  </td>
                </tr>
              )}
              {visible.map((page) => (
                <tr
                  key={page.id}
                  className={page.state === 'live' ? '' : 'opacity-75'}
                >
                  {manage && (
                    <td>
                      <input
                        type="checkbox"
                        aria-label={t('pages.select_row', { name: page.name })}
                        checked={selected.includes(page.id)}
                        onChange={() =>
                          setPicked((current) =>
                            current.includes(page.id)
                              ? current.filter((id) => id !== page.id)
                              : [...current, page.id],
                          )
                        }
                      />
                    </td>
                  )}
                  <td>
                    <div
                      className="flex items-center gap-2"
                      style={{ paddingLeft: page.depth * 20 }}
                    >
                      {page.depth > 0 && (
                        <span aria-hidden="true" className="text-slate-300">
                          └
                        </span>
                      )}
                      <Link
                        href={'/admin/pages/' + page.id + '/edit'}
                        className="font-semibold hover:underline"
                      >
                        {page.name}
                      </Link>
                    </div>
                  </td>
                  <td className="font-mono text-xs text-slate-600">
                    /{page.slug}
                  </td>
                  <td className="text-sm tabular-nums">{page.position}</td>
                  <td>
                    <StatusBadge tone={pageStateTone[page.state]}>
                      {t(pageLabels.state[page.state])}
                    </StatusBadge>
                  </td>
                  {manage && (
                    <td>
                      <div className="flex justify-end gap-1">
                        <IconButton
                          icon="plus"
                          label={t('pages.create_child') + ': ' + page.name}
                          href={'/admin/pages/create?parent=' + page.id}
                        />
                        <IconButton
                          icon="edit"
                          label={t('pages.edit') + ': ' + page.name}
                          href={'/admin/pages/' + page.id + '/edit'}
                        />
                        <IconButton
                          icon="trash"
                          danger
                          label={t('pages.delete') + ': ' + page.name}
                          onClick={() => remove(page)}
                        />
                      </div>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>
    </AdminLayout>
  );
}
