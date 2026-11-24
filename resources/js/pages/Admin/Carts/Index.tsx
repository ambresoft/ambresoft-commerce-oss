import EditorHeader from '../../../components/admin/EditorHeader';
import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import Pagination from '../../../components/admin/Pagination';
import FilterBar, {
  SearchField,
  FilterChip,
  SortControl,
} from '../../../components/admin/list/FilterBar';
import IconButton from '../../../components/admin/list/IconButton';
import StatusBadge from '../../../components/admin/list/StatusBadge';
import { useDateDisplay } from '../../../lib/useDateDisplay';
import { useTranslation } from '../../../lib/useTranslation';
import { useListQuery } from '../../../lib/useListQuery';
import type { AdminShell, Pagination as Page } from '../../../types/admin';
import type { CartRow } from '../../../types/carts';
import type { SharedProps } from '../../../types/page';

/** Catalog → Carts: every cart of customers and guests, newest change first. */
export default function CartsIndex({
  carts,
  filters,
  ...shell
}: AdminShell & { carts: Page<CartRow>; filters: Record<string, string> }) {
  const t = useTranslation();
  const { capabilities } = usePage<SharedProps>().props;
  const apply = useListQuery('/admin/carts', filters);
  const [search, setSearch] = useState(filters.search ?? '');
  const { date, time } = useDateDisplay();
  const when = (iso: string) =>
    date.format(new Date(iso)) + ' ' + time.format(new Date(iso));
  const filtered = ['search', 'type', 'contents'].some((key) => filters[key]);
  return (
    <AdminLayout title={t('carts.title')} {...shell}>
      <EditorHeader
        heading={t('carts.title')}
        iconKey="bought_together"
        description={
          <>
            <p className="mt-1 text-sm text-slate-500">
              {t('carts.count', { count: carts.total })}
            </p>
          </>
        }
      />
      <section className="list-card" aria-label={t('carts.title')}>
        <FilterBar
          resetHref="/admin/carts"
          active={filtered}
          search={
            <SearchField
              id="cart-search"
              value={search}
              onChange={setSearch}
              placeholder={t(
                capabilities['customers.view']
                  ? 'carts.search_with_email'
                  : 'carts.search',
              )}
              onSubmit={() => apply({ search })}
            />
          }
          sort={
            <SortControl
              value={filters.sort ?? 'updated_at'}
              direction={filters.direction === 'asc' ? 'asc' : 'desc'}
              options={['updated_at', 'created_at', 'units'].map((value) => ({
                value,
                label: t(`carts.sort_${value}`),
              }))}
              onChange={(sort, direction) => apply({ sort, direction })}
            />
          }
          chips={
            <>
              <FilterChip
                label={t('carts.type')}
                value={filters.type ?? ''}
                options={[
                  { value: '', label: t('common.all') },
                  { value: 'customer', label: t('carts.type_customer') },
                  { value: 'guest', label: t('carts.type_guest') },
                ]}
                onChange={(type) => apply({ type })}
              />
              <FilterChip
                label={t('carts.contents')}
                value={filters.contents ?? ''}
                options={[
                  { value: '', label: t('common.all') },
                  { value: 'with', label: t('carts.contents_with') },
                  { value: 'empty', label: t('carts.contents_empty') },
                ]}
                onChange={(contents) => apply({ contents })}
              />
            </>
          }
        />
        <div className="relative max-w-full overflow-x-auto border-t border-slate-200">
          <table className="data-table">
            <thead>
              <tr>
                {[
                  'col_cart',
                  'col_customer',
                  'col_lines',
                  'col_units',
                  'col_created',
                  'col_updated',
                ].map((field) => (
                  <th key={field}>{t('carts.' + field)}</th>
                ))}
                <th>
                  <span className="sr-only">{t('common.actions')}</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {carts.data.map((row) => (
                <tr key={row.id}>
                  <td className="text-sm">
                    <Link
                      href={'/admin/carts/' + row.id}
                      className="font-medium hover:underline"
                    >
                      #{row.id}
                    </Link>
                    <span
                      className="mt-0.5 block font-mono text-xs text-slate-500"
                      title={row.uuid}
                    >
                      {row.uuid.slice(0, 8)}…
                    </span>
                  </td>
                  <td className="text-sm">
                    {row.customer ? (
                      <>
                        <span className="block max-w-48 truncate">
                          {row.customer.name}
                        </span>
                        {row.customer.email && (
                          <span className="mt-0.5 block max-w-48 truncate text-xs text-slate-500">
                            {row.customer.email}
                          </span>
                        )}
                      </>
                    ) : (
                      <StatusBadge tone="neutral">
                        {t('carts.guest')}
                      </StatusBadge>
                    )}
                  </td>
                  <td className="text-sm tabular-nums">{row.lines}</td>
                  <td className="text-sm tabular-nums">{row.units}</td>
                  <td className="text-xs whitespace-nowrap text-slate-500">
                    {when(row.created_at)}
                  </td>
                  <td className="text-xs whitespace-nowrap text-slate-500">
                    {when(row.updated_at)}
                  </td>
                  <td>
                    <div className="flex justify-end">
                      <IconButton
                        icon="edit"
                        href={'/admin/carts/' + row.id}
                        label={t('carts.open_id', { id: row.id })}
                      />
                    </div>
                  </td>
                </tr>
              ))}
              {carts.data.length === 0 && (
                <tr>
                  <td colSpan={7} className="py-10 text-center text-slate-500">
                    {t(filtered ? 'carts.empty_filtered' : 'carts.empty')}
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
        <Pagination
          page={carts}
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
