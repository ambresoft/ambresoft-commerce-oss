import EditorHeader from '../../../components/admin/EditorHeader';
import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import Pagination from '../../../components/admin/Pagination';
import FormErrors from '../../../components/admin/FormErrors';
import ConfirmDeleteDialog from '../../../components/admin/ConfirmDeleteDialog';
import FilterBar, {
  SearchField,
  FilterChip,
  SortControl,
} from '../../../components/admin/list/FilterBar';
import BulkBar from '../../../components/admin/list/BulkBar';
import IconButton from '../../../components/admin/list/IconButton';
import StatusBadge from '../../../components/admin/list/StatusBadge';
import DateInput from '../../../components/admin/DateInput';
import { useDateDisplay } from '../../../lib/useDateDisplay';
import { useTranslation } from '../../../lib/useTranslation';
import { useListQuery } from '../../../lib/useListQuery';
import type { AdminShell, Pagination as Page } from '../../../types/admin';
import type {
  ReviewBulkResult,
  ReviewRow,
  ReviewStatus,
} from '../../../types/reviews';
import type { SharedProps } from '../../../types/page';
import Stars, { statusTones } from './Stars';

const bulkTargets = ['approved', 'rejected', 'spam'] as const;

export default function ReviewsIndex({
  reviews,
  filters,
  statuses,
  bulkResults,
  ...shell
}: AdminShell & {
  reviews: Page<ReviewRow>;
  filters: Record<string, string>;
  statuses: ReviewStatus[];
  bulkResults: ReviewBulkResult[];
}) {
  const t = useTranslation();
  const { capabilities, errors } = usePage<
    SharedProps & { errors: Record<string, string> }
  >().props;
  const moderate = !!capabilities['reviews.moderate'];
  const canDelete = !!capabilities['reviews.delete'];
  const apply = useListQuery('/admin/reviews', filters);
  const [search, setSearch] = useState(filters.search ?? '');
  const [dates, setDates] = useState({
    created_from: filters.created_from ?? '',
    created_to: filters.created_to ?? '',
  });
  const [selected, setSelected] = useState<string[]>([]);
  const [busy, setBusy] = useState(false);
  const [deleting, setDeleting] = useState<ReviewRow | null>(null);
  const onPage = selected.filter((id) => reviews.data.some((r) => r.id === id));
  const { date, time } = useDateDisplay();
  const when = (iso: string) =>
    date.format(new Date(iso)) + ' ' + time.format(new Date(iso));
  const returnTo = () => window.location.pathname + window.location.search;
  const filtered = [
    'search',
    'status',
    'rating',
    'verified',
    'product',
    'created_from',
    'created_to',
  ].some((key) => filters[key]);
  const edit = (row: ReviewRow) =>
    '/admin/reviews/' +
    row.id +
    '/edit?' +
    new URLSearchParams({ return_to: returnTo() }).toString();
  const bulk = (status: (typeof bulkTargets)[number]) =>
    router.post(
      '/admin/reviews/bulk',
      { ids: onPage, status, return_to: returnTo() },
      {
        preserveScroll: true,
        onStart: () => setBusy(true),
        onSuccess: () => setSelected([]),
        onFinish: () => setBusy(false),
      },
    );
  const remove = (row: ReviewRow) =>
    router.delete('/admin/reviews/' + row.id, {
      data: { version: row.version, return_to: returnTo() },
      preserveScroll: true,
      onStart: () => setBusy(true),
      onFinish: () => {
        setBusy(false);
        setDeleting(null);
      },
    });
  const failures = bulkResults.filter((r) => !r.success);
  return (
    <AdminLayout title={t('reviews.title')} {...shell}>
      <EditorHeader
        heading={t('reviews.title')}
        iconKey="reviews"
        description={
          <>
            <p className="mt-1 text-sm text-slate-500">
              {t('reviews.count', { count: reviews.total })}
            </p>
          </>
        }
      />
      <FormErrors errors={errors ?? {}} />
      {bulkResults.length > 0 && (
        <div
          role="status"
          className="mb-4 rounded-lg border border-slate-200 bg-white p-4 text-sm"
        >
          <p>
            {t('reviews.bulk_success')}: {bulkResults.length - failures.length}{' '}
            · {t('reviews.bulk_failed')}: {failures.length}
          </p>
          {failures.map((r) => (
            <p key={r.id} className="mt-2 text-red-700">
              #{r.id}: {r.reason}
            </p>
          ))}
        </div>
      )}
      <section className="list-card" aria-label={t('reviews.title')}>
        <FilterBar
          resetHref="/admin/reviews"
          active={filtered}
          search={
            <SearchField
              id="review-search"
              value={search}
              onChange={setSearch}
              placeholder={t(
                capabilities['customers.view']
                  ? 'reviews.search_with_email'
                  : 'reviews.search',
              )}
              onSubmit={() => apply({ search })}
            />
          }
          sort={
            <SortControl
              value={filters.sort ?? 'created_at'}
              direction={filters.direction === 'asc' ? 'asc' : 'desc'}
              options={['created_at', 'moderated_at', 'rating', 'id'].map(
                (value) => ({ value, label: t(`reviews.sort_${value}`) }),
              )}
              onChange={(sort, direction) => apply({ sort, direction })}
            />
          }
          chips={
            <>
              <FilterChip
                label={t('reviews.status')}
                value={filters.status ?? ''}
                options={[
                  { value: '', label: t('common.all') },
                  ...statuses.map((value) => ({
                    value,
                    label: t(`reviews.status_${value}`),
                  })),
                ]}
                onChange={(status) => apply({ status })}
              />
              <FilterChip
                label={t('reviews.rating')}
                value={filters.rating ?? ''}
                options={[
                  { value: '', label: t('common.all') },
                  ...['5', '4', '3', '2', '1'].map((value) => ({
                    value,
                    label: t('reviews.stars_label', { count: value }),
                  })),
                ]}
                onChange={(rating) => apply({ rating })}
              />
              <FilterChip
                label={t('reviews.verified')}
                value={filters.verified ?? ''}
                options={[
                  { value: '', label: t('common.all') },
                  { value: 'yes', label: t('reviews.verified_yes') },
                  { value: 'no', label: t('reviews.verified_no') },
                ]}
                onChange={(verified) => apply({ verified })}
              />
              {filters.product && (
                <button
                  type="button"
                  className="chip border-emerald-200 bg-primary-tint/50"
                  aria-label={t('reviews.product_filter_clear')}
                  onClick={() => apply({ product: '' })}
                >
                  {t('reviews.product')}:
                  <span className="max-w-40 truncate font-semibold text-slate-900">
                    {reviews.data[0]?.product.id === filters.product
                      ? reviews.data[0].product.name
                      : '#' + filters.product}
                  </span>
                  <span aria-hidden="true">×</span>
                </button>
              )}
            </>
          }
        />
        <div className="flex flex-wrap items-end gap-3 border-t border-slate-100 px-5 py-3">
          {(['created_from', 'created_to'] as const).map((field) => (
            <label key={field} className="text-xs text-slate-500">
              {t('reviews.' + field)}
              <DateInput
                className="field mt-1"
                value={dates[field]}
                onChange={(value) => setDates({ ...dates, [field]: value })}
                aria-label={t('reviews.' + field)}
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
        {moderate && onPage.length > 0 && (
          <BulkBar count={onPage.length} onClear={() => setSelected([])}>
            {bulkTargets.map((status) => (
              <button
                key={status}
                type="button"
                className={
                  status === 'approved' ? 'button-primary' : 'button-secondary'
                }
                disabled={busy}
                onClick={() => bulk(status)}
              >
                {t(`reviews.action_${status}`)}
              </button>
            ))}
          </BulkBar>
        )}
        <div className="relative max-w-full overflow-x-auto border-t border-slate-200">
          <table className="data-table">
            <thead>
              <tr>
                {moderate && (
                  <th className="w-12">
                    <input
                      type="checkbox"
                      className="size-4"
                      aria-label={t('reviews.select_page')}
                      checked={
                        reviews.data.length > 0 &&
                        onPage.length === reviews.data.length
                      }
                      onChange={(e) =>
                        setSelected(
                          e.target.checked ? reviews.data.map((r) => r.id) : [],
                        )
                      }
                    />
                  </th>
                )}
                {[
                  'product',
                  'customer',
                  'rating',
                  'review',
                  'verified',
                  'status',
                  'submitted',
                  'moderated',
                ].map((field) => (
                  <th key={field}>{t('reviews.' + field)}</th>
                ))}
                <th>
                  <span className="sr-only">{t('common.actions')}</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {reviews.data.map((row) => (
                <tr key={row.id}>
                  {moderate && (
                    <td>
                      <input
                        type="checkbox"
                        className="size-4"
                        aria-label={t('reviews.select_id', { id: row.id })}
                        checked={selected.includes(row.id)}
                        onChange={(e) =>
                          setSelected(
                            e.target.checked
                              ? [...selected, row.id]
                              : selected.filter((id) => id !== row.id),
                          )
                        }
                      />
                    </td>
                  )}
                  <td className="text-sm">
                    <Link
                      href={edit(row)}
                      className="block max-w-48 truncate font-medium hover:underline"
                    >
                      {row.product.name}
                    </Link>
                    {row.product.trashed ? (
                      <span className="mt-0.5 block text-xs text-red-700">
                        {t('reviews.product_trashed')}
                      </span>
                    ) : (
                      row.product.sku && (
                        <span className="mt-0.5 block text-xs text-slate-500">
                          {row.product.sku}
                        </span>
                      )
                    )}
                  </td>
                  <td className="text-sm">
                    <span className="block max-w-40 truncate">
                      {row.customer.name}
                    </span>
                    {row.customer.email && (
                      <span className="mt-0.5 block max-w-40 truncate text-xs text-slate-500">
                        {row.customer.email}
                      </span>
                    )}
                  </td>
                  <td>
                    <Stars rating={row.rating} />
                  </td>
                  <td className="text-sm">
                    <Link
                      href={edit(row)}
                      className="block w-64 hover:underline"
                      aria-label={t('reviews.open_id', { id: row.id })}
                    >
                      {row.title && (
                        <span className="block truncate font-medium">
                          {row.title}
                        </span>
                      )}
                      <span className="block truncate text-slate-500">
                        {row.excerpt + (row.truncated ? '…' : '')}
                      </span>
                    </Link>
                  </td>
                  <td className="text-sm">
                    {row.verified_purchase ? (
                      <StatusBadge tone="success">
                        {t('reviews.verified_badge')}
                      </StatusBadge>
                    ) : (
                      <span className="text-slate-400">
                        {t('reviews.verified_no')}
                      </span>
                    )}
                  </td>
                  <td>
                    <StatusBadge tone={statusTones[row.status]}>
                      {t(`reviews.status_${row.status}`)}
                    </StatusBadge>
                  </td>
                  <td className="text-xs whitespace-nowrap text-slate-500">
                    {when(row.created_at)}
                  </td>
                  <td className="text-xs whitespace-nowrap text-slate-500">
                    {row.moderated_at ? when(row.moderated_at) : '—'}
                  </td>
                  <td>
                    <div className="flex justify-end gap-1">
                      {canDelete && (
                        <IconButton
                          icon="trash"
                          danger
                          label={t('reviews.delete_id', { id: row.id })}
                          disabled={busy}
                          onClick={() => setDeleting(row)}
                        />
                      )}
                      <IconButton
                        icon="edit"
                        href={edit(row)}
                        label={t('reviews.open_id', { id: row.id })}
                      />
                    </div>
                  </td>
                </tr>
              ))}
              {reviews.data.length === 0 && (
                <tr>
                  <td
                    colSpan={moderate ? 10 : 9}
                    className="py-10 text-center text-slate-500"
                  >
                    {t(filtered ? 'reviews.empty_filtered' : 'reviews.empty')}
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
        <Pagination
          page={reviews}
          perPage={{
            value: filters.per_page ?? '25',
            options: ['25', '50', '100'],
            onChange: (per_page) => apply({ per_page }),
          }}
        />
      </section>
      {deleting && (
        <ConfirmDeleteDialog
          title={t('reviews.delete_title', { id: deleting.id })}
          facts={[
            t('reviews.delete_fact_rating'),
            deleting.status === 'approved'
              ? t('reviews.delete_fact_public')
              : t('reviews.delete_fact_hidden'),
          ]}
          confirmLabel={t('reviews.delete')}
          onConfirm={() => remove(deleting)}
          onCancel={() => setDeleting(null)}
        />
      )}
    </AdminLayout>
  );
}
