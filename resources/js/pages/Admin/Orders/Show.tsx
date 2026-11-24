import AdminCard from '../../../components/admin/AdminCard';
import EditorHeader from '../../../components/admin/EditorHeader';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import AdminLayout from '../../../layouts/admin/AdminLayout';
import Select from '../../../components/admin/Select';
import FormErrors from '../../../components/admin/FormErrors';
import StatusBadge from '../../../components/admin/list/StatusBadge';
import OrderStatusBadge from '../../../components/admin/OrderStatusBadge';
import { useDateDisplay } from '../../../lib/useDateDisplay';
import { Icon, type IconName } from '../../../components/admin/list/IconButton';
import { useTranslation } from '../../../lib/useTranslation';
import type { AdminShell } from '../../../types/admin';
import type {
  OrderAddress,
  OrderDetail,
  OrderEmailResult,
  OrderStatusOption,
} from '../../../types/orders';
import type { SharedProps } from '../../../types/page';
import type { OrderPayment } from '../../../types/payments';
import OrderPaymentCard from '../../../components/admin/OrderPaymentCard';
import OrderShippingCard from '../../../components/admin/OrderShippingCard';
import type { OrderShipping, ShipmentForm } from '../../../types/shipping';

type Props = AdminShell & {
  order: OrderDetail;
  statuses: OrderStatusOption[];
  payment: OrderPayment;
  shipping: OrderShipping;
  shipmentForm: ShipmentForm | null;
};

function Card({
  title,
  icon,
  children,
  aside,
}: {
  title: string;
  icon: IconName;
  children: ReactNode;
  aside?: ReactNode;
}) {
  return (
    <AdminCard title={title} icon={icon} action={aside}>
      {children}
    </AdminCard>
  );
}

function Address({ value }: { value: OrderAddress | null }) {
  const t = useTranslation();
  if (!value) return <p className="text-sm text-slate-500">—</p>;
  return (
    <address className="text-sm leading-6 not-italic">
      {value.first_name} {value.last_name}
      {value.company && <br />}
      {value.company}
      <br />
      {value.line1}
      {value.line2 && (
        <>
          <br />
          {value.line2}
        </>
      )}
      <br />
      {[value.postal_code, value.city].filter(Boolean).join(' ')}
      <br />
      {value.country}
      {value.region_code && ', ' + (value.region ?? value.region_code)}
      {value.phone && (
        <>
          <br />
          <span className="text-slate-500">{t('orders.phone')}: </span>
          {value.phone}
        </>
      )}
      {value.floor && (
        <p>
          <span className="text-slate-500">{t('customers.floor')}: </span>
          {value.floor}
        </p>
      )}
      {value.bell_name && (
        <p>
          <span className="text-slate-500">{t('customers.bell_name')}: </span>
          {value.bell_name}
        </p>
      )}
      {value.notes && (
        <p className="whitespace-pre-line">
          <span className="text-slate-500">
            {t('customers.address_notes')}:{' '}
          </span>
          {value.notes}
        </p>
      )}
    </address>
  );
}

export default function OrderShow({
  order,
  statuses,
  payment,
  shipping,
  shipmentForm,
  ...shell
}: Props) {
  const t = useTranslation();
  const { capabilities } = usePage<SharedProps>().props;
  const { date, time } = useDateDisplay();
  const when = (iso: string) =>
    date.format(new Date(iso)) + ' ' + time.format(new Date(iso));
  const statusForm = useForm({
    status_id: order.status?.id ?? '',
    version: order.version,
    note: '',
  });
  const comment = useForm({ visibility: 'internal', body: '' });
  const emailState = (email: OrderEmailResult) => (
    <span className="flex flex-wrap items-center gap-2">
      <StatusBadge
        tone={
          email.state === 'sent'
            ? 'success'
            : email.state === 'failed'
              ? 'danger'
              : 'warning'
        }
      >
        {t(`orders.email_${email.state}`)}
      </StatusBadge>
      <span className="text-xs text-slate-500">
        {email.recipient} ·{' '}
        {t('orders.email_attempts', { count: email.attempts })}
        {email.sent_at && ' · ' + when(email.sent_at)}
      </span>
      {email.error && (
        <span className="text-xs text-red-700">
          {t(`orders.mail_error_${email.error}`)}
        </span>
      )}
      {email.state !== 'sent' && capabilities['orders.notify'] && (
        <button
          type="button"
          className="text-xs font-medium underline"
          onClick={() =>
            router.post(
              '/admin/orders/' +
                order.id +
                '/notifications/' +
                email.id +
                '/retry',
              {},
              { preserveScroll: true },
            )
          }
        >
          {t('orders.retry')}
        </button>
      )}
    </span>
  );
  const totals: [string, string][] = [
    [t('orders.subtotal'), order.totals.subtotal],
    ...(order.totals.has_discount
      ? [
          [t('orders.discount_total'), '−' + order.totals.discount] as [
            string,
            string,
          ],
        ]
      : []),
    [t('orders.shipping_total'), order.totals.shipping],
    ...(order.totals.payment_fee
      ? ([[t('orders.payment_fee'), order.totals.payment_fee]] as [
          string,
          string,
        ][])
      : []),
    ...(order.totals.has_added_tax
      ? [[t('orders.tax_added'), order.totals.tax_added] as [string, string]]
      : []),
  ];
  return (
    <AdminLayout
      title={t('orders.number', { number: order.number })}
      {...shell}
    >
      <EditorHeader
        heading={t('orders.number', { number: order.number })}
        iconKey="orders"
        backHref="/admin/orders"
        backLabel={t('orders.back')}
        status={
          <>
            <OrderStatusBadge status={order.status} />
          </>
        }
        description={
          <>
            <p className="mt-1 text-sm text-slate-500">
              {t('orders.created_at', { date: when(order.created_at) })} ·{' '}
              {t('orders.updated_at', { date: when(order.updated_at) })}
            </p>
            <dl className="mt-3 flex flex-wrap gap-x-6 gap-y-2 text-sm">
              <div className="flex gap-2">
                <dt className="text-slate-500">
                  {t('orders.shipping_method')}:
                </dt>
                <dd className="font-medium">
                  {shipping.method?.name ?? t('orders.shipping_method_none')}
                </dd>
              </div>
              <div className="flex gap-2">
                <dt className="text-slate-500">{t('payments.method')}:</dt>
                <dd className="font-medium">
                  {payment.method?.name ?? t('payments.method_none')}
                </dd>
              </div>
              <div className="flex gap-2">
                <dt className="text-slate-500">
                  {t('orders.invoice_requested')}:
                </dt>
                <dd>
                  <StatusBadge
                    tone={order.invoice_requested ? 'success' : 'neutral'}
                  >
                    {t(order.invoice_requested ? 'common.yes' : 'common.no')}
                  </StatusBadge>
                </dd>
              </div>
            </dl>
          </>
        }
        actions={
          <>
            <div className="flex flex-wrap gap-2">
              <a
                className="button-secondary"
                href={'/admin/orders/' + order.id + '/invoice'}
                target="_blank"
                rel="noopener"
              >
                {t('orders.invoice_print')}
              </a>
              <a
                className="button-secondary"
                href={'/admin/orders/' + order.id + '/packing-slip'}
                target="_blank"
                rel="noopener"
              >
                {t('orders.packing_slip_print')}
              </a>
              {capabilities['orders.update'] && (
                <Link
                  className="button-secondary"
                  href={'/admin/orders/' + order.id + '/edit'}
                >
                  {t('orders.edit')}
                </Link>
              )}
            </div>
          </>
        }
      />
      <div className="grid gap-5 lg:grid-cols-3">
        <div className="space-y-5 lg:col-span-2">
          {order.invoice_details && (
            <Card icon="receipt" title={t('checkout.modal_title')}>
              <dl className="grid gap-x-8 gap-y-5 text-sm sm:grid-cols-2">
                <div className="min-w-0 sm:col-span-2">
                  <dt className="text-xs text-slate-500">
                    {t('checkout.business_name')}
                  </dt>
                  <dd className="mt-1 break-words text-base font-semibold">
                    {order.invoice_details.business_name || '—'}
                  </dd>
                </div>
                <div>
                  <dt className="text-xs text-slate-500">
                    {t('checkout.vat_number')}
                  </dt>
                  <dd className="mt-1 font-mono font-medium">
                    {order.invoice_details.vat_number}
                  </dd>
                </div>
                <div>
                  <dt className="text-xs text-slate-500">
                    {t('checkout.country')}
                  </dt>
                  <dd className="mt-1">
                    {order.invoice_details.country ??
                      order.invoice_details.country_code}{' '}
                    <span className="text-slate-500">
                      ({order.invoice_details.country_code})
                    </span>
                  </dd>
                </div>
                <div>
                  <dt className="text-xs text-slate-500">
                    {t('checkout.provider')}
                  </dt>
                  <dd className="mt-1">
                    {t(`checkout.method_${order.invoice_details.provider}`)}
                  </dd>
                </div>
                <div>
                  <dt className="text-xs text-slate-500">
                    {t('checkout.checked_at')}
                  </dt>
                  <dd className="mt-1 tabular-nums">
                    <time dateTime={order.invoice_details.verified_at}>
                      {when(order.invoice_details.verified_at)}
                    </time>
                  </dd>
                </div>
              </dl>
            </Card>
          )}
          <Card icon="box" title={t('orders.items')}>
            <div className="overflow-x-auto">
              <table className="data-table [&_td]:px-3 [&_th]:px-3">
                <thead>
                  <tr>
                    <th className="min-w-56">{t('orders.product')}</th>
                    <th className="text-right">{t('orders.quantity')}</th>
                    <th className="text-right">
                      {t('orders.invoice_unit_price_net')}
                    </th>
                    <th className="text-right">
                      {t('orders.invoice_vat_rate')}
                    </th>
                    <th className="text-right">
                      {t('orders.invoice_unit_price_gross')}
                    </th>
                    <th className="text-right">{t('orders.line_total')}</th>
                  </tr>
                </thead>
                <tbody>
                  {order.items.map((item) => (
                    <tr key={item.id}>
                      <td className="text-sm">
                        <span className="flex items-center gap-3">
                          <span
                            aria-hidden="true"
                            className="flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-slate-200 bg-slate-50 p-2 text-slate-400"
                          >
                            {item.image_url ? (
                              <img
                                src={item.image_url}
                                alt=""
                                loading="lazy"
                                className="size-full object-cover"
                              />
                            ) : (
                              <Icon name="box" />
                            )}
                          </span>
                          <span className="min-w-0">
                            {item.product_id &&
                            capabilities['products.view'] ? (
                              <Link
                                className="font-medium hover:underline"
                                href={
                                  '/admin/products/' + item.product_id + '/edit'
                                }
                              >
                                {item.name}
                              </Link>
                            ) : (
                              <span className="font-medium">{item.name}</span>
                            )}
                            <span className="block text-xs text-slate-500">
                              {[
                                item.sku && t('orders.sku') + ': ' + item.sku,
                                item.variation,
                                !item.product_id && t('orders.product_deleted'),
                              ]
                                .filter(Boolean)
                                .join(' · ')}
                            </span>
                            {item.discount && (
                              <span className="block text-xs text-emerald-700">
                                {t('orders.line_discount')}: −{item.discount}
                              </span>
                            )}
                          </span>
                        </span>
                      </td>
                      <td className="text-right text-sm tabular-nums">
                        {item.quantity}
                        {item.deducted > 0 &&
                          item.deducted !== item.quantity && (
                            <span className="ml-auto block max-w-24 text-xs text-slate-500">
                              {t('orders.deducted', { count: item.deducted })}
                            </span>
                          )}
                      </td>
                      <td className="text-right text-sm whitespace-nowrap tabular-nums">
                        {item.unit_net}
                      </td>
                      <td className="text-right text-sm whitespace-nowrap tabular-nums">
                        {item.vat_rate}
                        <span className="block text-xs whitespace-normal text-slate-500">
                          {item.tax}
                          {item.tax_included &&
                            ' ' + t('orders.tax_included_short')}
                        </span>
                      </td>
                      <td className="text-right text-sm whitespace-nowrap tabular-nums">
                        {item.unit_gross}
                        {item.price_overridden && (
                          <span className="block text-xs text-slate-500">
                            {t('orders.catalog_price', {
                              price: item.catalog_price,
                            })}
                          </span>
                        )}
                      </td>
                      <td className="text-right text-sm font-medium whitespace-nowrap tabular-nums">
                        {item.total}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <dl className="mt-5 ml-auto max-w-sm space-y-1.5 text-sm">
              {totals.map(([label, value]) => (
                <div key={label} className="flex justify-between gap-4">
                  <dt className="text-slate-600">{label}</dt>
                  <dd className="tabular-nums">{value}</dd>
                </div>
              ))}
              {order.taxes
                .filter((tax) => !tax.included)
                .map((tax, index) => (
                  <div
                    key={index}
                    className="flex justify-between gap-4 pl-3 text-xs text-slate-500"
                  >
                    <dt>
                      {tax.name}
                      {tax.rate && ' (' + tax.rate + ')'}
                      {tax.included && ' · ' + t('orders.tax_included_short')}
                    </dt>
                    <dd className="tabular-nums">{tax.amount}</dd>
                  </div>
                ))}
              <div className="flex justify-between gap-4 border-t border-slate-200 pt-2 text-base font-semibold">
                <dt>{t('orders.grand_total')}</dt>
                <dd className="tabular-nums">{order.totals.total}</dd>
              </div>
              {order.totals.tax_included_raw !== '0' && (
                <div className="space-y-1 text-right text-xs text-slate-500">
                  <p>
                    {t('orders.includes_tax', {
                      amount: order.totals.tax_included,
                    })}
                  </p>
                  {order.taxes
                    .filter((tax) => tax.included)
                    .map((tax, index) => (
                      <p key={index}>
                        {tax.name}
                        {tax.rate && ' (' + tax.rate + ')'}: {tax.amount}
                      </p>
                    ))}
                </div>
              )}
              {order.coupon_code && (
                <p className="text-right text-xs text-slate-500">
                  {t('coupons.code')}: {order.coupon_code}
                </p>
              )}
              {order.promotions?.map((p) => (
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
              {order.coupon_id && capabilities['coupons.view'] && (
                <p className="text-right text-xs">
                  <Link
                    className="text-link"
                    href={`/admin/coupons/${order.coupon_id}/edit?tab=history`}
                  >
                    {t('coupons.history')}
                  </Link>
                </p>
              )}
              {order.tax_exempt && (
                <p className="text-right text-xs text-slate-500">
                  {t('orders.tax_exempt')}
                </p>
              )}
            </dl>
          </Card>
          <Card icon="clock" title={t('orders.history')}>
            {order.history.length === 0 ? (
              <p className="text-sm text-slate-500">—</p>
            ) : (
              <ol className="space-y-4">
                {order.history.map((h) => (
                  <li
                    key={h.id}
                    className="border-l-2 border-slate-200 pl-4 text-sm"
                  >
                    <p className="flex flex-wrap items-center gap-2">
                      {h.from ? (
                        <>
                          <OrderStatusBadge status={h.from} />
                          <span aria-hidden>→</span>
                        </>
                      ) : (
                        <span className="text-slate-500">
                          {t('orders.history_created')}
                        </span>
                      )}
                      <OrderStatusBadge status={h.to} />
                    </p>
                    <p className="mt-1 text-xs text-slate-500">
                      {when(h.at)} · {h.actor ?? t(`orders.source_${h.source}`)}
                    </p>
                    {h.note && <p className="mt-1">{h.note}</p>}
                    {h.emails.map((email) => (
                      <div key={email.id} className="mt-2">
                        <span className="mr-2 text-xs font-medium text-slate-600">
                          {t(`orders.email_kind_${email.kind}`)}:
                        </span>
                        {emailState(email)}
                      </div>
                    ))}
                  </li>
                ))}
              </ol>
            )}
          </Card>
          <Card icon="chat" title={t('orders.comments')}>
            {capabilities['orders.update'] && (
              <form
                className="mb-5 space-y-3"
                onSubmit={(event) => {
                  event.preventDefault();
                  comment.post('/admin/orders/' + order.id + '/comments', {
                    preserveScroll: true,
                    onSuccess: () => comment.reset('body'),
                  });
                }}
              >
                <label className="block text-sm font-medium" htmlFor="comment">
                  {t('orders.add_comment')}
                </label>
                <textarea
                  id="comment"
                  className="field min-h-20"
                  maxLength={5000}
                  value={comment.data.body}
                  onChange={(e) => comment.setData('body', e.target.value)}
                />
                <div className="flex flex-wrap items-center gap-3">
                  <Select
                    className="w-auto"
                    aria-label={t('orders.comment_visibility')}
                    value={comment.data.visibility}
                    onChange={(value) => comment.setData('visibility', value)}
                    options={[
                      {
                        value: 'internal',
                        label: t('orders.comment_internal'),
                      },
                      {
                        value: 'customer',
                        label: t('orders.comment_customer'),
                      },
                    ]}
                  />
                  <button
                    className="button-secondary"
                    disabled={comment.processing}
                  >
                    {t('orders.add_comment')}
                  </button>
                </div>
                <p className="text-xs text-slate-500">
                  {t('orders.comment_hint')}
                </p>
                {comment.errors.body && (
                  <p role="alert" className="text-sm text-red-700">
                    {comment.errors.body}
                  </p>
                )}
              </form>
            )}
            {order.comments.length === 0 ? (
              <p className="text-sm text-slate-500">
                {t('orders.no_comments')}
              </p>
            ) : (
              <ul className="space-y-3">
                {order.comments.map((c) => (
                  <li
                    key={c.id}
                    className="rounded-md border border-slate-200 p-3 text-sm"
                  >
                    <p className="mb-1 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                      <StatusBadge
                        tone={
                          c.visibility === 'internal' ? 'neutral' : 'success'
                        }
                      >
                        {t(`orders.comment_${c.visibility}`)}
                      </StatusBadge>
                      {c.author ?? '—'} · {when(c.at)}
                    </p>
                    <p className="whitespace-pre-line">{c.body}</p>
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </div>
        <div className="space-y-5">
          {capabilities['orders.status'] && (
            <Card icon="restore" title={t('orders.change_status')}>
              <form
                className="space-y-3"
                onSubmit={(event) => {
                  event.preventDefault();
                  statusForm.patch('/admin/orders/' + order.id + '/status', {
                    preserveScroll: true,
                  });
                }}
              >
                <FormErrors errors={statusForm.errors} />
                <Select
                  aria-label={t('orders.new_status')}
                  value={statusForm.data.status_id}
                  onChange={(value) => statusForm.setData('status_id', value)}
                  options={statuses
                    .filter((s) => s.active || s.id === order.status?.id)
                    .map((s) => ({
                      value: s.id,
                      label: s.name,
                      color: s.color,
                      disabled: !s.active,
                    }))}
                />
                <textarea
                  className="field min-h-16"
                  aria-label={t('orders.status_note')}
                  placeholder={t('orders.status_note')}
                  maxLength={1000}
                  value={statusForm.data.note}
                  onChange={(e) => statusForm.setData('note', e.target.value)}
                />
                <p className="text-xs text-slate-500">
                  {t('orders.status_email_hint')}
                </p>
                <button
                  className="button-primary w-full"
                  disabled={
                    statusForm.processing ||
                    statusForm.data.status_id === order.status?.id
                  }
                >
                  {t('orders.change_status')}
                </button>
              </form>
            </Card>
          )}
          <OrderPaymentCard orderId={order.id} payment={payment} />
          <OrderShippingCard
            orderId={order.id}
            shipping={shipping}
            shipmentForm={shipmentForm}
          />
          <Card icon="user" title={t('orders.customer')}>
            <dl className="space-y-2 text-sm">
              <div>
                <dt className="text-xs text-slate-500">{t('common.name')}</dt>
                <dd className="font-medium">
                  {order.customer_id && capabilities['customers.view'] ? (
                    <Link
                      className="hover:underline"
                      href={'/admin/customers/' + order.customer_id}
                    >
                      {order.first_name} {order.last_name}
                    </Link>
                  ) : (
                    order.first_name + ' ' + order.last_name
                  )}
                  {!order.customer_id && (
                    <span className="ml-2 text-xs font-normal text-slate-500">
                      {t('orders.guest')}
                    </span>
                  )}
                </dd>
              </div>
              <div>
                <dt className="text-xs text-slate-500">{t('orders.email')}</dt>
                <dd className="break-all">{order.email}</dd>
              </div>
              {order.phone && (
                <div>
                  <dt className="text-xs text-slate-500">
                    {t('orders.phone')}
                  </dt>
                  <dd>{order.phone}</dd>
                </div>
              )}
              {capabilities['orders.customer-ip'] && (
                <div>
                  <dt className="text-xs text-slate-500">
                    {t('orders.ip_address')}
                  </dt>
                  <dd className="font-mono">
                    {order.ip_address ?? t('orders.ip_unknown')}
                  </dd>
                </div>
              )}
            </dl>
          </Card>
          <Card icon="receipt" title={t('orders.billing_address')}>
            <Address value={order.billing} />
          </Card>
          <Card icon="pin" title={t('orders.shipping_address')}>
            {order.shipping ? (
              <Address value={order.shipping} />
            ) : (
              <p className="text-sm text-slate-500">
                {t('orders.shipping_same')}
              </p>
            )}
          </Card>
          <Card icon="note" title={t('orders.notes')}>
            <dl className="space-y-3 text-sm">
              <div>
                <dt className="text-xs text-slate-500">
                  {t('orders.customer_note')}
                </dt>
                <dd className="whitespace-pre-line">
                  {order.customer_note || '—'}
                </dd>
              </div>
              <div>
                <dt className="text-xs text-slate-500">
                  {t('orders.internal_note')}
                </dt>
                <dd className="whitespace-pre-line">
                  {order.internal_note || '—'}
                </dd>
              </div>
            </dl>
          </Card>
        </div>
      </div>
    </AdminLayout>
  );
}
