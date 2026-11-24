import AdminCard from '../../../components/admin/AdminCard';
import EditorHeader from '../../../components/admin/EditorHeader';
import EditorActionButton from '../../../components/admin/EditorActionButton';
import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';

import FormErrors from '../../../components/admin/FormErrors';
import FormField from '../../../components/auth/FormField';
import AddressFields, {
  emptyAddress,
} from '../../../components/admin/AddressFields';
import Select from '../../../components/admin/Select';
import RequiredMark from '../../../components/admin/RequiredMark';
import OrderStatusBadge from '../../../components/admin/OrderStatusBadge';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';
import type { AddressInput, RegionOptions } from '../../../types/customers';
import type {
  OrderAddressInput,
  OrderCustomerOption,
  OrderEditor,
  OrderLineInput,
  OrderProductOption,
  OrderQuote,
  OrderShippingChoice,
  OrderStatusOption,
} from '../../../types/orders';
import type { SharedProps } from '../../../types/page';
import type { CountryOption } from '../../../types/taxes';

type Props = AdminShell & {
  order: OrderEditor | null;
  statuses: OrderStatusOption[];
  products: OrderProductOption[];
  customers: OrderCustomerOption[];
  countries: CountryOption[];
  regions?: RegionOptions;
  defaultLocale: string;
  quote: OrderQuote | null;
  currency: { code: string; decimals: number };
  paymentMethods: { id: string; name: string }[];
  paymentMethodLocked?: boolean;
  shippingMethods: OrderShippingChoice[];
};

const toInput = (a: OrderAddressInput | null): AddressInput =>
  a ? { ...emptyAddress(), ...a, label: '' } : emptyAddress();

export default function OrderForm({
  order,
  statuses,
  products,
  customers,
  countries,
  regions = {},
  defaultLocale,
  quote: serverQuote,
  currency,
  paymentMethods,
  paymentMethodLocked = false,
  shippingMethods,
  ...shell
}: Props) {
  const t = useTranslation();
  const { i18n } = usePage<SharedProps>().props;
  const [productSearch, setProductSearch] = useState('');
  const [customerSearch, setCustomerSearch] = useState('');
  const initial = statuses.find((s) => s.initial) ?? statuses[0];
  const form = useForm({
    version: order?.version ?? 1,
    customer_id: order?.customer_id ?? '',
    locale: order?.locale ?? defaultLocale,
    first_name: order?.first_name ?? '',
    last_name: order?.last_name ?? '',
    email: order?.email ?? '',
    phone: order?.phone ?? '',
    billing: toInput(order?.billing ?? null),
    shipping_same: !order?.shipping,
    shipping: toInput(order?.shipping ?? null),
    coupon_code: order?.coupon_code ?? '',
    customer_note: order?.customer_note ?? '',
    internal_note: order?.internal_note ?? '',
    status_id: initial?.id ?? '',
    payment_method_id: order
      ? order.payment_method_id
      : (paymentMethods[0]?.id ?? ''),
    shipping_method_id: order?.shipping_method_id ?? '',
    shipping_amount: order?.shipping_amount ?? '',
    recalculate_shipping: false,
    confirm_final: false,
    lines: (order?.items ?? []).map((item): OrderLineInput => ({
      key: item.item_id,
      item_id: item.item_id,
      product_id: item.product_id,
      name: item.name,
      sku: item.sku,
      variation: item.variation,
      quantity: String(item.quantity),
      unit_price: item.unit_price,
      catalog_price: item.catalog_price,
      price_overridden: item.price_overridden,
    })),
  });
  const errors = form.errors as Record<string, string>;
  const locked = order !== null && !order.editable_currency;
  const final = order?.status?.final ?? false;
  const payload = () => ({
    version: form.data.version,
    customer_id: form.data.customer_id || null,
    locale: form.data.locale,
    first_name: form.data.first_name,
    last_name: form.data.last_name,
    email: form.data.email,
    phone: form.data.phone,
    billing: form.data.billing,
    shipping_same: form.data.shipping_same,
    shipping: form.data.shipping_same ? null : form.data.shipping,
    coupon_code: form.data.coupon_code,
    customer_note: form.data.customer_note,
    internal_note: form.data.internal_note,
    status_id: form.data.status_id,
    payment_method_id: form.data.payment_method_id || null,
    shipping_method_id: form.data.shipping_method_id || null,
    shipping_amount: form.data.shipping_method_id
      ? form.data.shipping_amount
      : '',
    recalculate_shipping: form.data.recalculate_shipping,
    confirm_final: form.data.confirm_final,
    items: form.data.lines.map((line) => ({
      item_id: line.item_id,
      product_id: line.item_id ? null : line.product_id,
      quantity: line.quantity,
      unit_price: line.unit_price,
    })),
  });
  const reload = (only: string, data: Record<string, string>) =>
    router.get(window.location.pathname, data, {
      only: [only],
      preserveState: true,
      preserveScroll: true,
      replace: true,
    });
  const chooseCustomer = (id: string) => {
    const customer = customers.find((c) => c.id === id);
    if (!customer) {
      form.setData('customer_id', '');
      return;
    }
    form.setData({
      ...form.data,
      customer_id: customer.id,
      first_name: customer.first_name,
      last_name: customer.last_name,
      email: customer.email,
      phone: customer.phone,
      billing: customer.billing ? toInput(customer.billing) : form.data.billing,
      shipping_same: !customer.shipping,
      shipping: toInput(customer.shipping),
    });
  };
  const addProduct = (product: OrderProductOption) => {
    if (form.data.lines.some((l) => !l.item_id && l.product_id === product.id))
      return;
    form.setData('lines', [
      ...form.data.lines,
      {
        key: 'p' + product.id,
        item_id: null,
        product_id: product.id,
        name: product.name,
        sku: product.sku,
        variation: null,
        quantity: '1',
        unit_price: '',
        catalog_price: product.price,
        price_overridden: false,
      },
    ]);
  };
  const [quoteInput, setQuoteInput] = useState(() => JSON.stringify(form.data));
  const quote = quoteInput === JSON.stringify(form.data) ? serverQuote : null;
  const setLine = (key: string, changes: Partial<OrderLineInput>) =>
    form.setData(
      'lines',
      form.data.lines.map((l) => (l.key === key ? { ...l, ...changes } : l)),
    );
  const shippingMethod = shippingMethods.find(
    (m) => m.id === form.data.shipping_method_id,
  );
  const paymentMismatch =
    !!shippingMethod &&
    shippingMethod.payment_method_ids.length > 0 &&
    !shippingMethod.payment_method_ids.includes(form.data.payment_method_id);
  const quoteLine = (line: OrderLineInput) =>
    quote?.lines.find((q) => q.key === (line.item_id ?? 'p' + line.product_id));
  const title = order
    ? t('orders.edit_number', { number: order.number })
    : t('orders.create');
  return (
    <AdminLayout title={title} {...shell}>
      <EditorHeader
        heading={title}
        iconKey="orders"
        backHref={order ? '/admin/orders/' + order.id : '/admin/orders'}
        backLabel={t('orders.back')}
        status={<>{order && <OrderStatusBadge status={order.status} />}</>}
        actions={
          <>
            <button
              type="button"
              className="button-secondary"
              disabled={form.processing || form.data.lines.length === 0}
              onClick={() => {
                setQuoteInput(JSON.stringify(form.data));
                form.transform(payload);
                form.post(
                  order
                    ? '/admin/orders/' + order.id + '/quote'
                    : '/admin/orders/quote',
                  { preserveState: true, preserveScroll: true },
                );
              }}
            >
              {t('orders.calculate')}
            </button>
            <EditorActionButton
              disabled={form.processing}
              form="admin-orders-form-0"
            >
              {t(form.processing ? 'common.wait' : 'common.save')}
            </EditorActionButton>
          </>
        }
      />
      <form
        className="space-y-5"
        onSubmit={(event) => {
          event.preventDefault();
          form.transform(payload);
          if (order) form.put('/admin/orders/' + order.id);
          else form.post('/admin/orders');
        }}
        id="admin-orders-form-0"
      >
        <p className="text-xs text-slate-500">{t('common.required_fields')}</p>
        <FormErrors errors={errors} />
        {locked && (
          <p className="rounded-md bg-amber-50 p-3 text-sm text-amber-800">
            {t('orders.currency_locked', { currency: order.currency })}
          </p>
        )}
        <section>
          <AdminCard
            title={t('orders.customer')}
            iconKey="customer"
            bodyClassName="p-5 space-y-5"
          >
            <div className="flex flex-wrap items-end gap-3">
              <div className="grow">
                <FormField
                  id="customer-search"
                  label={t('orders.find_customer')}
                  value={customerSearch}
                  onChange={(e) => setCustomerSearch(e.target.value)}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter') {
                      e.preventDefault();
                      reload('customers', { customer_search: customerSearch });
                    }
                  }}
                />
              </div>
              <button
                type="button"
                className="button-secondary"
                onClick={() =>
                  reload('customers', { customer_search: customerSearch })
                }
              >
                {t('common.search')}
              </button>
            </div>
            <label className="block text-sm font-medium">
              {t('orders.customer_account')}
              <Select
                className="mt-2"
                aria-label={t('orders.customer_account')}
                value={form.data.customer_id}
                onChange={chooseCustomer}
                options={[
                  { value: '', label: t('orders.guest_order') },
                  ...customers.map((c) => ({
                    value: c.id,
                    label: c.name + ' · ' + c.email,
                  })),
                ]}
              />
            </label>
            <p className="text-xs text-slate-500">
              {t('orders.customer_hint')}
            </p>
            <div className="grid gap-4 sm:grid-cols-2">
              <FormField
                id="first_name"
                label={t('orders.first_name')}
                requiredIndicator
                maxLength={100}
                value={form.data.first_name}
                error={errors.first_name}
                onChange={(e) => form.setData('first_name', e.target.value)}
              />
              <FormField
                id="last_name"
                label={t('orders.last_name')}
                requiredIndicator
                maxLength={100}
                value={form.data.last_name}
                error={errors.last_name}
                onChange={(e) => form.setData('last_name', e.target.value)}
              />
              <FormField
                id="email"
                type="email"
                label={t('orders.email')}
                requiredIndicator
                maxLength={254}
                value={form.data.email}
                error={errors.email}
                onChange={(e) => form.setData('email', e.target.value)}
              />
              <FormField
                id="phone"
                label={t('orders.phone')}
                maxLength={40}
                value={form.data.phone}
                error={errors.phone}
                onChange={(e) => form.setData('phone', e.target.value)}
              />
              <label className="block text-sm font-medium">
                {t('orders.email_language')}
                <RequiredMark />
                <Select
                  className="mt-2"
                  aria-label={t('orders.email_language')}
                  value={form.data.locale}
                  onChange={(value) => form.setData('locale', value)}
                  options={i18n.locales.map((l) => ({
                    value: l.code,
                    label: l.name,
                  }))}
                />
              </label>
            </div>
          </AdminCard>
        </section>
        <section>
          <AdminCard
            title={t('orders.billing_address')}
            iconKey="billing_address"
            bodyClassName="p-5 space-y-5"
          >
            <AddressFields
              id="billing"
              value={form.data.billing}
              onChange={(value) => form.setData('billing', value)}
              errors={errors}
              countries={countries}
              regions={regions}
              prefix="billing."
              delivery
              streetNumber={false}
            />
            <label className="flex items-center gap-2 border-t border-slate-200 pt-5 text-sm font-medium">
              <input
                type="checkbox"
                checked={form.data.shipping_same}
                onChange={(e) =>
                  form.setData('shipping_same', e.target.checked)
                }
              />
              {t('orders.shipping_same')}
            </label>
            {!form.data.shipping_same && (
              <>
                <h2 className="text-base font-semibold">
                  {t('orders.shipping_address')}
                </h2>
                <AddressFields
                  id="shipping"
                  value={form.data.shipping}
                  onChange={(value) => form.setData('shipping', value)}
                  errors={errors}
                  countries={countries}
                  regions={regions}
                  prefix="shipping."
                  delivery
                  streetNumber={false}
                />
              </>
            )}
          </AdminCard>
        </section>
        <section>
          <AdminCard
            title={t('orders.items')}
            iconKey="items"
            bodyClassName="p-5 space-y-5"
          >
            {!locked && (
              <>
                <div className="flex flex-wrap items-end gap-3">
                  <div className="grow">
                    <FormField
                      id="product-search"
                      label={t('orders.find_product')}
                      value={productSearch}
                      onChange={(e) => setProductSearch(e.target.value)}
                      onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                          e.preventDefault();
                          reload('products', { product_search: productSearch });
                        }
                      }}
                    />
                  </div>
                  <button
                    type="button"
                    className="button-secondary"
                    onClick={() =>
                      reload('products', { product_search: productSearch })
                    }
                  >
                    {t('common.search')}
                  </button>
                </div>
                <ul className="max-h-64 divide-y divide-slate-100 overflow-auto rounded-md border border-slate-200">
                  {products.length === 0 && (
                    <li className="p-3 text-sm text-slate-500">
                      {t('orders.no_products')}
                    </li>
                  )}
                  {products.map((p) => {
                    const added = form.data.lines.some(
                      (l) => l.product_id === p.id,
                    );
                    return (
                      <li
                        key={p.id}
                        className="flex flex-wrap items-center justify-between gap-3 p-3 text-sm"
                      >
                        <span>
                          <span className="font-medium">{p.name}</span>
                          <span className="block text-xs text-slate-500">
                            {[
                              p.sku,
                              p.price_formatted,
                              t('orders.in_stock', { count: p.quantity }),
                              p.status !== 'active' &&
                                t('products.' + p.status),
                            ]
                              .filter(Boolean)
                              .join(' · ')}
                          </span>
                        </span>
                        <button
                          type="button"
                          className="button-secondary text-sm"
                          disabled={added}
                          onClick={() => addProduct(p)}
                        >
                          {t(added ? 'orders.added' : 'orders.add')}
                        </button>
                      </li>
                    );
                  })}
                </ul>
              </>
            )}
            {errors.items && (
              <p role="alert" className="text-sm text-red-700">
                {errors.items}
              </p>
            )}
            <div className="overflow-x-auto">
              <table className="data-table">
                <thead>
                  <tr>
                    <th>{t('orders.product')}</th>
                    <th className="w-28">{t('orders.quantity')}</th>
                    <th className="w-40">
                      {t('orders.unit_price_currency', { code: currency.code })}
                    </th>
                    <th className="text-right">{t('orders.tax')}</th>
                    <th className="text-right">{t('orders.line_total')}</th>
                    <th>
                      <span className="sr-only">{t('orders.remove')}</span>
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {form.data.lines.length === 0 && (
                    <tr>
                      <td
                        colSpan={6}
                        className="py-6 text-center text-sm text-slate-500"
                      >
                        {t('orders.no_items')}
                      </td>
                    </tr>
                  )}
                  {form.data.lines.map((line, index) => {
                    const priced = quoteLine(line);
                    const deleted = line.item_id !== null && !line.product_id;
                    return (
                      <tr key={line.key}>
                        <td className="text-sm">
                          <span className="font-medium">{line.name}</span>
                          <span className="block text-xs text-slate-500">
                            {[
                              line.sku,
                              line.variation,
                              deleted && t('orders.product_deleted'),
                            ]
                              .filter(Boolean)
                              .join(' · ')}
                          </span>
                          {errors['items.' + index + '.quantity'] && (
                            <span
                              role="alert"
                              className="block text-xs text-red-700"
                            >
                              {errors['items.' + index + '.quantity']}
                            </span>
                          )}
                          {errors['items.' + index + '.product_id'] && (
                            <span
                              role="alert"
                              className="block text-xs text-red-700"
                            >
                              {errors['items.' + index + '.product_id']}
                            </span>
                          )}
                        </td>
                        <td>
                          <input
                            className="field"
                            type="number"
                            min={1}
                            step={1}
                            inputMode="numeric"
                            aria-label={t('orders.quantity_of', {
                              name: line.name,
                            })}
                            disabled={locked || deleted}
                            value={line.quantity}
                            onChange={(e) =>
                              setLine(line.key, { quantity: e.target.value })
                            }
                          />
                        </td>
                        <td>
                          <input
                            className="field"
                            inputMode="decimal"
                            aria-label={t('orders.unit_price_of', {
                              name: line.name,
                            })}
                            placeholder={line.catalog_price}
                            disabled={locked || deleted}
                            value={line.unit_price}
                            onChange={(e) =>
                              setLine(line.key, { unit_price: e.target.value })
                            }
                          />
                          {errors['items.' + index + '.unit_price'] && (
                            <span
                              role="alert"
                              className="block text-xs text-red-700"
                            >
                              {errors['items.' + index + '.unit_price']}
                            </span>
                          )}
                          <span className="mt-1 block text-xs text-slate-500">
                            {t('orders.catalog_price', {
                              price: line.catalog_price,
                            })}
                          </span>
                        </td>
                        <td className="text-right text-sm tabular-nums">
                          {priced?.tax ?? '—'}
                        </td>
                        <td className="text-right text-sm font-medium tabular-nums">
                          {priced?.total ?? '—'}
                        </td>
                        <td>
                          {!locked && (
                            <button
                              type="button"
                              className="text-sm text-red-700"
                              onClick={() =>
                                form.setData(
                                  'lines',
                                  form.data.lines.filter(
                                    (l) => l.key !== line.key,
                                  ),
                                )
                              }
                            >
                              {t('orders.remove')}
                            </button>
                          )}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
            <p className="text-xs text-slate-500">
              {t('orders.price_override_hint')}
            </p>
            <div className="max-w-md">
              <FormField
                id="coupon_code"
                label={t('coupons.coupon_code')}
                value={form.data.coupon_code}
                maxLength={20}
                error={errors.coupon_code}
                tooltip={t('coupons.order_help')}
                onChange={(e) => form.setData('coupon_code', e.target.value)}
              />
            </div>
            {quote ? (
              <dl className="ml-auto max-w-sm space-y-1.5 rounded-md bg-slate-50 p-4 text-sm">
                <div className="flex justify-between gap-4">
                  <dt>{t('orders.subtotal')}</dt>
                  <dd className="tabular-nums">{quote.subtotal}</dd>
                </div>
                {(quote.coupon_code || (quote.promotions?.length ?? 0) > 0) && (
                  <div className="flex justify-between gap-4">
                    <dt>
                      {t('orders.discount_total')}
                      {quote.coupon_code ? ` (${quote.coupon_code})` : ''}
                    </dt>
                    <dd className="tabular-nums">−{quote.discount}</dd>
                  </div>
                )}
                {quote.promotions?.map((p) => (
                  <div
                    key={p.id}
                    className="rounded-lg bg-emerald-50 px-3 py-2 text-sm"
                  >
                    <dt>{p.name}</dt>
                    <dd>
                      {t('promotions.discount_summary', {
                        discount: p.discount,
                        shipping: p.shipping_discount,
                      })}
                    </dd>
                  </div>
                ))}
                {quote.taxes.map((tax) => (
                  <div
                    key={tax.name}
                    className="flex justify-between gap-4 text-xs text-slate-500"
                  >
                    <dt>
                      {tax.name}
                      {tax.included && ' · ' + t('orders.tax_included_short')}
                    </dt>
                    <dd className="tabular-nums">{tax.amount}</dd>
                  </div>
                ))}
                {quote.shipping_status && (
                  <div className="flex justify-between gap-4">
                    <dt>{t('orders.shipping_charge')}</dt>
                    <dd className="tabular-nums">
                      {quote.shipping_status.available ||
                      quote.shipping_status.overridden
                        ? quote.shipping
                        : '—'}
                    </dd>
                  </div>
                )}
                {quote.payment_fee && (
                  <div className="flex justify-between gap-4">
                    <dt>{t('orders.payment_fee')}</dt>
                    <dd className="tabular-nums">{quote.payment_fee}</dd>
                  </div>
                )}
                <div className="flex justify-between gap-4">
                  <dt>{t('orders.tax_total')}</dt>
                  <dd className="tabular-nums">{quote.tax}</dd>
                </div>
                <div className="flex justify-between gap-4 border-t border-slate-200 pt-2 font-semibold">
                  <dt>{t('orders.grand_total')}</dt>
                  <dd className="tabular-nums">{quote.total}</dd>
                </div>
                {quote.unresolved.length > 0 && (
                  <p role="status" className="text-xs text-amber-800">
                    {t('orders.taxes_unresolved', {
                      taxes: quote.unresolved.join(', '),
                    })}
                  </p>
                )}
                {quote.exempt && (
                  <p className="text-xs text-slate-500">
                    {t('orders.tax_exempt')}
                  </p>
                )}
                {quote.shipping_status && !quote.shipping_status.available && (
                  <p role="status" className="text-xs text-amber-800">
                    {quote.shipping_status.message}
                  </p>
                )}
                {quote.shipping_status?.overridden &&
                  quote.shipping_status.calculated && (
                    <p className="text-xs text-slate-500">
                      {t('orders.shipping_calculated', {
                        amount: quote.shipping_status.calculated,
                      })}
                    </p>
                  )}
              </dl>
            ) : (
              <p className="text-right text-sm text-slate-500">
                {t('orders.calculate_hint')}
              </p>
            )}
          </AdminCard>
        </section>
        <section>
          <AdminCard
            title={t('orders.notes')}
            iconKey="notes"
            bodyClassName="p-5 space-y-5"
          >
            {!order && (
              <label className="block text-sm font-medium">
                {t('orders.initial_status')}
                <RequiredMark />
                <Select
                  className="mt-2"
                  aria-label={t('orders.initial_status')}
                  value={form.data.status_id}
                  onChange={(value) => form.setData('status_id', value)}
                  options={statuses.map((s) => ({
                    value: s.id,
                    label: s.name,
                    color: s.color,
                  }))}
                />
                <span className="mt-1 block text-xs font-normal text-slate-500">
                  {t('orders.initial_status_hint')}
                </span>
              </label>
            )}
            <div className="space-y-2">
              <label
                className="block text-sm font-medium"
                htmlFor="payment-method"
              >
                {t('payments.method')}
              </label>
              <Select
                id="payment-method"
                aria-label={t('payments.method')}
                value={form.data.payment_method_id}
                disabled={paymentMethodLocked}
                invalid={!!errors.payment_method_id}
                onChange={(value) => form.setData('payment_method_id', value)}
                options={[
                  { value: '', label: t('payments.method_none') },
                  ...paymentMethods.map((m) => ({
                    value: m.id,
                    label: m.name,
                  })),
                ]}
              />
              {paymentMethodLocked && (
                <p className="text-xs text-slate-500">
                  {t('payments.method_locked')}
                </p>
              )}
              {errors.payment_method_id && (
                <p role="alert" className="text-sm text-red-700">
                  {errors.payment_method_id}
                </p>
              )}
            </div>
            <div className="space-y-2">
              <label
                className="block text-sm font-medium"
                htmlFor="shipping-method"
              >
                {t('orders.shipping_method')}
              </label>
              <Select
                id="shipping-method"
                aria-label={t('orders.shipping_method')}
                value={form.data.shipping_method_id}
                invalid={!!errors.shipping_method_id}
                onChange={(value) =>
                  form.setData({
                    ...form.data,
                    shipping_method_id: value,
                    shipping_amount: value ? form.data.shipping_amount : '',
                  })
                }
                options={[
                  { value: '', label: t('orders.shipping_method_none') },
                  ...shippingMethods.map((m) => ({
                    value: m.id,
                    label: m.name,
                  })),
                ]}
              />
              {paymentMismatch && (
                <p role="status" className="text-xs text-amber-800">
                  {t('orders.payment_not_for_shipping')}
                </p>
              )}
              {errors.shipping_method_id && (
                <p role="alert" className="text-sm text-red-700">
                  {errors.shipping_method_id}
                </p>
              )}
            </div>
            {form.data.shipping_method_id && (
              <div className="grid gap-4 sm:grid-cols-2">
                <FormField
                  id="shipping-amount"
                  label={t('orders.shipping_amount', {
                    currency: currency.code,
                  })}
                  hint={t('orders.shipping_amount_hint')}
                  inputMode="decimal"
                  value={form.data.shipping_amount}
                  error={errors.shipping_amount}
                  onChange={(e) =>
                    form.setData('shipping_amount', e.target.value)
                  }
                />
                {order && (
                  <label className="flex items-start gap-2 self-center text-sm">
                    <input
                      type="checkbox"
                      className="mt-0.5"
                      checked={form.data.recalculate_shipping}
                      onChange={(e) =>
                        form.setData('recalculate_shipping', e.target.checked)
                      }
                    />
                    <span>
                      {t('orders.recalculate_shipping')}
                      <span className="block text-xs text-slate-500">
                        {t('orders.recalculate_shipping_hint')}
                      </span>
                    </span>
                  </label>
                )}
              </div>
            )}
            {(['customer_note', 'internal_note'] as const).map((field) => (
              <label key={field} className="block text-sm font-medium">
                {t('orders.' + field)}
                <textarea
                  className="field mt-2 min-h-20"
                  maxLength={5000}
                  value={form.data[field]}
                  onChange={(e) => form.setData(field, e.target.value)}
                />
                <span className="mt-1 block text-xs font-normal text-slate-500">
                  {t('orders.' + field + '_hint')}
                </span>
              </label>
            ))}
            {final && (
              <label className="flex items-start gap-2 rounded-md bg-amber-50 p-3 text-sm text-amber-900">
                <input
                  type="checkbox"
                  className="mt-0.5"
                  checked={form.data.confirm_final}
                  onChange={(e) =>
                    form.setData('confirm_final', e.target.checked)
                  }
                />
                {t('orders.confirm_final')}
              </label>
            )}
          </AdminCard>
        </section>
      </form>
    </AdminLayout>
  );
}
