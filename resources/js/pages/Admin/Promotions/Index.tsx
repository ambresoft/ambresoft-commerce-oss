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
import Switch from '../../../components/admin/Switch';
import { useTranslation } from '../../../lib/useTranslation';
import { useListQuery } from '../../../lib/useListQuery';
import type { AdminShell, Pagination as Page } from '../../../types/admin';
import type { SharedProps } from '../../../types/page';
import type { PromotionRow } from '../../../types/promotions';

export default function PromotionIndex({
  promotions,
  filters,
  ...shell
}: AdminShell & {
  promotions: Page<PromotionRow>;
  filters: Record<string, string>;
}) {
  const t = useTranslation();
  const { capabilities, errors } = usePage<
    SharedProps & { errors: Record<string, string> }
  >().props;
  const apply = useListQuery('/admin/promotions', filters);
  const [search, setSearch] = useState(filters.search ?? '');
  const [pending, setPending] = useState<string | null>(null);
  return (
    <AdminLayout title={t('promotions.title')} {...shell}>
      <EditorHeader
        heading={t('promotions.title')}
        iconKey="promotions"
        description={
          <>
            <p className="mt-2 text-sm text-slate-500">
              {t('promotions.intro')}
            </p>
          </>
        }
        actions={
          <>
            {capabilities['promotions.manage'] && (
              <Link className="button-primary" href="/admin/promotions/create">
                <Icon name="plus" className="size-5 shrink-0" />
                {t('promotions.create')}
              </Link>
            )}
          </>
        }
      />
      <FormErrors errors={errors ?? {}} />
      <div className="list-card">
        <FilterBar
          resetHref="/admin/promotions"
          active={
            !!filters.search ||
            !!filters.status ||
            !!filters.mode ||
            !!filters.benefit
          }
          search={
            <SearchField
              id="promotion-search"
              value={search}
              onChange={setSearch}
              onSubmit={() => apply({ search })}
              placeholder={t('promotions.search')}
            />
          }
          chips={
            <>
              {(['status', 'mode', 'benefit'] as const).map((key) => (
                <FilterChip
                  key={key}
                  label={t(`promotions.${key}`)}
                  value={filters[key] ?? ''}
                  options={(key === 'status'
                    ? ['active', 'disabled']
                    : key === 'mode'
                      ? ['automatic', 'coupon']
                      : ['percentage', 'fixed', 'free_shipping', 'coupon']
                  ).map((value) => ({
                    value,
                    label: t(`promotions.${value}`),
                  }))}
                  onChange={(value) => apply({ [key]: value })}
                />
              ))}
            </>
          }
          sort={
            <SortControl
              value={filters.sort ?? 'priority'}
              direction={filters.direction === 'desc' ? 'desc' : 'asc'}
              options={[
                'name',
                'priority',
                'starts_at',
                'ends_at',
                'status',
              ].map((value) => ({ value, label: t(`promotions.${value}`) }))}
              onChange={(sort, direction) => apply({ sort, direction })}
            />
          }
        />
        <div className="overflow-x-auto">
          <table className="data-table">
            <thead>
              <tr>
                {[
                  'name',
                  'mode',
                  'benefit',
                  'status',
                  'starts_at',
                  'ends_at',
                  'priority',
                  'used',
                ].map((key) => (
                  <th key={key}>{t(`promotions.${key}`)}</th>
                ))}
                {capabilities['promotions.manage'] && (
                  <th>{t('promotions.actions')}</th>
                )}
              </tr>
            </thead>
            <tbody>
              {promotions.data.length === 0 && (
                <tr>
                  <td colSpan={9} className="py-12 text-center text-slate-500">
                    {t('promotions.empty')}
                  </td>
                </tr>
              )}
              {promotions.data.map((p) => (
                <tr key={p.id}>
                  <td>
                    <Link
                      href={`/admin/promotions/${p.id}/edit`}
                      className="text-link font-medium"
                    >
                      {p.name}
                    </Link>
                  </td>
                  <td>
                    {t(`promotions.${p.mode}`)}
                    {p.coupon_code && (
                      <span className="mt-1 block font-mono text-xs text-slate-500">
                        {p.coupon_code}
                      </span>
                    )}
                  </td>
                  <td>
                    {t(`promotions.${p.benefit}`)}
                    {p.amount && (
                      <span className="mt-1 block text-xs text-slate-500">
                        {p.amount}
                        {p.benefit === 'percentage' ? '%' : ''}
                      </span>
                    )}
                  </td>
                  <td>
                    <StatusBadge
                      tone={p.state === 'active' ? 'success' : 'neutral'}
                    >
                      {t(`promotions.${p.state}`)}
                    </StatusBadge>
                  </td>
                  <td className="whitespace-nowrap">
                    {p.starts_at?.replace('T', ' ') || '—'}
                  </td>
                  <td className="whitespace-nowrap">
                    {p.ends_at?.replace('T', ' ') || '—'}
                  </td>
                  <td>{p.priority}</td>
                  <td>
                    {p.used} / {p.total_limit ?? t('promotions.unlimited')}
                  </td>
                  {capabilities['promotions.manage'] && (
                    <td>
                      <Switch
                        checked={p.status === 'active'}
                        disabled={pending === p.id}
                        label={`${t('promotions.status')}: ${p.name}`}
                        onChange={(enabled) => {
                          setPending(p.id);
                          router.patch(
                            `/admin/promotions/${p.id}/status`,
                            {
                              version: p.version,
                              status: enabled ? 'active' : 'disabled',
                            },
                            {
                              preserveScroll: true,
                              onFinish: () => setPending(null),
                            },
                          );
                        }}
                      />
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <Pagination
          page={promotions}
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
