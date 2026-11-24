import { Icon } from '../../../components/admin/list/IconButton';
import EditorHeader from '../../../components/admin/EditorHeader';
import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import Pagination from '../../../components/admin/Pagination';
import ConfirmDeleteDialog from '../../../components/admin/ConfirmDeleteDialog';
import FormErrors from '../../../components/admin/FormErrors';
import FilterBar, {
  FilterChip,
  SearchField,
} from '../../../components/admin/list/FilterBar';
import IconButton from '../../../components/admin/list/IconButton';
import Switch from '../../../components/admin/Switch';
import { useListQuery } from '../../../lib/useListQuery';
import { useTranslation } from '../../../lib/useTranslation';
import { usePreferences } from '../../../lib/usePreferences';
import type { AdminShell, Pagination as Page } from '../../../types/admin';
import type { SharedProps } from '../../../types/page';
import type { ReelRow } from '../../../types/reels';

export default function ReelsIndex({
  rows,
  filters,
  ...shell
}: AdminShell & { rows: Page<ReelRow>; filters: Record<string, string> }) {
  const t = useTranslation();
  const { capabilities, errors } = usePage<SharedProps>().props;
  const { timezone: timeZone } = usePreferences();
  const manage = !!capabilities['reels.manage'];
  const apply = useListQuery('/admin/reels', filters);
  const [search, setSearch] = useState(filters.search ?? '');
  const [deleting, setDeleting] = useState<ReelRow | null>(null);
  const [pending, setPending] = useState<string | null>(null);
  return (
    <AdminLayout title={t('reels.title')} {...shell}>
      <EditorHeader
        heading={t('reels.title')}
        iconKey="video"
        description={
          <>
            <p className="mt-1 text-sm text-slate-500">
              {t('reels.description')}
            </p>
          </>
        }
        actions={
          <>
            {manage && (
              <Link className="button-primary" href="/admin/reels/create">
                <Icon name="plus" className="size-5 shrink-0" />
                {t('reels.create')}
              </Link>
            )}
          </>
        }
      />
      <FormErrors errors={errors ?? {}} />
      <section className="list-card">
        <FilterBar
          resetHref="/admin/reels"
          active={!!(filters.search || filters.status || filters.sort)}
          search={
            <SearchField
              id="reels-search"
              value={search}
              onChange={setSearch}
              placeholder={t('common.search')}
              onSubmit={() => apply({ search })}
            />
          }
          chips={
            <>
              <FilterChip
                label={t('common.status')}
                value={filters.status ?? ''}
                options={[
                  { value: '', label: t('common.all') },
                  { value: 'active', label: t('common.active') },
                  { value: 'disabled', label: t('common.inactive') },
                ]}
                onChange={(status) => apply({ status })}
              />
              <FilterChip
                label={t('layouts.source')}
                value={filters.sort ?? 'newest'}
                options={[
                  { value: 'newest', label: t('reels.newest') },
                  { value: 'views', label: t('reels.total_views') },
                ]}
                onChange={(sort) => apply({ sort })}
              />
            </>
          }
        />
        <div className="overflow-x-auto">
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('reels.name')}</th>
                <th>{t('reels.total_views')}</th>
                <th>{t('reels.created_at')}</th>
                <th className="text-right">{t('common.actions')}</th>
              </tr>
            </thead>
            <tbody>
              {rows.data.length === 0 && (
                <tr>
                  <td colSpan={4} className="py-10 text-center text-slate-500">
                    {t('reels.empty')}
                  </td>
                </tr>
              )}
              {rows.data.map((row) => (
                <tr key={row.id}>
                  <td>
                    <Link
                      href={`/admin/reels/${row.id}/edit`}
                      className="flex items-center gap-3 font-semibold"
                    >
                      <img
                        className="h-16 w-10 rounded object-cover"
                        src={row.cover}
                        alt=""
                      />
                      {row.name}
                    </Link>
                  </td>
                  <td>
                    {capabilities['reels.analytics'] ? (
                      <Link
                        className="text-link"
                        href={`/admin/reels/${row.id}/history`}
                      >
                        {row.views}
                      </Link>
                    ) : (
                      row.views
                    )}
                  </td>
                  <td>
                    {new Date(row.created_at).toLocaleDateString(undefined, {
                      timeZone,
                    })}
                  </td>
                  <td>
                    <div className="flex items-center justify-end gap-2">
                      {manage && (
                        <IconButton
                          icon="trash"
                          danger
                          label={t('reels.delete_confirm') + ': ' + row.name}
                          onClick={() => setDeleting(row)}
                        />
                      )}
                      <IconButton
                        icon="edit"
                        label={
                          t(manage ? 'reels.edit' : 'reels.name') +
                          ': ' +
                          row.name
                        }
                        href={`/admin/reels/${row.id}/edit`}
                      />
                      <Switch
                        checked={row.status === 'active'}
                        disabled={!manage || pending !== null}
                        label={t('common.status') + ': ' + row.name}
                        onChange={(active) => {
                          setPending(row.id);
                          router.patch(
                            `/admin/reels/${row.id}/status`,
                            {
                              version: row.version,
                              status: active ? 'active' : 'disabled',
                            },
                            {
                              preserveScroll: true,
                              onFinish: () => setPending(null),
                            },
                          );
                        }}
                      />
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <Pagination page={rows} />
      </section>
      {deleting && (
        <ConfirmDeleteDialog
          title={t('reels.delete_confirm')}
          facts={[t('reels.delete_impact')]}
          onCancel={() => setDeleting(null)}
          onConfirm={() =>
            router.delete(`/admin/reels/${deleting.id}`, {
              data: { version: deleting.version },
              onFinish: () => setDeleting(null),
            })
          }
        />
      )}
    </AdminLayout>
  );
}
