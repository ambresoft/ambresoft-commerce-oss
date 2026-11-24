import EditorHeader from '../../../components/admin/EditorHeader';
import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import Pagination from '../../../components/admin/Pagination';
import FilterBar, {
  FilterChip,
  SearchField,
  SortControl,
} from '../../../components/admin/list/FilterBar';
import { Icon } from '../../../components/admin/list/IconButton';
import DateInput from '../../../components/admin/DateInput';
import Select from '../../../components/admin/Select';
import BulkBar from '../../../components/admin/list/BulkBar';
import ConfirmDeleteDialog from '../../../components/admin/ConfirmDeleteDialog';
import FormErrors from '../../../components/admin/FormErrors';
import OrderStatusBadge from '../../../components/admin/OrderStatusBadge';
import { useDateDisplay } from '../../../lib/useDateDisplay';
import { useListQuery } from '../../../lib/useListQuery';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell, Pagination as Page } from '../../../types/admin';
import type { OrderRow, OrderStatusOption } from '../../../types/orders';
import type { SharedProps } from '../../../types/page';

type Props = AdminShell & {
  orders: Page<OrderRow>;
  filters: Record<string, string>;
  statuses: OrderStatusOption[];
  canSeeIp: boolean;
};

const advanced = [
  'created_from',
  'created_to',
  'updated_from',
  'updated_to',
  'total_min',
  'total_max',
  'ip',
] as const;

export default function OrdersIndex(props: Props) {
  return (
    <OrderList
      key={JSON.stringify([
        props.filters,
        props.orders.current_page,
        props.orders.data.map((o) => [o.id, o.version]),
      ])}
      {...props}
    />
  );
}

function OrderList({ orders, filters, statuses, canSeeIp, ...shell }: Props) {
  const t = useTranslation();
  const { capabilities, errors: serverErrors = {} } = usePage<
    SharedProps & { errors?: Record<string, string> }
  >().props;
  const apply = useListQuery('/admin/orders', filters);
  const [search, setSearch] = useState(filters.search ?? '');
  const [more, setMore] = useState(() =>
    Object.fromEntries(advanced.map((key) => [key, filters[key] ?? ''])),
  );
  const [open, setOpen] = useState(advanced.some((key) => filters[key]));
  const filtered = ['search', 'status', 'invoice', ...advanced].some(
    (k) => filters[k],
  );
  const { date, time } = useDateDisplay();
  const when = (iso: string) =>
    date.format(new Date(iso)) + ' ' + time.format(new Date(iso));
  const [selected, setSelected] = useState<string[]>([]);
  const [bulkStatus, setBulkStatus] = useState('');
  const [pending, setPending] = useState<{
    rows: OrderRow[];
    statusId: string;
    bulk: boolean;
  } | null>(null);
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>(serverErrors);
  const rows = orders.data.filter((o) => selected.includes(o.id));
  const canStatus = capabilities['orders.status'];
  const changeStatus = (order: OrderRow, statusId: string) => {
    if (
      statusId !== order.status?.id &&
      statuses.some((s) => s.id === statusId && s.active)
    )
      setPending({ rows: [order], statusId, bulk: false });
  };
  const submitStatus = () => {
    if (!pending || busy) return;
    const action = pending;
    setPending(null);
    setBusy(true);
    setErrors({});
    router.patch(
      action.bulk
        ? '/admin/orders/bulk-status'
        : '/admin/orders/' + action.rows[0].id + '/status',
      {
        status_id: action.statusId,
        ...(action.bulk
          ? {
              orders: action.rows.map((o) => ({
                id: o.id,
                version: o.version,
              })),
            }
          : { version: action.rows[0].version }),
        return_to: window.location.pathname + window.location.search,
      },
      {
        preserveScroll: true,
        onSuccess: () => setSelected([]),
        onError: setErrors,
        onFinish: () => setBusy(false),
      },
    );
  };
  return (
    <AdminLayout title={t('orders.title')} {...shell}>
      <EditorHeader
        heading={t('orders.title')}
        iconKey="orders"
        description={
          <>
            <p className="mt-1 text-sm text-slate-500">
              {t('orders.count', { count: orders.total })}
            </p>
          </>
        }
        actions={
          <>
            {capabilities['orders.create'] && (
              <Link
                className="button-primary gap-2"
                href="/admin/orders/create"
              >
                <Icon name="plus" />
                {t('orders.create')}
              </Link>
            )}
          </>
        }
      />
      <FormErrors errors={errors} />
      {pending && (
        <ConfirmDeleteDialog
          icon="↻"
          title={t('orders.bulk_status_confirm', {
            count: pending.rows.length,
            status: statuses.find((s) => s.id === pending.statusId)?.name ?? '',
          })}
          subtitle={t('orders.bulk_status_help')}
          confirmLabel={t('orders.change_status')}
          facts={[pending.rows.map((o) => '#' + o.number).join(', ')]}
          onConfirm={submitStatus}
          onCancel={() => setPending(null)}
        />
      )}
      <section className="list-card" aria-label={t('orders.title')}>
        <FilterBar
          resetHref="/admin/orders"
          active={filtered}
          search={
            <SearchField
              id="order-search"
              value={search}
              placeholder={t('orders.search_placeholder')}
              onChange={setSearch}
              onSubmit={() => apply({ search })}
            />
          }
          sort={
            <SortControl
              value={filters.sort ?? 'created_at'}
              direction={filters.direction === 'asc' ? 'asc' : 'desc'}
              options={[
                { value: 'created_at', label: t('orders.sort_created') },
                { value: 'number', label: t('orders.sort_number') },
                { value: 'customer', label: t('orders.sort_customer') },
                { value: 'status', label: t('orders.sort_status') },
                { value: 'total', label: t('orders.sort_total') },
                { value: 'updated_at', label: t('orders.sort_updated') },
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
                  ...statuses.map((s) => ({
                    value: s.id,
                    color: s.color,
                    label: s.active
                      ? s.name
                      : s.name + ' (' + t('orders.status_inactive') + ')',
                  })),
                ]}
                onChange={(value) => apply({ status: value })}
              />
              <FilterChip
                label={t('orders.filter_invoice')}
                value={filters.invoice ?? ''}
                options={[
                  { value: '', label: t('common.all') },
                  { value: '1', label: t('orders.filter_invoice_requested') },
                  { value: '0', label: t('orders.filter_invoice_none') },
                ]}
                onChange={(value) => apply({ invoice: value })}
              />
              <button
                type="button"
                className="button-secondary text-sm"
                aria-expanded={open}
                aria-controls="order-advanced-filters"
                onClick={() => setOpen(!open)}
              >
                {t('orders.more_filters')}
              </button>
            </>
          }
        />
        {open && (
          <form
            id="order-advanced-filters"
            className="grid gap-4 border-b border-slate-200 p-4 sm:grid-cols-2 lg:grid-cols-4"
            onSubmit={(event) => {
              event.preventDefault();
              apply(more);
            }}
          >
            {(
              [
                ['created_from', 'orders.created_from'],
                ['created_to', 'orders.created_to'],
                ['updated_from', 'orders.updated_from'],
                ['updated_to', 'orders.updated_to'],
              ] as const
            ).map(([key, label]) => (
              <label key={key} className="block text-sm font-medium">
                {t(label)}
                <DateInput
                  className="mt-1"
                  aria-label={t(label)}
                  value={more[key]}
                  onChange={(value) => setMore({ ...more, [key]: value })}
                />
              </label>
            ))}
            {(
              [
                ['total_min', 'orders.total_min'],
                ['total_max', 'orders.total_max'],
              ] as const
            ).map(([key, label]) => (
              <label key={key} className="block text-sm font-medium">
                {t(label)}
                <input
                  className="field mt-1"
                  inputMode="decimal"
                  value={more[key]}
                  onChange={(e) => setMore({ ...more, [key]: e.target.value })}
                />
              </label>
            ))}
            {canSeeIp && (
              <label className="block text-sm font-medium">
                {t('orders.ip_address')}
                <input
                  className="field mt-1"
                  value={more.ip}
                  onChange={(e) => setMore({ ...more, ip: e.target.value })}
                />
              </label>
            )}
            <div className="flex items-end">
              <button className="button-primary">{t('orders.apply')}</button>
            </div>
          </form>
        )}
        {canStatus && rows.length > 0 && (
          <BulkBar count={rows.length} onClear={() => setSelected([])}>
            <Select
              aria-label={t('orders.new_status')}
              value={bulkStatus}
              disabled={busy}
              onChange={setBulkStatus}
              options={[
                { value: '', label: t('orders.new_status') },
                ...statuses
                  .filter((s) => s.active)
                  .map((s) => ({ value: s.id, label: s.name, color: s.color })),
              ]}
            />
            <button
              type="button"
              className="button-primary"
              disabled={busy || !bulkStatus}
              onClick={() =>
                setPending({ rows, statusId: bulkStatus, bulk: true })
              }
            >
              {t('orders.bulk_status')}
            </button>
          </BulkBar>
        )}
        <div className="overflow-x-auto">
          <table className="data-table">
            <thead>
              <tr>
                {canStatus && (
                  <th className="w-10">
                    <input
                      type="checkbox"
                      aria-label={t('orders.select_page')}
                      disabled={busy || orders.data.length === 0}
                      checked={
                        orders.data.length > 0 &&
                        rows.length === orders.data.length
                      }
                      onChange={(e) =>
                        setSelected(
                          e.target.checked ? orders.data.map((o) => o.id) : [],
                        )
                      }
                    />
                  </th>
                )}
                <th>{t('orders.order')}</th>
                <th>{t('orders.created')}</th>
                <th>{t('orders.customer')}</th>
                <th>{t('common.status')}</th>
                <th className="text-right">{t('orders.items')}</th>
                <th className="text-right">{t('orders.total')}</th>
                <th>{t('orders.updated')}</th>
              </tr>
            </thead>
            <tbody>
              {orders.data.length === 0 && (
                <tr>
                  <td
                    colSpan={canStatus ? 8 : 7}
                    className="py-10 text-center text-slate-500"
                  >
                    {t(filtered ? 'orders.no_matches' : 'orders.empty')}
                  </td>
                </tr>
              )}
              {orders.data.map((o) => (
                <tr key={o.id}>
                  {canStatus && (
                    <td>
                      <input
                        type="checkbox"
                        aria-label={t('orders.select_order', {
                          number: o.number,
                        })}
                        disabled={busy}
                        checked={selected.includes(o.id)}
                        onChange={(e) =>
                          setSelected(
                            e.target.checked
                              ? [...selected, o.id]
                              : selected.filter((id) => id !== o.id),
                          )
                        }
                      />
                    </td>
                  )}
                  <td>
                    <Link
                      className="font-semibold tabular-nums hover:underline"
                      href={'/admin/orders/' + o.id}
                    >
                      #{o.number}
                    </Link>
                    {o.ip_address && (
                      <span className="block font-mono text-xs text-slate-400">
                        {o.ip_address}
                      </span>
                    )}
                  </td>
                  <td className="text-sm whitespace-nowrap text-slate-600">
                    {when(o.created_at)}
                  </td>
                  <td className="text-sm">
                    <span className="font-medium">{o.customer}</span>
                    <span className="block text-slate-500">{o.email}</span>
                    {o.phone && (
                      <span className="block text-slate-500 tabular-nums">
                        {o.phone}
                      </span>
                    )}
                  </td>
                  <td>
                    {capabilities['orders.status'] ? (
                      <Select
                        className="w-auto min-w-40"
                        aria-label={t('orders.change_status_of', {
                          number: o.number,
                        })}
                        disabled={busy}
                        value={o.status?.id ?? ''}
                        onChange={(value) => changeStatus(o, value)}
                        options={statuses
                          .filter((s) => s.active || s.id === o.status?.id)
                          .map((s) => ({
                            value: s.id,
                            label: s.name,
                            color: s.color,
                            disabled: !s.active,
                          }))}
                      />
                    ) : (
                      <OrderStatusBadge status={o.status} />
                    )}
                  </td>
                  <td className="text-right text-sm tabular-nums">{o.items}</td>
                  <td className="text-right text-sm font-medium whitespace-nowrap tabular-nums">
                    {o.total}
                  </td>
                  <td className="text-sm whitespace-nowrap text-slate-600">
                    {when(o.updated_at)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <Pagination page={orders} />
      </section>
    </AdminLayout>
  );
}
