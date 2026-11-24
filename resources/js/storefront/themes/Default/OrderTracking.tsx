import { useState, type CSSProperties, type ReactNode } from 'react';
import { useTranslation } from '../../../lib/useTranslation';
import type { PublicBlock } from '../../types';

type Tracking = NonNullable<PublicBlock['content']['tracking']>;
type Order = NonNullable<Tracking['order']>;

const locale = () => document.documentElement.lang || undefined;

const date = (iso: string) =>
  new Date(iso).toLocaleString(locale(), {
    dateStyle: 'medium',
    timeStyle: 'short',
  });

/** "2 days ago", "in 3 hours": the largest unit that fits, in the page's language. */
function relative(iso: string, now = Date.now()): string {
  const seconds = Math.round((new Date(iso).getTime() - now) / 1000);
  const units: [Intl.RelativeTimeFormatUnit, number][] = [
    ['year', 31536000],
    ['month', 2592000],
    ['week', 604800],
    ['day', 86400],
    ['hour', 3600],
    ['minute', 60],
  ];
  const format = new Intl.RelativeTimeFormat(locale(), { numeric: 'auto' });
  for (const [unit, size] of units) {
    if (Math.abs(seconds) >= size)
      return format.format(Math.round(seconds / size), unit);
  }
  return format.format(0, 'minute');
}

function Icon({
  name,
}: {
  name:
    'box' | 'search' | 'alert' | 'lock' | 'mail' | 'user' | 'copy' | 'check';
}) {
  const paths: Record<typeof name, ReactNode> = {
    box: (
      <>
        <path d="M21 8 12 3 3 8v8l9 5 9-5V8Z" />
        <path d="m3 8 9 5 9-5M12 13v8" />
        <path d="m7.5 5.5 9 5" />
      </>
    ),
    search: (
      <>
        <circle cx="11" cy="11" r="7" />
        <path d="m20 20-3.5-3.5" />
      </>
    ),
    alert: (
      <>
        <circle cx="12" cy="12" r="9" />
        <path d="M12 7.5v5.5M12 16.5h.01" />
      </>
    ),
    lock: (
      <>
        <rect x="4" y="10" width="16" height="11" rx="2.5" />
        <path d="M8 10V7a4 4 0 0 1 8 0v3" />
      </>
    ),
    mail: (
      <>
        <rect x="3" y="5" width="18" height="14" rx="2.5" />
        <path d="m4 7 8 6 8-6" />
      </>
    ),
    user: (
      <>
        <circle cx="12" cy="8" r="4" />
        <path d="M4 21a8 8 0 0 1 16 0" />
      </>
    ),
    copy: (
      <>
        <rect x="8" y="8" width="12" height="12" rx="2.5" />
        <path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2" />
      </>
    ),
    check: <path d="m5 12.5 4.5 4.5L19 7.5" />,
  };
  return (
    <svg
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
    >
      {paths[name]}
    </svg>
  );
}

/** Copies the order number; the confirmation is announced to screen readers. */
function CopyNumber({ number }: { number: string }) {
  const t = useTranslation();
  const [copied, setCopied] = useState(false);
  if (!navigator.clipboard) return null;
  return (
    <button
      type="button"
      className="sf-track-copy"
      onClick={() =>
        navigator.clipboard.writeText(number).then(() => {
          setCopied(true);
          window.setTimeout(() => setCopied(false), 2000);
        })
      }
    >
      <Icon name={copied ? 'check' : 'copy'} />
      <span aria-live="polite">
        {copied
          ? t('storefront.tracking_copied')
          : t('storefront.tracking_copy')}
      </span>
    </button>
  );
}

function StateCard({
  icon,
  tone,
  title,
  text,
  children,
}: {
  icon: 'alert' | 'lock';
  tone: 'error' | 'info';
  title: string;
  text: string;
  children?: ReactNode;
}) {
  return (
    <section
      className={`sf-track-state is-${tone}`}
      role={tone === 'error' ? 'alert' : 'status'}
    >
      <span className="sf-track-state-icon">
        <Icon name={icon} />
      </span>
      <div>
        <h2>{title}</h2>
        <p>{text}</p>
        {children}
      </div>
    </section>
  );
}

function OrderCard({ order }: { order: Order }) {
  const t = useTranslation();
  const history = [...order.timeline].reverse();
  const latest = history[0];
  const accent = order.status?.color ?? 'var(--sf-primary, #05ab7d)';
  return (
    <section className="sf-track-card" aria-labelledby="sf-track-heading">
      <header className="sf-track-card-head">
        <div>
          <div className="sf-track-number-row">
            <h2 id="sf-track-heading">
              {t('storefront.tracking_order', { number: order.number })}
            </h2>
            <CopyNumber number={order.number} />
          </div>
          <p className="sf-track-placed">
            {t('storefront.tracking_placed', { date: date(order.created_at) })}
          </p>
        </div>
      </header>
      {order.status && (
        <div
          className="sf-track-current"
          style={{ '--sf-track-accent': accent } as CSSProperties}
        >
          <span className="sf-track-pulse" aria-hidden="true" />
          <div>
            <p className="sf-track-eyebrow">
              {t('storefront.tracking_current')}
            </p>
            <p className="sf-track-current-name">{order.status.name}</p>
            {latest && (
              <p className="sf-track-updated">
                {t('storefront.tracking_updated', {
                  time: relative(latest.at),
                })}
                {' · '}
                <time dateTime={latest.at}>{date(latest.at)}</time>
              </p>
            )}
          </div>
        </div>
      )}
      {history.length > 0 && (
        <div className="sf-track-history">
          <h3>{t('storefront.tracking_history')}</h3>
          <ol className="sf-track-timeline">
            {history.map((step, n) => (
              <li
                key={n}
                className={n === 0 ? 'is-current' : undefined}
                style={
                  {
                    '--sf-track-step': step.status?.color ?? '#9ca3af',
                  } as CSSProperties
                }
                aria-current={n === 0 ? 'step' : undefined}
              >
                <span className="sf-track-dot" aria-hidden="true" />
                <div>
                  <strong>{step.status?.name}</strong>
                  <span className="sf-track-when">
                    <time dateTime={step.at}>{date(step.at)}</time>
                    <span aria-hidden="true"> · </span>
                    <span>{relative(step.at)}</span>
                  </span>
                </div>
              </li>
            ))}
          </ol>
        </div>
      )}
      <footer className="sf-track-actions">
        {order.account_url && (
          <a className="sf-track-button" href={order.account_url}>
            {t('storefront.tracking_view_order')}
          </a>
        )}
        <a className="sf-track-button is-secondary" href="/">
          {t('storefront.tracking_continue')}
        </a>
      </footer>
    </section>
  );
}

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
      <div className="sf-track-hero">
        <span className="sf-track-hero-icon">
          <Icon name="box" />
        </span>
        <h1>{t('storefront.tracking_title')}</h1>
        <p className="sf-track-intro">{t('storefront.tracking_intro')}</p>
        <form className="sf-track-form" method="get" action="/order-status">
          <label htmlFor="sf-track-number" className="sf-visually-hidden">
            {t('storefront.tracking_number')}
          </label>
          <div className="sf-track-field">
            <Icon name="search" />
            <input
              id="sf-track-number"
              name="number"
              defaultValue={tracking.number}
              required
              maxLength={40}
              pattern="[A-Za-z0-9\-]+"
              autoComplete="off"
              inputMode="text"
              placeholder={t('storefront.tracking_placeholder')}
              aria-describedby="sf-track-hint"
            />
            <button type="submit">{t('storefront.tracking_submit')}</button>
          </div>
          <p id="sf-track-hint" className="sf-track-hint">
            {t('storefront.tracking_hint')}
          </p>
        </form>
      </div>
      {tracking.state === 'not_found' && (
        <StateCard
          icon="alert"
          tone="error"
          title={t('storefront.tracking_not_found')}
          text={t('storefront.tracking_not_found_help')}
        />
      )}
      {tracking.state === 'login' && (
        <StateCard
          icon="lock"
          tone="info"
          title={t('storefront.tracking_login_title')}
          text={t('storefront.tracking_login_needed')}
        >
          <a className="sf-track-button" href="/login">
            {t('storefront.tracking_login')}
          </a>
        </StateCard>
      )}
      {tracking.state === 'found' && order && <OrderCard order={order} />}
      {tracking.state === null && (
        <ul className="sf-track-help">
          <li>
            <span className="sf-track-help-icon">
              <Icon name="mail" />
            </span>
            <div>
              <h2>{t('storefront.tracking_help_email_title')}</h2>
              <p>{t('storefront.tracking_help_email')}</p>
            </div>
          </li>
          <li>
            <span className="sf-track-help-icon">
              <Icon name="user" />
            </span>
            <div>
              <h2>{t('storefront.tracking_help_account_title')}</h2>
              <p>{t('storefront.tracking_help_account')}</p>
              <a href="/account/orders">
                {t('storefront.tracking_help_account_link')}
              </a>
            </div>
          </li>
        </ul>
      )}
    </div>
  );
}
