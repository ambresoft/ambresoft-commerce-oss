import { Icon } from '../../../components/admin/list/IconButton';
import EditorHeader from '../../../components/admin/EditorHeader';
import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import Pagination from '../../../components/admin/Pagination';
import FormErrors from '../../../components/admin/FormErrors';
import FilterBar, {
  SearchField,
  FilterChip,
  SortControl,
} from '../../../components/admin/list/FilterBar';
import StatusBadge from '../../../components/admin/list/StatusBadge';
import { useTranslation } from '../../../lib/useTranslation';
import { useListQuery } from '../../../lib/useListQuery';
import { useDateDisplay } from '../../../lib/useDateDisplay';
import type { AdminShell, Pagination as Page } from '../../../types/admin';
import type { SharedProps } from '../../../types/page';
import type { CouponRow } from '../../../types/coupons';

export default function CouponIndex({
  coupons,
  filters,
  ...shell
}: AdminShell & { coupons: Page<CouponRow>; filters: Record<string, string> }) {
  const t = useTranslation();
  const { capabilities, errors } = usePage<
    SharedProps & { errors: Record<string, string> }
  >().props;
  const apply = useListQuery('/admin/coupons', filters);
  const [search, setSearch] = useState(filters.search ?? '');
  const [selected, setSelected] = useState<Record<string, boolean>>({});
  const { date } = useDateDisplay();
  const rows = coupons.data.filter((r) => selected[r.id]);
  const when = (value: string | null) =>
    value ? date.format(new Date(value + 'T12:00:00')) : t('coupons.no_date');
  const remove = () => {
    if (
      !rows.length ||
      !window.confirm(t('coupons.confirm_delete', { count: rows.length }))
    )
      return;
    router.delete('/admin/coupons/bulk', {
      data: { rows: rows.map((r) => ({ id: r.id, version: r.version })) },
      onSuccess: () => setSelected({}),
    });
  };
  return (
    <AdminLayout title={t('coupons.title')} {...shell}>
      <EditorHeader
        heading={t('coupons.title')}
        iconKey="discounts"
        description={
          <>
            <p className="mt-2 text-sm text-slate-500">{t('coupons.intro')}</p>
          </>
        }
        actions={
          <>
            {capabilities['coupons.manage'] && (
              <Link className="button-primary" href="/admin/coupons/create">
                <Icon name="plus" className="size-5 shrink-0" />
                {t('coupons.create')}
              </Link>
            )}
          </>
        }
      />
      <FormErrors errors={errors ?? {}} />
      <div className="list-card">
        <FilterBar
          resetHref="/admin/coupons"
          active={!!filters.search || !!filters.status}
          search={
            <SearchField
              id="coupon-search"
              value={search}
              onChange={setSearch}
              onSubmit={() => apply({ search })}
              placeholder={t('coupons.search')}
            />
          }
          chips={
            <FilterChip
              label={t('coupons.status')}
              value={filters.status ?? ''}
              options={[
                { value: 'active', label: t('coupons.active') },
                { value: 'disabled', label: t('coupons.disabled') },
              ]}
              onChange={(status) => apply({ status })}
            />
          }
          sort={
            <SortControl
              value={filters.sort ?? 'name'}
              direction={filters.direction === 'desc' ? 'desc' : 'asc'}
              options={['name', 'code', 'starts_on', 'ends_on', 'status'].map(
                (value) => ({ value, label: t(`coupons.${value}`) }),
              )}
              onChange={(sort, direction) => apply({ sort, direction })}
            />
          }
        />
        {capabilities['coupons.delete'] && rows.length > 0 && (
          <div className="border-b border-slate-200 px-5 py-3">
            <button
              type="button"
              className="button-secondary text-red-700"
              onClick={remove}
            >
              {t('common.delete')} ({rows.length})
            </button>
          </div>
        )}
        <div className="relative max-w-full overflow-x-auto">
          <table className="data-table">
            <thead>
              <tr>
                {capabilities['coupons.delete'] && (
                  <th className="w-10">
                    <input
                      type="checkbox"
                      aria-label={t('coupons.select_all')}
                      checked={
                        coupons.data.length > 0 &&
                        rows.length === coupons.data.length
                      }
                      onChange={(e) =>
                        setSelected(
                          Object.fromEntries(
                            coupons.data.map((r) => [r.id, e.target.checked]),
                          ),
                        )
                      }
                    />
                  </th>
                )}
                {[
                  'name',
                  'code',
                  'amount',
                  'starts_on',
                  'ends_on',
                  'used',
                  'status',
                ].map((key) => (
                  <th key={key}>{t(`coupons.${key}`)}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {coupons.data.length === 0 && (
                <tr>
                  <td colSpan={8} className="py-12 text-center text-slate-500">
                    {t('coupons.empty')}
                  </td>
                </tr>
              )}
              {coupons.data.map((row) => (
                <tr key={row.id}>
                  {capabilities['coupons.delete'] && (
                    <td>
                      <input
                        type="checkbox"
                        aria-label={row.name}
                        checked={!!selected[row.id]}
                        onChange={(e) =>
                          setSelected({
                            ...selected,
                            [row.id]: e.target.checked,
                          })
                        }
                      />
                    </td>
                  )}
                  <td>
                    <Link
                      href={`/admin/coupons/${row.id}/edit`}
                      className="text-link font-medium"
                    >
                      {row.name}
                    </Link>
                  </td>
                  <td className="font-mono">{row.code}</td>
                  <td>
                    <span>{row.discount}</span>
                    {row.free_shipping && (
                      <span className="mt-1 block text-xs text-slate-500">
                        {t('coupons.free_shipping')}
                      </span>
                    )}
                  </td>
                  <td className="whitespace-nowrap">{when(row.starts_on)}</td>
                  <td className="whitespace-nowrap">{when(row.ends_on)}</td>
                  <td>
                    {row.used} / {row.total_limit ?? t('coupons.unlimited')}
                  </td>
                  <td>
                    <StatusBadge
                      tone={row.status === 'active' ? 'success' : 'neutral'}
                    >
                      {t(`coupons.${row.status}`)}
                    </StatusBadge>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <Pagination
          page={coupons}
          perPage={{
            value: filters.per_page ?? '25',
            options: ['25', '50', '100'],
            onChange: (per_page) => apply({ per_page }),
          }}
        />
      </div>
    </AdminLayout>
  );
}
