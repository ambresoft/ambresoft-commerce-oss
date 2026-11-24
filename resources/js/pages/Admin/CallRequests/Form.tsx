import UnifiedCard from '../../../components/admin/AdminCard';
import EditorStatusSelect from '../../../components/admin/EditorStatusSelect';
import { Icon } from '../../../components/admin/list/IconButton';
import EditorHeader from '../../../components/admin/EditorHeader';
import EditorActionButton from '../../../components/admin/EditorActionButton';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';

import ConfirmDeleteDialog from '../../../components/admin/ConfirmDeleteDialog';
import FormField from '../../../components/auth/FormField';
import FormErrors from '../../../components/admin/FormErrors';
import Select from '../../../components/admin/Select';
import IconButton from '../../../components/admin/list/IconButton';
import { SearchField } from '../../../components/admin/list/FilterBar';
import OrderStatusBadge from '../../../components/admin/OrderStatusBadge';
import { useDirtyForm } from '../../../lib/useUnsavedChanges';
import { useDateDisplay } from '../../../lib/useDateDisplay';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';
import type {
  CallAssignee,
  CallStatus,
  CallProduct,
  CallRequestDetail,
} from '../../../types/callRequests';
import type { SharedProps } from '../../../types/page';

export default function CallRequestForm({
  callRequest,
  statuses,
  assignees,
  productOptions,
  ...shell
}: AdminShell & {
  callRequest: CallRequestDetail | null;
  statuses: CallStatus[];
  assignees: CallAssignee[];
  productOptions: CallProduct[];
}) {
  const t = useTranslation();
  const { capabilities } = usePage<SharedProps>().props;
  const manage = !!capabilities['call-requests.manage'];
  const [search, setSearch] = useState('');
  const [confirmDelete, setConfirmDelete] = useState(false);
  const [product, setProduct] = useState('');
  const { date, time } = useDateDisplay();
  const form = useForm({
    version: callRequest?.version ?? 0,
    name: callRequest?.name ?? '',
    phone: callRequest?.phone ?? '',
    convenient_time: callRequest?.convenient_time ?? '',
    notes: callRequest?.notes ?? '',
    status: callRequest?.status ?? 'new',
    responsible_id: callRequest?.responsible_id ?? '',
    order_number: callRequest?.order_number ?? '',
    products: [] as { product_id: string; quantity: string }[],
  });
  useDirtyForm(form);
  const title = callRequest
    ? t('calls.request_title', { id: callRequest.id })
    : t('calls.create');
  const remove = () => {
    if (!callRequest || form.processing) return;
    setConfirmDelete(false);
    form.transform(() => ({ version: callRequest.version }));
    form.delete('/admin/call-requests/' + callRequest.id);
  };
  return (
    <AdminLayout title={title} {...shell}>
      <EditorHeader
        status={
          <>
            <EditorStatusSelect
              disabled={!manage || form.processing}
              aria-label={t('calls.status')}
              value={form.data.status}
              options={statuses.map((value) => ({
                value,
                label: t(`calls.status_${value}`),
              }))}
              onChange={(value) => form.setData('status', value as CallStatus)}
            />{' '}
          </>
        }
        heading={title}
        iconKey="phone"
        backHref="/admin/call-requests"
        backLabel={t('calls.back')}
        description={
          <>
            {callRequest && (
              <p className="mt-1 text-sm text-slate-500">
                {date.format(new Date(callRequest.created_at))}
                {time.format(new Date(callRequest.created_at))}
              </p>
            )}
          </>
        }
        actions={
          <>
            {manage && (
              <EditorActionButton
                disabled={form.processing}
                form="admin-callrequests-form-0"
              >
                {t(form.processing ? 'common.wait' : 'common.save')}
              </EditorActionButton>
            )}
          </>
        }
        menu={[
          callRequest && capabilities['call-requests.delete'] && (
            <button
              type="button"
              disabled={form.processing}
              onClick={() => setConfirmDelete(true)}
              className="text-red-700"
            >
              <Icon name="trash" />
              {t('calls.delete')}
            </button>
          ),
        ]}
      />
      <form
        onSubmit={(e) => {
          e.preventDefault();
          if (!manage) return;
          form.transform((data) => {
            const { products, ...fields } = data;
            return callRequest ? fields : { ...fields, products };
          });
          if (callRequest) form.patch('/admin/call-requests/' + callRequest.id);
          else form.post('/admin/call-requests');
        }}
        id="admin-callrequests-form-0"
      >
        <FormErrors errors={form.errors} />
        <div className="space-y-5">
          <UnifiedCard
            title={t('common.details')}
            iconKey="general"
            bodyClassName="p-5 space-y-5"
          >
            <fieldset
              className="space-y-5"
              disabled={!manage || form.processing}
            >
              <div className="grid gap-5 sm:grid-cols-2">
                <FormField
                  id="call-name"
                  label={t('calls.name')}
                  value={form.data.name}
                  maxLength={200}
                  error={form.errors.name}
                  onChange={(e) => form.setData('name', e.target.value)}
                />
                <FormField
                  id="call-phone"
                  label={t('calls.phone')}
                  requiredIndicator
                  value={form.data.phone}
                  maxLength={40}
                  error={form.errors.phone}
                  onChange={(e) => form.setData('phone', e.target.value)}
                />
              </div>
              <div className="grid gap-5 sm:grid-cols-2">
                <label className="text-sm font-medium">
                  {t('calls.responsible_id')}
                  <Select
                    className="mt-2"
                    aria-label={t('calls.responsible_id')}
                    value={form.data.responsible_id}
                    invalid={!!form.errors.responsible_id}
                    options={[
                      { value: '', label: t('calls.unassigned') },
                      ...assignees.map((u) => ({
                        value: u.id,
                        label:
                          u.name +
                          (u.eligible ? '' : ' · ' + t('calls.unavailable')),
                        disabled: !u.eligible,
                      })),
                    ]}
                    onChange={(value) => form.setData('responsible_id', value)}
                  />
                  {form.errors.responsible_id && (
                    <p className="mt-1 text-sm text-red-700">
                      {form.errors.responsible_id}
                    </p>
                  )}
                </label>
              </div>
              <div className="grid gap-5 sm:grid-cols-2">
                <FormField
                  id="call-time"
                  label={t('calls.convenient_time')}
                  value={form.data.convenient_time}
                  maxLength={200}
                  hint={t('calls.time_hint')}
                  error={form.errors.convenient_time}
                  onChange={(e) =>
                    form.setData('convenient_time', e.target.value)
                  }
                />
                <FormField
                  id="call-order"
                  label={t('calls.order_number')}
                  inputMode="numeric"
                  value={form.data.order_number}
                  error={form.errors.order_number}
                  hint={t('calls.order_hint')}
                  onChange={(e) => form.setData('order_number', e.target.value)}
                />
              </div>
              <div>
                <label
                  htmlFor="call-notes"
                  className="mb-2 block text-sm font-medium"
                >
                  {t('calls.notes')}
                </label>
                {manage ? (
                  <textarea
                    id="call-notes"
                    className="field min-h-48"
                    value={form.data.notes}
                    maxLength={10000}
                    onChange={(e) => form.setData('notes', e.target.value)}
                  />
                ) : (
                  <p className="rounded-md bg-slate-50 p-4 text-sm whitespace-pre-wrap break-words">
                    {form.data.notes || '—'}
                  </p>
                )}
                {form.errors.notes && (
                  <p role="alert" className="mt-1 text-sm text-red-700">
                    {form.errors.notes}
                  </p>
                )}
              </div>
            </fieldset>
            {callRequest?.order_status && (
              <div className="flex items-center gap-3 text-sm">
                <span>{t('calls.linked_order_status')}</span>
                <OrderStatusBadge status={callRequest.order_status} />
                {capabilities['orders.view'] && callRequest.order_id && (
                  <Link
                    className="text-link"
                    href={'/admin/orders/' + callRequest.order_id}
                  >
                    {t('calls.open_order')}
                  </Link>
                )}
              </div>
            )}
            <section
              className="space-y-4 border-t border-slate-200 pt-6"
              aria-labelledby="call-products-title"
            >
              <h2 id="call-products-title" className="text-base font-semibold">
                {t('calls.products')}
              </h2>
              {callRequest ? (
                <>
                  {callRequest.products.length === 0 && (
                    <p className="text-sm text-slate-500">
                      {t('calls.no_products')}
                    </p>
                  )}
                  <ul className="space-y-3">
                    {callRequest.products.map((p, index) => (
                      <li
                        key={index}
                        className="flex flex-wrap justify-between gap-3 text-sm"
                      >
                        <div>
                          {p.available &&
                          p.id &&
                          capabilities['products.update'] ? (
                            <Link
                              className="font-medium hover:underline"
                              href={'/admin/products/' + p.id + '/edit'}
                            >
                              {p.name}
                            </Link>
                          ) : (
                            <span className="font-medium">{p.name}</span>
                          )}
                          {p.sku && (
                            <span className="mt-0.5 block text-xs text-slate-500">
                              {p.sku}
                            </span>
                          )}
                        </div>
                        <span className="text-slate-500">
                          {t('calls.quantity')}: {p.quantity}
                        </span>
                      </li>
                    ))}
                  </ul>
                  <p className="text-xs text-slate-500">
                    {t('calls.products_snapshot')}
                  </p>
                </>
              ) : (
                <>
                  <SearchField
                    embedded
                    id="call-product-search"
                    value={search}
                    onChange={setSearch}
                    placeholder={t('calls.product_search')}
                    onSubmit={() =>
                      router.get(
                        '/admin/call-requests/create',
                        {
                          product_search: search,
                          selected_products: form.data.products.map(
                            (p) => p.product_id,
                          ),
                        },
                        {
                          only: ['productOptions'],
                          preserveState: true,
                          preserveScroll: true,
                        },
                      )
                    }
                  />
                  <div className="flex flex-wrap gap-3">
                    <div className="min-w-0 flex-1">
                      <Select
                        aria-label={t('calls.product_select')}
                        value={product}
                        options={[
                          { value: '', label: t('calls.product_select') },
                          ...productOptions
                            .filter(
                              (p) =>
                                !form.data.products.some(
                                  (row) => row.product_id === p.id,
                                ),
                            )
                            .map((p) => ({
                              value: p.id,
                              label: p.name + (p.sku ? ' · ' + p.sku : ''),
                            })),
                        ]}
                        onChange={setProduct}
                      />
                    </div>
                    <button
                      type="button"
                      className="button-secondary"
                      disabled={!product || form.data.products.length >= 100}
                      onClick={() => {
                        form.setData('products', [
                          ...form.data.products,
                          { product_id: product, quantity: '1' },
                        ]);
                        setProduct('');
                      }}
                    >
                      {t('calls.product_add')}
                    </button>
                  </div>
                  <ul className="space-y-3">
                    {form.data.products.map((p, index) => (
                      <li
                        key={p.product_id}
                        className="flex items-center gap-3"
                      >
                        <span className="min-w-0 flex-1 text-sm">
                          {productOptions.find(
                            (option) => option.id === p.product_id,
                          )?.name ?? '#' + p.product_id}
                        </span>
                        <input
                          className="field w-24"
                          aria-label={t('calls.product_quantity', {
                            row: index + 1,
                          })}
                          inputMode="numeric"
                          value={p.quantity}
                          onChange={(e) =>
                            form.setData(
                              'products',
                              form.data.products.map((row, i) =>
                                i === index
                                  ? { ...row, quantity: e.target.value }
                                  : row,
                              ),
                            )
                          }
                        />
                        <IconButton
                          icon="trash"
                          label={t('calls.product_remove', { row: index + 1 })}
                          onClick={() =>
                            form.setData(
                              'products',
                              form.data.products.filter((_, i) => i !== index),
                            )
                          }
                        />
                      </li>
                    ))}
                  </ul>
                </>
              )}
            </section>
          </UnifiedCard>
        </div>
      </form>
      {confirmDelete && callRequest && capabilities['call-requests.delete'] && (
        <ConfirmDeleteDialog
          title={t('calls.confirm_delete', { count: 1 })}
          facts={[]}
          confirmLabel={t('calls.delete')}
          onCancel={() => setConfirmDelete(false)}
          onConfirm={remove}
        />
      )}
    </AdminLayout>
  );
}
