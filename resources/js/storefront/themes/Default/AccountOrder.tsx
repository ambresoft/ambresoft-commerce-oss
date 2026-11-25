import { router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { useTranslation } from '../../../lib/useTranslation';
import { useDateFormat } from './AccountParts';
import Dialog from './Dialog';
import type { AccountOrderAddress, AccountOrderDetail } from '../../types';

const icons = {
  download: (
    <>
      <path d="M12 3v12m-4-4 4 4 4-4M4 16v5h16v-5" />
    </>
  ),
  cancel: (
    <>
      <circle cx="12" cy="12" r="9" />
      <path d="m9 9 6 6m0-6-6 6" />
    </>
  ),
  back: <path d="m15 6-6 6 6 6" />,
  calendar: (
    <>
      <rect x="3.5" y="5" width="17" height="15.5" rx="2.5" />
      <path d="M8 3v4M16 3v4M3.5 10h17" />
    </>
  ),
  box: (
    <>
      <path d="M21 8 12 3 3 8l9 5 9-5Z" />
      <path d="M3 8v8l9 5 9-5V8M12 13v8" />
    </>
  ),
  pin: (
    <>
      <path d="M12 21s7-6.2 7-11.5A7 7 0 0 0 5 9.5C5 14.8 12 21 12 21Z" />
      <circle cx="12" cy="9.5" r="2.5" />
    </>
  ),
  receipt: (
    <>
      <path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3Z" />
      <path d="M9 8h6M9 12h6" />
    </>
  ),
  truck: (
    <>
      <path d="M3 6h11v10H3zM14 10h4l3 3v3h-7" />
      <circle cx="7.5" cy="17.5" r="1.8" />
      <circle cx="17.5" cy="17.5" r="1.8" />
    </>
  ),
  card: (
    <>
      <rect x="3" y="5.5" width="18" height="13" rx="2.5" />
      <path d="M3 10h18M7 15h3" />
    </>
  ),
  note: (
    <>
      <path d="M5 4h14a1 1 0 0 1 1 1v10l-5 5H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1Z" />
      <path d="M15 20v-4a1 1 0 0 1 1-1h4M8 9h8M8 13h4" />
    </>
  ),
  phone: (
    <path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A15 15 0 0 1 3 6a2 2 0 0 1 2-2Z" />
  ),
  clock: (
    <>
      <circle cx="12" cy="12" r="9" />
      <path d="M12 7v5l3 2" />
    </>
  ),
};

function Icon({ name }: { name: keyof typeof icons }) {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      width="20"
      height="20"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      {icons[name]}
    </svg>
  );
}

function Panel({
  icon,
  title,
  children,
  className = '',
}: {
  icon: keyof typeof icons;
  title: string;
  children: ReactNode;
  className?: string;
}) {
  return (
    <section className={`sf-acc-card sf-ord-panel ${className}`}>
      <h2>
        <span className="sf-ord-icon">
          <Icon name={icon} />
        </span>
        {title}
      </h2>
      {children}
    </section>
  );
}

/** A product picture; a plain icon stands in when there is none or it cannot be shown. */
function Thumb({
  image,
}: {
  image: AccountOrderDetail['items'][number]['image'];
}) {
  const [failed, setFailed] = useState(false);
  return (
    <span className="sf-ord-thumb">
      {image && !failed ? (
        <img
          src={image.url}
          alt=""
          width={image.width}
          height={image.height}
          loading="lazy"
          onError={() => setFailed(true)}
        />
      ) : (
        <Icon name="box" />
      )}
    </span>
  );
}

function Address({ address }: { address: AccountOrderAddress }) {
  return (
    <address className="sf-ord-address">
      {address.lines.map((line, i) => (
        <span key={i} className={i === 0 ? 'is-name' : undefined}>
          {line}
        </span>
      ))}
      {address.phone && (
        <span className="sf-ord-phone">
          <Icon name="phone" />
          {address.phone}
        </span>
      )}
    </address>
  );
}

/** One of the customer's orders in full: status and delivery, products, totals, details and progress. */
export default function AccountOrder({ order }: { order: AccountOrderDetail }) {
  const t = useTranslation();
  const format = useDateFormat();
  const dateTime = (iso: string) =>
    format(iso, { dateStyle: 'medium', timeStyle: 'short' });
  const day = format(order.created_at, {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
  });
  const recipient = order.shipping?.lines[0];
  const where = order.shipping?.lines.slice(1, -1).join(', ');
  const [cancelling, setCancelling] = useState(false);
  const sameAddress =
    order.billing &&
    order.shipping &&
    JSON.stringify(order.billing) === JSON.stringify(order.shipping);
  return (
    <>
      <a className="sf-ord-back" href="/account/orders">
        <Icon name="back" />
        {t('account.order_back')}
      </a>

      <section className="sf-acc-card sf-ord-status">
        <div className="sf-ord-status-head">
          <div>
            {order.status && (
              <h2 style={{ color: order.status.color }}>{order.status.name}</h2>
            )}
            <p className="sf-ord-day">{day}</p>
            {where && <p className="sf-ord-where">{where}</p>}
          </div>
          <div className="sf-ord-status-actions">
            <span className="sf-ord-number">
              {t('account.order_heading', { number: order.number })}
            </span>
            {order.invoice_url && (
              <a href={order.invoice_url} className="sf-ord-invoice" download>
                <Icon name="download" />
                {t('account.download_invoice')}
              </a>
            )}
            {order.can_cancel && (
              <button
                type="button"
                className="sf-ord-cancel"
                onClick={() => setCancelling(true)}
              >
                <Icon name="cancel" />
                {t('orders.cancel_button')}
              </button>
            )}
          </div>
        </div>
        <dl className="sf-ord-facts">
          {order.invoice_requested && (
            <div>
              <dt>{t('storefront.invoice_requested')}:</dt>
              <dd>{t('storefront.yes')}</dd>
            </div>
          )}
          {order.shipping_method && (
            <div>
              <dt>{t('account.order_shipping_method')}:</dt>
              <dd>{order.shipping_method}</dd>
            </div>
          )}
          {recipient && (
            <div>
              <dt>{t('account.order_recipient')}:</dt>
              <dd>{recipient}</dd>
            </div>
          )}
        </dl>
        <ul className="sf-ord-items">
          {order.items.map((i) => (
            <li key={i.id}>
              {i.url ? (
                <a
                  className="sf-ord-link"
                  href={i.url}
                  aria-hidden="true"
                  tabIndex={-1}
                >
                  <Thumb image={i.image} />
                </a>
              ) : (
                <Thumb image={i.image} />
              )}
              <div className="sf-ord-item-info">
                <strong>
                  {i.url ? (
                    <a className="sf-ord-link" href={i.url}>
                      {i.name}
                    </a>
                  ) : (
                    i.name
                  )}
                </strong>
                {(i.variation || i.sku) && (
                  <span>
                    {[
                      i.variation,
                      i.sku && `${t('account.order_sku')}: ${i.sku}`,
                    ]
                      .filter(Boolean)
                      .join(' · ')}
                  </span>
                )}
              </div>
              <span className="sf-ord-item-calc">
                {i.quantity} × {i.unit_price}
              </span>
            </li>
          ))}
        </ul>
        <div className="sf-ord-pay">
          <span>{order.payment_method ?? t('account.order_total')}</span>
          <b>{order.totals.total}</b>
        </div>
      </section>

      <div className="sf-ord-cols">
        <Panel icon="receipt" title={t('account.order_summary')}>
          <dl className="sf-ord-totals">
            <div>
              <dt>{t('account.order_subtotal')}</dt>
              <dd>{order.totals.subtotal}</dd>
            </div>
            {order.totals.discount && (
              <div className="is-discount">
                <dt>{t('account.order_discount')}</dt>
                <dd>−{order.totals.discount}</dd>
              </div>
            )}
            <div>
              <dt>{t('account.order_shipping')}</dt>
              <dd
                className={order.totals.shipping_free ? 'is-free' : undefined}
              >
                {order.totals.shipping_free
                  ? t('account.order_free')
                  : `+ ${order.totals.shipping}`}
              </dd>
            </div>
            {order.totals.payment_fee && (
              <div>
                <dt>{t('account.order_payment_fee')}</dt>
                <dd>+ {order.totals.payment_fee}</dd>
              </div>
            )}
            <div className="is-total">
              <dt>{t('account.order_total')}</dt>
              <dd>{order.totals.total}</dd>
            </div>
            {order.totals.tax_included && (
              <div className="is-muted">
                <dt>{t('account.order_vat_included')}</dt>
                <dd>{order.totals.tax_included}</dd>
              </div>
            )}
          </dl>
        </Panel>

        <Panel icon="note" title={t('account.order_details')}>
          <dl className="sf-ord-details">
            {order.payment_method && (
              <div>
                <dt>{t('account.order_payment_method')}</dt>
                <dd>{order.payment_method}</dd>
              </div>
            )}
            <div>
              <dt>{t('account.order_placed_label')}</dt>
              <dd>{dateTime(order.created_at)}</dd>
            </div>
            {order.shipping && (
              <div>
                <dt>{t('account.order_shipping_address')}</dt>
                <dd>
                  <Address address={order.shipping} />
                </dd>
              </div>
            )}
            {order.billing && !sameAddress && (
              <div>
                <dt>{t('account.order_billing_address')}</dt>
                <dd>
                  <Address address={order.billing} />
                </dd>
              </div>
            )}
            {order.note && (
              <div>
                <dt>{t('account.order_note')}</dt>
                <dd className="sf-ord-note">{order.note}</dd>
              </div>
            )}
          </dl>
        </Panel>
      </div>

      {order.invoice_details && (
        <Panel icon="receipt" title={t('checkout.modal_title')}>
          <dl className="sf-ord-details">
            {order.invoice_details.business_name && (
              <div>
                <dt>{t('checkout.business_name')}</dt>
                <dd>{order.invoice_details.business_name}</dd>
              </div>
            )}
            <div>
              <dt>{t('checkout.vat_number')}</dt>
              <dd>{order.invoice_details.vat_number}</dd>
            </div>
            <div>
              <dt>{t('checkout.country')}</dt>
              <dd>
                {order.invoice_details.country} (
                {order.invoice_details.country_code})
              </dd>
            </div>
          </dl>
        </Panel>
      )}

      {order.timeline.length > 0 && (
        <Panel icon="clock" title={t('account.order_progress')}>
          <ol className="sf-ord-timeline">
            {order.timeline.map((h, n) => (
              <li key={n}>
                <span
                  aria-hidden="true"
                  style={{ background: h.status.color }}
                />
                <strong>{h.status.name}</strong>
                <time dateTime={h.at}>{dateTime(h.at)}</time>
              </li>
            ))}
          </ol>
        </Panel>
      )}
      {cancelling && (
        <Dialog
          title={t('orders.cancel_page_heading', { number: order.number })}
          onClose={() => setCancelling(false)}
        >
          <p>{t('orders.cancel_confirm')}</p>
          <div className="sf-wl-confirm">
            <button
              type="button"
              className="sf-acc-secondary"
              onClick={() => setCancelling(false)}
            >
              {t('storefront.wishlist_cancel')}
            </button>
            <button
              type="button"
              className="sf-acc-danger is-filled"
              onClick={() =>
                router.post(
                  `/account/orders/${order.id}/cancel`,
                  {},
                  {
                    preserveScroll: true,
                    onFinish: () => setCancelling(false),
                  },
                )
              }
            >
              {t('orders.cancel_button')}
            </button>
          </div>
        </Dialog>
      )}
    </>
  );
}
