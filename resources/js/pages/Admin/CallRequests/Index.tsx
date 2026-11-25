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
import IconButton, { Icon } from '../../../components/admin/list/IconButton';
import Select from '../../../components/admin/Select';
import StatusBadge, {
  type Tone,
} from '../../../components/admin/list/StatusBadge';
import OrderStatusBadge from '../../../components/admin/OrderStatusBadge';
import DateInput from '../../../components/admin/DateInput';
import { useDateDisplay } from '../../../lib/useDateDisplay';
import { useTranslation } from '../../../lib/useTranslation';
import { useListQuery } from '../../../lib/useListQuery';
import type { AdminShell, Pagination as Page } from '../../../types/admin';
import type {
  CallRequestRow,
  CallAssignee,
  CallStatus,
} from '../../../types/callRequests';
import type { SharedProps } from '../../../types/page';

const tones: Record<CallStatus, Tone> = {
  new: 'warning',
  in_progress: 'neutral',
  completed: 'success',
  no_answer: 'danger',
};
export default function CallRequestsIndex({
  requests,
  filters,
  statuses,
  assignees,
  ...shell
}: AdminShell & {
  requests: Page<CallRequestRow>;
  filters: Record<string, string>;
  statuses: CallStatus[];
  assignees: CallAssignee[];
}) {
  const t = useTranslation();
  const { capabilities, errors } = usePage<
    SharedProps & { errors: Record<string, string> }
  >().props;
  const apply = useListQuery('/admin/call-requests', filters);
  const [search, setSearch] = useState(filters.search ?? '');
  const [dates, setDates] = useState({
    created_from: filters.created_from ?? '',
    created_to: filters.created_to ?? '',
  });
  const [selected, setSelected] = useState<Record<string, boolean>>({});
  const [busy, setBusy] = useState(false);
  const rows = requests.data.filter((r) => selected[r.id]);
  const { date, time } = useDateDisplay();
  const when = (iso: string) =>
    date.format(new Date(iso)) + ' ' + time.format(new Date(iso));
  const statusOptions = statuses.map((value) => ({
    value,
    label: t(`calls.status_${value}`),
  }));
  const staffOptions = [
    { value: '', label: t('calls.unassigned') },
    ...assignees.map((u) => ({
      value: u.id,
      label: u.name + (u.eligible ? '' : ' · ' + t('calls.unavailable')),
      disabled: !u.eligible,
    })),
  ];
  const returnTo = () => window.location.pathname + window.location.search;
  const update = (row: CallRequestRow, patch: Record<string, string>) =>
    router.patch(
      '/admin/call-requests/' + row.id,
      { version: row.version, ...patch, return_to: returnTo() },
      {
        preserveScroll: true,
        onStart: () => setBusy(true),
        onFinish: () => setBusy(false),
      },
    );
  const remove = (chosen: CallRequestRow[]) => {
    if (
      !chosen.length ||
      !window.confirm(t('calls.confirm_delete', { count: chosen.length }))
    )
      return;
    router.delete(
      chosen.length === 1
        ? '/admin/call-requests/' + chosen[0]!.id
        : '/admin/call-requests/bulk',
      {
        data: {
          ...(chosen.length === 1
            ? { version: chosen[0]!.version }
            : { rows: chosen.map((r) => ({ id: r.id, version: r.version })) }),
          return_to: returnTo(),
        },
        preserveScroll: true,
        onStart: () => setBusy(true),
        onSuccess: () => setSelected({}),
        onFinish: () => setBusy(false),
      },
    );
  };
  return (
    <AdminLayout title={t('calls.title')} {...shell}>
      <EditorHeader
        heading={t('calls.title')}
        iconKey="phone"
        description={
          <>
            <p className="mt-1 text-sm text-slate-500">
              {t('calls.count', { count: requests.total })}
            </p>
          </>
        }
        actions={
          <>
            {capabilities['call-requests.manage'] && (
              <Link
                href="/admin/call-requests/create"
                className="button-primary gap-2"
              >
                <Icon name="plus" />
                {t('calls.create')}
              </Link>
            )}
          </>
        }
      />
      <FormErrors errors={errors ?? {}} />
      <section className="list-card" aria-label={t('calls.title')}>
        <FilterBar
          resetHref="/admin/call-requests"
          active={[
            'search',
            'status',
            'responsible',
            'created_from',
            'created_to',
          ].some((key) => filters[key])}
          search={
            <SearchField
              id="call-search"
              value={search}
              onChange={setSearch}
              placeholder={t('calls.search')}
              onSubmit={() => apply({ search })}
            />
          }
          sort={
            <SortControl
              value={filters.sort ?? 'created_at'}
              direction={filters.direction === 'asc' ? 'asc' : 'desc'}
              options={['created_at', 'updated_at', 'id', 'status'].map(
                (value) => ({ value, label: t(`calls.sort_${value}`) }),
              )}
              onChange={(sort, direction) => apply({ sort, direction })}
            />
          }
          chips={
            <>
              <FilterChip
                label={t('calls.status')}
                value={filters.status ?? ''}
                options={[
                  { value: '', label: t('common.all') },
                  ...statusOptions,
                ]}
                onChange={(status) => apply({ status })}
              />
              <FilterChip
                label={t('calls.responsible_id')}
                value={filters.responsible ?? ''}
                options={[
                  { value: '', label: t('common.all') },
                  { value: 'unassigned', label: t('calls.unassigned') },
                  ...assignees.map((u) => ({ value: u.id, label: u.name })),
                ]}
                onChange={(responsible) => apply({ responsible })}
              />
            </>
          }
        />
        <div className="flex flex-wrap items-end gap-3 border-b border-slate-100 px-5 py-3">
          {(['created_from', 'created_to'] as const).map((field) => (
            <label key={field} className="text-xs text-slate-500">
              {t('calls.' + field)}
              <DateInput
                className="field mt-1"
                value={dates[field]}
                onChange={(value) => setDates({ ...dates, [field]: value })}
                aria-label={t('calls.' + field)}
              />
            </label>
          ))}
          <button
            type="button"
            className="button-secondary"
            onClick={() => apply(dates)}
          >
            {t('common.search')}
          </button>
        </div>
        {capabilities['call-requests.delete'] && rows.length > 0 && (
          <div className="flex items-center gap-3 border-b border-slate-100 px-5 py-3">
            <span className="text-sm">
              {t('common.selected', { count: rows.length })}
            </span>
            <button
              type="button"
              className="button-danger"
              disabled={busy}
              onClick={() => remove(rows)}
            >
              {t('calls.delete_selected')}
            </button>
          </div>
        )}
        <div className="relative max-w-full overflow-x-auto">
          <table className="data-table">
            <thead>
              <tr>
                {capabilities['call-requests.delete'] && (
                  <th className="w-12">
                    <input
                      type="checkbox"
                      aria-label={t('calls.select_page')}
                      checked={
                        requests.data.length > 0 &&
                        rows.length === requests.data.length
                      }
                      onChange={(e) =>
                        setSelected(
                          Object.fromEntries(
                            requests.data.map((r) => [r.id, e.target.checked]),
                          ),
                        )
                      }
                    />
                  </th>
                )}
                {[
                  'id',
                  'created_at',
                  'contact',
                  'order_number',
                  'responsible_id',
                  'status',
                  'notes',
                ].map((field) => (
                  <th key={field}>{t('calls.' + field)}</th>
                ))}
                <th>
                  <span className="sr-only">{t('common.actions')}</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {requests.data.map((row) => (
                <tr key={row.id}>
                  {capabilities['call-requests.delete'] && (
                    <td>
                      <input
                        type="checkbox"
                        aria-label={t('calls.select_id', { id: row.id })}
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
                      className="font-medium hover:underline"
                      href={'/admin/call-requests/' + row.id + '/edit'}
                    >
                      #{row.id}
                    </Link>
                  </td>
                  <td className="text-xs whitespace-nowrap text-slate-500">
                    {when(row.created_at)}
                  </td>
                  <td className="text-sm">
                    <Link
                      className="block font-medium hover:underline"
                      href={'/admin/call-requests/' + row.id + '/edit'}
                    >
                      {row.name || t('calls.unnamed')}
                    </Link>
                    <a
                      className="text-link mt-0.5 block whitespace-nowrap"
                      href={'tel:' + row.phone.replace(/[^+0-9]/g, '')}
                    >
                      {row.phone}
                    </a>
                    {row.convenient_time && (
                      <span className="mt-1 block max-w-48 truncate text-xs text-slate-500">
                        {row.convenient_time}
                      </span>
                    )}
                  </td>
                  <td className="text-sm">
                    {row.order_number ? (
                      <>
                        {row.order_id && capabilities['orders.view'] ? (
                          <Link
                            className="font-medium hover:underline"
                            href={'/admin/orders/' + row.order_id}
                          >
                            #{row.order_number}
                          </Link>
                        ) : (
                          <span>#{row.order_number}</span>
                        )}
                        {row.order_status && (
                          <span className="mt-1 block">
                            <OrderStatusBadge status={row.order_status} />
                          </span>
                        )}
                      </>
                    ) : (
                      <span className="text-slate-400">—</span>
                    )}
                    {row.product_count > 0 && (
                      <span className="mt-1 block text-xs text-slate-500">
                        {t('calls.product_count', { count: row.product_count })}
                      </span>
                    )}
                  </td>
                  <td>
                    {capabilities['call-requests.manage'] ? (
                      <Select
                        className="min-w-40"
                        aria-label={t('calls.assign_id', { id: row.id })}
                        value={row.responsible_id}
                        options={staffOptions}
                        disabled={busy}
                        onChange={(responsible_id) =>
                          update(row, { responsible_id })
                        }
                      />
                    ) : (
                      <span className="text-sm">
                        {row.responsible_name || t('calls.unassigned')}
                      </span>
                    )}
                  </td>
                  <td>
                    {capabilities['call-requests.manage'] ? (
                      <Select
                        className="min-w-36"
                        aria-label={t('calls.status_id', { id: row.id })}
                        value={row.status}
                        options={statusOptions}
                        disabled={busy}
                        onChange={(status) => update(row, { status })}
                      />
                    ) : (
                      <StatusBadge tone={tones[row.status]}>
                        {t(`calls.status_${row.status}`)}
                      </StatusBadge>
                    )}
                  </td>
                  <td>
                    <Link
                      className="block w-48 truncate text-sm text-slate-500 hover:text-slate-900"
                      aria-label={t('calls.open_id', { id: row.id })}
                      href={'/admin/call-requests/' + row.id + '/edit'}
                    >
                      {row.notes_preview
                        ? row.notes_preview + (row.notes_truncated ? '…' : '')
                        : t('calls.no_notes')}
                    </Link>
                  </td>
                  <td>
                    <div className="flex gap-1">
                      <Link
                        href={'/admin/call-requests/' + row.id + '/edit'}
                        className="icon-button"
                        aria-label={t('calls.open_id', { id: row.id })}
                      >
                        <Icon name="edit" />
                      </Link>
                      {capabilities['call-requests.delete'] && (
                        <IconButton
                          icon="trash"
                          label={t('calls.delete_id', { id: row.id })}
                          disabled={busy}
                          onClick={() => remove([row])}
                        />
                      )}
                    </div>
                  </td>
                </tr>
              ))}
              {requests.data.length === 0 && (
                <tr>
                  <td colSpan={9} className="py-10 text-center text-slate-500">
                    {t('calls.empty')}
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
        <Pagination
          page={requests}
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
