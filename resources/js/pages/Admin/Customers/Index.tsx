import EditorHeader from '../../../components/admin/EditorHeader';
import { useDateDisplay } from '../../../lib/useDateDisplay';
import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import Pagination from '../../../components/admin/Pagination';
import FilterBar, {
  FilterChip,
  SearchField,
  SortControl,
} from '../../../components/admin/list/FilterBar';
import { Icon } from '../../../components/admin/list/IconButton';
import StatusBadge from '../../../components/admin/list/StatusBadge';
import { useListQuery } from '../../../lib/useListQuery';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell, Pagination as Page } from '../../../types/admin';
import type { CustomerRow } from '../../../types/customers';
import type { SharedProps } from '../../../types/page';

type Props = AdminShell & {
  customers: Page<CustomerRow>;
  filters: Record<string, string>;
  groups: { id: string; name: string }[];
};

export default function CustomersIndex({
  customers,
  filters,
  groups,
  ...shell
}: Props) {
  const t = useTranslation();
  const { capabilities } = usePage<SharedProps>().props;
  const apply = useListQuery('/admin/customers', filters);
  const [search, setSearch] = useState(filters.search ?? '');
  const filtered = ['search', 'status', 'group', 'blacklisted', 'account'].some(
    (key) => filters[key],
  );
  const { date: date } = useDateDisplay();
  return (
    <AdminLayout title={t('customers.title')} {...shell}>
      <EditorHeader
        heading={t('customers.title')}
        iconKey="customers"
        description={
          <>
            <p className="mt-1 text-sm text-slate-500">
              {t('customers.count', { count: customers.total })}
            </p>
          </>
        }
        actions={
          <>
            {capabilities['customers.create'] && (
              <Link
                className="button-primary gap-2"
                href="/admin/customers/create"
              >
                <Icon name="plus" />
                {t('customers.create')}
              </Link>
            )}
          </>
        }
      />
      <section className="list-card" aria-label={t('customers.title')}>
        <FilterBar
          resetHref="/admin/customers"
          active={filtered}
          search={
            <SearchField
              id="customer-search"
              value={search}
              placeholder={t('customers.search_placeholder')}
              onChange={setSearch}
              onSubmit={() => apply({ search })}
            />
          }
          sort={
            <SortControl
              value={filters.sort ?? 'created_at'}
              direction={
                filters.direction === 'asc' ||
                (!filters.direction &&
                  filters.sort &&
                  filters.sort !== 'created_at')
                  ? 'asc'
                  : 'desc'
              }
              options={[
                { value: 'created_at', label: t('customers.sort_created') },
                { value: 'name', label: t('customers.sort_name') },
                { value: 'email', label: t('customers.sort_email') },
              ]}
              onChange={(sort, direction) => apply({ sort, direction })}
            />
          }
          chips={
            <>
              <FilterChip
                label={t('common.status')}
                value={filters.status ?? ''}
                options={[
                  { value: '', label: t('common.all') },
                  { value: 'active', label: t('customers.active') },
                  { value: 'disabled', label: t('customers.disabled') },
                ]}
                onChange={(value) => apply({ status: value })}
              />
              <FilterChip
                label={t('customers.blacklist_filter')}
                value={filters.blacklisted ?? ''}
                options={[
                  { value: '', label: t('common.all') },
                  { value: 'yes', label: t('customers.blacklist_yes') },
                  { value: 'no', label: t('customers.blacklist_no') },
                ]}
                onChange={(value) => apply({ blacklisted: value })}
              />
              <FilterChip
                label={t('customers.account_filter')}
                value={filters.account ?? ''}
                options={[
                  { value: '', label: t('common.all') },
                  { value: 'yes', label: t('customers.account_yes') },
                  { value: 'no', label: t('customers.account_no') },
                ]}
                onChange={(value) => apply({ account: value })}
              />
              <FilterChip
                label={t('customers.group')}
                value={filters.group ?? ''}
                options={[
                  { value: '', label: t('common.all') },
                  ...groups.map((g) => ({ value: g.id, label: g.name })),
                ]}
                onChange={(value) => apply({ group: value })}
              />
            </>
          }
        />
        <div className="overflow-x-auto">
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('common.name')}</th>
                <th>{t('customers.email')}</th>
                <th>{t('customers.phone')}</th>
                <th>{t('common.status')}</th>
                <th>{t('customers.groups')}</th>
                <th>{t('customers.created')}</th>
              </tr>
            </thead>
            <tbody>
              {customers.data.length === 0 && (
                <tr>
                  <td colSpan={6} className="py-10 text-center text-slate-500">
                    {t(filtered ? 'customers.no_matches' : 'customers.empty')}
                  </td>
                </tr>
              )}
              {customers.data.map((c) => (
                <tr key={c.id}>
                  <td>
                    <Link
                      className="font-semibold hover:underline"
                      href={'/admin/customers/' + c.id}
                    >
                      {c.name}
                    </Link>
                  </td>
                  <td className="text-sm">{c.email}</td>
                  <td className="text-sm tabular-nums">
                    {c.phone ?? <span className="text-slate-400">—</span>}
                  </td>
                  <td>
                    <div className="flex flex-wrap gap-1.5">
                      <StatusBadge
                        tone={c.status === 'active' ? 'success' : 'neutral'}
                      >
                        {t(
                          c.deleted_at
                            ? 'customers.deleted'
                            : c.status === 'active'
                              ? 'customers.active'
                              : 'customers.disabled',
                        )}
                      </StatusBadge>
                      {c.blacklisted && (
                        <StatusBadge tone="danger">
                          {t('customers.blacklisted')}
                        </StatusBadge>
                      )}
                      {c.has_account && (
                        <StatusBadge tone="neutral">
                          {t('customers.account_badge')}
                        </StatusBadge>
                      )}
                    </div>
                  </td>
                  <td className="text-sm">
                    {c.groups.length > 0 ? (
                      c.groups.join(', ')
                    ) : (
                      <span className="text-slate-400">—</span>
                    )}
                  </td>
                  <td className="text-sm whitespace-nowrap text-slate-600">
                    {date.format(new Date(c.created_at))}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <Pagination page={customers} />
      </section>
    </AdminLayout>
  );
}
