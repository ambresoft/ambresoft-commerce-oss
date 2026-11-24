import UnifiedCard from '../../../components/admin/AdminCard';
import EditorStatusSelect from '../../../components/admin/EditorStatusSelect';
import EditorHeader from '../../../components/admin/EditorHeader';
import EditorActionButton from '../../../components/admin/EditorActionButton';
import AdminTabs, { AdminTab } from '../../../components/admin/AdminTabs';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';

import FormField from '../../../components/auth/FormField';
import FormErrors from '../../../components/admin/FormErrors';
import Select from '../../../components/admin/Select';
import DateInput from '../../../components/admin/DateInput';
import Pagination from '../../../components/admin/Pagination';
import { SearchField } from '../../../components/admin/list/FilterBar';
import StatusBadge from '../../../components/admin/list/StatusBadge';
import { useDirtyForm } from '../../../lib/useUnsavedChanges';
import { useUrlTab } from '../../../lib/useUrlTab';
import { useDateDisplay } from '../../../lib/useDateDisplay';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell, Pagination as Page } from '../../../types/admin';
import type { SharedProps } from '../../../types/page';
import type {
  CouponData,
  CouponDetail,
  CouponOption,
  CouponUse,
} from '../../../types/coupons';

const tabs = ['general', 'history'] as const;
export default function CouponForm({
  coupon,
  currency,
  productOptions,
  categoryOptions,
  history,
  ...shell
}: AdminShell & {
  coupon: CouponDetail | null;
  currency: { code: string; decimals: number };
  productOptions: CouponOption[];
  categoryOptions: CouponOption[];
  history: Page<CouponUse> | null;
}) {
  const t = useTranslation();
  const { capabilities } = usePage<SharedProps>().props;
  const manage = !!capabilities['coupons.manage'] && !coupon?.deleted_at;
  const [tab, setTab] = useUrlTab(coupon ? tabs : (['general'] as const));
  const { date, time } = useDateDisplay();
  const [search, setSearch] = useState({ product: '', category: '' });
  const [choice, setChoice] = useState({ product: '', category: '' });
  const [chosenNames, setChosenNames] = useState<Record<string, string>>({});
  const form = useForm<CouponData>({
    version: coupon?.version ?? 0,
    name: coupon?.name ?? '',
    code: coupon?.code ?? '',
    type: coupon?.type ?? 'percentage',
    amount: coupon?.amount ?? '',
    minimum: coupon?.minimum ?? '',
    login_required: coupon?.login_required ?? false,
    free_shipping: coupon?.free_shipping ?? false,
    all_products: coupon?.all_products ?? true,
    starts_on: coupon?.starts_on ?? '',
    ends_on: coupon?.ends_on ?? '',
    total_limit: coupon?.total_limit ?? '',
    customer_limit: coupon?.customer_limit ?? '',
    status: coupon?.status ?? 'active',
    product_ids: coupon?.product_ids ?? [],
    category_ids: coupon?.category_ids ?? [],
  });
  useDirtyForm(form);
  const sync = (page: { props: unknown }) => {
    const saved = (page.props as { coupon: CouponDetail }).coupon;
    const data: CouponData = {
      ...form.data,
      ...Object.fromEntries(
        Object.keys(form.data).map((key) => [
          key,
          saved[key as keyof CouponData],
        ]),
      ),
    };
    form.setDefaults(data);
    form.setData(data);
  };
  const title = coupon ? coupon.name : t('coupons.create');
  const path = coupon
    ? `/admin/coupons/${coupon.id}/edit`
    : '/admin/coupons/create';
  const textField = (
    field:
      'name' | 'code' | 'amount' | 'minimum' | 'total_limit' | 'customer_limit',
    help?: string,
  ) => (
    <FormField
      id={field}
      label={
        t(`coupons.${field}`) +
        (field === 'amount'
          ? ` (${form.data.type === 'percentage' ? '%' : currency.code})`
          : field === 'minimum'
            ? ` (${currency.code})`
            : '')
      }
      value={form.data[field]}
      onChange={(e) => form.setData(field, e.target.value)}
      error={form.errors[field]}
      tooltip={help ? t(`coupons.${help}`) : undefined}
      requiredIndicator={['name', 'code', 'amount'].includes(field)}
      inputMode={
        ['amount', 'minimum', 'total_limit', 'customer_limit'].includes(field)
          ? 'decimal'
          : undefined
      }
      maxLength={field === 'code' ? 20 : field === 'name' ? 128 : 22}
    />
  );
  return (
    <AdminLayout title={title} {...shell}>
      <EditorHeader
        heading={title}
        iconKey="discounts"
        backHref="/admin/coupons"
        backLabel={t('coupons.back')}
        status={
          manage && (
            <div className="mr-auto flex items-center gap-3">
              <EditorStatusSelect
                disabled={form.processing}
                id="coupon-status"
                aria-label={t('coupons.status')}
                value={form.data.status}
                onChange={(v) => form.setData('status', v)}
                options={['active', 'disabled'].map((value) => ({
                  value,
                  label: t(`coupons.${value}`),
                }))}
              />
            </div>
          )
        }
        actions={
          <>
            {manage && (
              <>
                <EditorActionButton
                  disabled={form.processing}
                  form="admin-coupons-form-0"
                >
                  {t(form.processing ? 'common.wait' : 'common.save')}
                </EditorActionButton>
              </>
            )}
          </>
        }
        menu={[
          coupon && !coupon.deleted_at && capabilities['coupons.delete'] && (
            <button
              type="button"
              className="button-secondary text-red-700"
              onClick={() => {
                if (window.confirm(t('coupons.confirm_delete', { count: 1 })))
                  router.delete(`/admin/coupons/${coupon.id}`, {
                    data: { version: coupon.version },
                  });
              }}
            >
              {t('common.delete')}
            </button>
          ),
        ]}
      />
      <form
        onSubmit={(e) => {
          e.preventDefault();
          if (!manage) return;
          if (coupon)
            form.put(`/admin/coupons/${coupon.id}`, { onSuccess: sync });
          else form.post('/admin/coupons', { onSuccess: sync });
        }}
        id="admin-coupons-form-0"
      >
        <AdminTabs className="mb-6">
          {tabs
            .filter((v) => v !== 'history' || coupon)
            .map((v) => (
              <AdminTab
                key={v}
                type="button"
                role="tab"
                aria-selected={tab === v}
                aria-controls={`coupon-${v}`}
                onClick={() => setTab(v)}
                iconKey={v}
              >
                {t(`coupons.${v}`)}
              </AdminTab>
            ))}
        </AdminTabs>
        <FormErrors errors={form.errors} />
        {coupon?.deleted_at && (
          <p className="mb-5 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            {t('coupons.archived')}
          </p>
        )}
        <div id="coupon-general" role="tabpanel" hidden={tab !== 'general'}>
          <UnifiedCard
            title={t('common.details')}
            iconKey="details"
            bodyClassName="p-5 "
          >
            <fieldset disabled={!manage} className="min-w-0">
              <section className="border-b border-slate-200 pb-8">
                <h2 className="mb-6 text-lg font-semibold">
                  {t('coupons.details')}
                </h2>
                <div className="grid gap-6 md:grid-cols-2">
                  {textField('name', 'name_help')}
                  {textField('code', 'code_help')}
                  <div className="space-y-2">
                    <span className="block text-sm font-medium">
                      {t('coupons.type')}
                    </span>
                    <Select
                      aria-label={t('coupons.type')}
                      value={form.data.type}
                      onChange={(v) => form.setData('type', v)}
                      options={['percentage', 'fixed'].map((value) => ({
                        value,
                        label: t(`coupons.${value}`),
                      }))}
                    />
                  </div>
                  {textField('amount', 'amount_help')}
                </div>
              </section>
              <section className="border-b border-slate-200 py-8">
                <h2 className="mb-6 text-lg font-semibold">
                  {t('coupons.conditions')}
                </h2>
                <div className="grid gap-6 md:grid-cols-2">
                  {textField('minimum', 'minimum_help')}
                  <div className="flex flex-col justify-center gap-4">
                    {(['login_required', 'free_shipping'] as const).map((f) => (
                      <label
                        key={f}
                        className="flex items-center gap-3 text-sm"
                      >
                        <input
                          type="checkbox"
                          checked={form.data[f]}
                          onChange={(e) => form.setData(f, e.target.checked)}
                        />
                        {t(`coupons.${f}`)}
                      </label>
                    ))}
                  </div>
                  <div className="space-y-2">
                    <span className="block text-sm font-medium">
                      {t('coupons.scope')}
                    </span>
                    <Select
                      aria-label={t('coupons.scope')}
                      value={form.data.all_products ? 'all' : 'restricted'}
                      onChange={(v) =>
                        form.setData('all_products', v === 'all')
                      }
                      options={[
                        { value: 'all', label: t('coupons.all_products') },
                        { value: 'restricted', label: t('coupons.restricted') },
                      ]}
                    />
                  </div>
                </div>
                <p className="mt-4 text-sm leading-6 text-slate-500">
                  {t('coupons.scope_help')}
                </p>
                {!form.data.all_products && (
                  <div className="mt-6 grid gap-6 md:grid-cols-2">
                    {(['product', 'category'] as const).map((kind) => {
                      const field =
                        kind === 'product' ? 'product_ids' : 'category_ids';
                      const options =
                        kind === 'product' ? productOptions : categoryOptions;
                      return (
                        <div key={kind} className="min-w-0 space-y-3">
                          <h3 className="text-sm font-medium">
                            {t(`coupons.${field}`)}
                          </h3>
                          <SearchField
                            id={`${kind}-search`}
                            value={search[kind]}
                            onChange={(v) =>
                              setSearch({ ...search, [kind]: v })
                            }
                            placeholder={t(`coupons.${field}`)}
                            onSubmit={() =>
                              router.get(
                                path,
                                { [`${kind}_search`]: search[kind] },
                                {
                                  only: [
                                    kind === 'product'
                                      ? 'productOptions'
                                      : 'categoryOptions',
                                  ],
                                  preserveState: true,
                                  preserveScroll: true,
                                },
                              )
                            }
                          />
                          <div className="flex gap-2">
                            <div className="min-w-0 flex-1">
                              <Select
                                aria-label={t(`coupons.${field}`)}
                                value={choice[kind]}
                                onChange={(v) =>
                                  setChoice({ ...choice, [kind]: v })
                                }
                                options={[
                                  { value: '', label: t('coupons.select') },
                                  ...options
                                    .filter(
                                      (o) => !form.data[field].includes(o.id),
                                    )
                                    .map((o) => ({
                                      value: o.id,
                                      label: o.name,
                                    })),
                                ]}
                              />
                            </div>
                            <button
                              type="button"
                              className="button-secondary self-end"
                              disabled={!choice[kind]}
                              onClick={() => {
                                const id = choice[kind];
                                if (!id || form.data[field].includes(id))
                                  return;
                                form.setData(field, [...form.data[field], id]);
                                setChosenNames({
                                  ...chosenNames,
                                  [`${kind}:${id}`]:
                                    options.find((o) => o.id === id)?.name ??
                                    `#${id}`,
                                });
                                setChoice({ ...choice, [kind]: '' });
                              }}
                            >
                              {t('coupons.add')}
                            </button>
                          </div>
                          <ul className="divide-y divide-slate-200">
                            {form.data[field].map((id) => (
                              <li
                                key={id}
                                className="flex items-center justify-between gap-3 py-3 text-sm"
                              >
                                <span>
                                  {options.find((o) => o.id === id)?.name ??
                                    chosenNames[`${kind}:${id}`] ??
                                    `#${id}`}
                                </span>
                                <button
                                  type="button"
                                  className="text-link shrink-0"
                                  onClick={() =>
                                    form.setData(
                                      field,
                                      form.data[field].filter((v) => v !== id),
                                    )
                                  }
                                >
                                  {t('coupons.remove')}
                                </button>
                              </li>
                            ))}
                          </ul>
                        </div>
                      );
                    })}
                  </div>
                )}
              </section>
              <section className="pt-8">
                <h2 className="mb-2 text-lg font-semibold">
                  {t('coupons.schedule')}
                </h2>
                <p className="mb-6 text-sm text-slate-500">
                  {t('coupons.dates_help')}
                </p>
                <div className="grid gap-6 md:grid-cols-2">
                  {(['starts_on', 'ends_on'] as const).map((f) => (
                    <div key={f} className="space-y-2">
                      <label className="text-sm font-medium" htmlFor={f}>
                        {t(`coupons.${f}`)}
                      </label>
                      <DateInput
                        id={f}
                        value={form.data[f]}
                        onChange={(v) => form.setData(f, v)}
                      />
                      {form.errors[f] && (
                        <p className="text-sm text-red-700">{form.errors[f]}</p>
                      )}
                    </div>
                  ))}
                  {textField('total_limit', 'limit_help')}
                  {textField('customer_limit', 'customer_help')}
                </div>
              </section>
            </fieldset>
          </UnifiedCard>
        </div>
        {coupon && history && (
          <div
            id="coupon-history"
            role="tabpanel"
            hidden={tab !== 'history'}
            className="list-card"
          >
            <div className="border-b border-slate-200 p-5">
              <h2 className="text-lg font-semibold">{t('coupons.history')}</h2>
            </div>
            <div className="relative max-w-full overflow-x-auto">
              <table className="data-table">
                <thead>
                  <tr>
                    {[
                      'order',
                      'customer',
                      'history_amount',
                      'created_at',
                      'status',
                    ].map((f) => (
                      <th key={f}>{t(`coupons.${f}`)}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {history.data.length === 0 && (
                    <tr>
                      <td
                        colSpan={5}
                        className="py-12 text-center text-slate-500"
                      >
                        {t('coupons.history_empty')}
                      </td>
                    </tr>
                  )}
                  {history.data.map((row) => (
                    <tr key={row.id}>
                      <td>
                        {capabilities['orders.view'] ? (
                          <Link
                            className="text-link"
                            href={`/admin/orders/${row.order_id}`}
                          >
                            #{row.order_number}
                          </Link>
                        ) : (
                          `#${row.order_number}`
                        )}
                      </td>
                      <td>{row.customer}</td>
                      <td>{row.amount}</td>
                      <td>
                        {date.format(new Date(row.created_at))}
                        {time.format(new Date(row.created_at))}
                      </td>
                      <td>
                        <StatusBadge
                          tone={row.released ? 'neutral' : 'success'}
                        >
                          {t(
                            row.released
                              ? 'coupons.released'
                              : 'coupons.redeemed',
                          )}
                        </StatusBadge>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <Pagination page={history} />
          </div>
        )}
      </form>
    </AdminLayout>
  );
}
