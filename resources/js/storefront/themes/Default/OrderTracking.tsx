import { useTranslation } from '../../../lib/useTranslation';
import type { PublicBlock } from '../../types';

const date = (iso: string) =>
  new Date(iso).toLocaleString(document.documentElement.lang || undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
  });

/**
 * Order tracking: a number goes in (a plain GET form, so it works without scripts); the server answers with the
 * order's status and history, a request to sign in (orders of registered customers), or "not found".
 */
export default function OrderTracking({ block }: { block: PublicBlock }) {
  const t = useTranslation();
  const tracking = block.content.tracking;
  if (!tracking) return null;
  const order = tracking.order;
  return (
    <div className="sf-track">
      <h1>{t('storefront.tracking_title')}</h1>
      <p className="sf-track-intro">{t('storefront.tracking_intro')}</p>
      <form className="sf-track-form" method="get" action="/order-status">
        <label htmlFor="sf-track-number">
          {t('storefront.tracking_number')}
        </label>
        <div>
          <input
            id="sf-track-number"
            name="number"
            defaultValue={tracking.number}
            required
            maxLength={40}
            pattern="[A-Za-z0-9\-]+"
            autoComplete="off"
            inputMode="text"
            placeholder="100001"
          />
          <button type="submit">{t('storefront.tracking_submit')}</button>
        </div>
      </form>
      {tracking.state === 'not_found' && (
        <p className="sf-track-message is-error" role="alert">
          {t('storefront.tracking_not_found')}
        </p>
      )}
      {tracking.state === 'login' && (
        <div className="sf-track-message" role="status">
          <p>{t('storefront.tracking_login_needed')}</p>
          <a className="sf-track-login" href="/login">
            {t('storefront.tracking_login')}
          </a>
        </div>
      )}
      {tracking.state === 'found' && order && (
        <section className="sf-track-card" aria-labelledby="sf-track-heading">
          <header>
            <div>
              <h2 id="sf-track-heading">
                {t('storefront.tracking_order', { number: order.number })}
              </h2>
              <p>
                {t('storefront.tracking_placed', {
                  date: date(order.created_at),
                })}
              </p>
            </div>
            {order.status && (
              <span
                className="sf-track-status"
                style={{ backgroundColor: order.status.color }}
              >
                {order.status.name}
              </span>
            )}
          </header>
          {order.timeline.length > 0 && (
            <>
              <h3>{t('storefront.tracking_history')}</h3>
              <ol className="sf-track-timeline">
                {[...order.timeline].reverse().map((step, n) => (
                  <li key={n} className={n === 0 ? 'is-current' : undefined}>
                    <span
                      className="sf-track-dot"
                      style={{ backgroundColor: step.status?.color }}
                      aria-hidden="true"
                    />
                    <strong>{step.status?.name}</strong>
                    <time dateTime={step.at}>{date(step.at)}</time>
                  </li>
                ))}
              </ol>
            </>
          )}
          {order.account_url && (
            <a className="sf-track-account" href={order.account_url}>
              {t('storefront.tracking_view_order')}
            </a>
          )}
        </section>
      )}
    </div>
  );
}
