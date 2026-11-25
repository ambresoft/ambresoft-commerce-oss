import { Icon } from '../../../components/admin/list/IconButton';
import EditorHeader from '../../../components/admin/EditorHeader';
import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import Pagination from '../../../components/admin/Pagination';
import FilterBar, {
  FilterChip,
  SearchField,
} from '../../../components/admin/list/FilterBar';
import StatusBadge from '../../../components/admin/list/StatusBadge';
import IconButton from '../../../components/admin/list/IconButton';
import { useListQuery } from '../../../lib/useListQuery';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell, Pagination as Page } from '../../../types/admin';
import type { SharedProps } from '../../../types/page';
import type { MenuRow } from '../../../types/menus';

export default function Menus({
  menus,
  filters,
  ...shell
}: AdminShell & { menus: Page<MenuRow>; filters: Record<string, string> }) {
  const t = useTranslation();
  const { capabilities } = usePage<SharedProps>().props;
  const [search, setSearch] = useState(filters.search ?? '');
  const apply = useListQuery('/admin/menus', filters);
  return (
    <AdminLayout title={t('menus.title')} {...shell}>
      <EditorHeader
        heading={t('menus.title')}
        iconKey="features"
        description={
          <>
            <p className="mt-2 text-sm text-slate-600">{t('menus.intro')}</p>
          </>
        }
        actions={
          <>
            {capabilities['menus.manage'] && (
              <Link className="button-primary" href="/admin/menus/create">
                <Icon name="plus" className="size-5 shrink-0" />
                {t('menus.create')}
              </Link>
            )}
          </>
        }
      />
      <div className="list-card">
        <FilterBar
          active={Object.values(filters).some(Boolean)}
          resetHref="/admin/menus"
          search={
            <SearchField
              id="menu-search"
              value={search}
              onChange={setSearch}
              placeholder={t('menus.search')}
              onSubmit={() => apply({ search })}
            />
          }
          chips={
            <>
              {(['status', 'placement', 'source'] as const).map((field) => (
                <FilterChip
                  key={field}
                  label={t(
                    field === 'status' ? 'common.status' : 'menus.' + field,
                  )}
                  value={filters[field] ?? ''}
                  options={[
                    { value: '', label: t('common.all') },
                    ...{
                      status: ['active', 'disabled'],
                      placement: ['header', 'footer', 'other'],
                      source: ['manual', 'categories'],
                    }[field].map((value) => ({
                      value,
                      label: t('menus.' + value),
                    })),
                  ]}
                  onChange={(value) => apply({ [field]: value })}
                />
              ))}
            </>
          }
        />
        <div className="overflow-x-auto">
          <table className="data-table">
            <thead>
              <tr>
                {[
                  'name',
                  'placement',
                  'source',
                  'items',
                  'status',
                  'actions',
                ].map((k) => (
                  <th key={k}>
                    {t(
                      ['name', 'status', 'actions'].includes(k)
                        ? 'common.' + k
                        : 'menus.' + k,
                    )}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {menus.data.map((m) => (
                <tr key={m.id}>
                  <td>
                    <Link
                      className="font-medium text-primary hover:underline"
                      href={'/admin/menus/' + m.id}
                    >
                      {m.name}
                    </Link>
                  </td>
                  <td>{t('menus.' + m.placement)}</td>
                  <td>
                    {t('menus.' + m.source)}
                    {m.style === 'link_groups'
                      ? ` · ${t('menus.link_groups')}`
                      : ''}
                  </td>
                  <td>
                    {m.source === 'categories' ? t('menus.live') : m.item_count}
                  </td>
                  <td>
                    <StatusBadge
                      tone={m.status === 'active' ? 'success' : 'neutral'}
                    >
                      {t('menus.' + m.status)}
                    </StatusBadge>
                  </td>
                  <td>
                    <IconButton
                      icon="edit"
                      label={t('common.edit')}
                      href={'/admin/menus/' + m.id + '/edit'}
                    />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {menus.data.length === 0 && (
          <p className="p-8 text-center text-sm text-slate-500">
            {t(
              menus.total === 0 && Object.values(filters).some(Boolean)
                ? 'common.no_results'
                : 'common.empty',
            )}
          </p>
        )}
        <Pagination page={menus} />
      </div>
    </AdminLayout>
  );
}
