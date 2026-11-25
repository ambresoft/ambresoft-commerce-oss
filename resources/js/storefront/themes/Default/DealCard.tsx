import { useState } from 'react';
import { createPortal } from 'react-dom';
import Dialog from './Dialog';
import { usePage } from '@inertiajs/react';
import { useTranslation } from '../../../lib/useTranslation';
import type { PublicDisplay, PublicItem } from '../../types';

export function BlockTitleIcon({
  name,
}: {
  name?: PublicDisplay['title_icon'];
}) {
  if (!name || name === 'none') return null;
  const paths = {
    ticket:
      'M3 5h18v5a2 2 0 0 0 0 4v5H3v-5a2 2 0 0 0 0-4V5Zm13 0v3m0 3v2m0 3v3',
    gift: 'M3 8h18v4H3V8Zm2 4v9h14v-9M12 8v13M12 8H8a3 3 0 1 1 3-3l1 3Zm0 0h4a3 3 0 1 0-3-3l-1 3Z',
    trophy:
      'M7 3h10v6a5 5 0 0 1-10 0V3Zm0 2H3v3a4 4 0 0 0 4 4m10-7h4v3a4 4 0 0 1-4 4m-5 2v7m-5 0h10',
    percent:
      'm5 19 14-14M9 6a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm12 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',
  };
  return (
    <svg
      className="sf-title-icon"
      width="28"
      height="28"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.7"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
    >
      <path d={paths[name]} />
    </svg>
  );
}

export function DealCard({
  item,
  shadow,
}: {
  item: PublicItem;
  shadow?: boolean;
}) {
  const t = useTranslation();
  const [readingTerms, setReadingTerms] = useState(false);
  const { i18n } = usePage<{ i18n: { locale: string } }>().props;
  const deal = item.deal;
  if (!deal) return null;
  const terms = deal.terms.map((term) => term.trim()).filter(Boolean);
  const hasTerms = terms.length > 0 || deal.description.trim() !== '';
  const expiry = deal.expires_on
    ? new Intl.DateTimeFormat(i18n.locale.replace('_', '-'), {
        dateStyle: 'medium',
        timeZone: 'UTC',
      }).format(new Date(`${deal.expires_on}T00:00:00Z`))
    : null;
  return (
    <article className={`sf-deal ${shadow ? 'sf-card-shadow' : ''}`}>
      <div
        className={`sf-deal-benefit ${deal.benefit.length > 10 ? 'sf-deal-benefit-long' : deal.benefit.length > 5 ? 'sf-deal-benefit-compact' : ''}`}
      >
        <strong>{deal.benefit}</strong>
        <span>{t(`storefront.deal_${deal.kind}`)}</span>
      </div>
      <div className="sf-deal-content">
        <p className="sf-deal-expiry">
          {deal.expires_on ? (
            <time dateTime={deal.expires_on}>
              {t('storefront.deal_until', { date: expiry! })}
            </time>
          ) : (
            t('storefront.deal_no_expiry')
          )}
        </p>
        <h3>{item.name}</h3>
        {deal.description && (
          <p className="sf-deal-description">{deal.description}</p>
        )}
        {deal.code && <code className="sf-deal-code">{deal.code}</code>}
        {hasTerms && (
          <button
            type="button"
            className="sf-deal-terms"
            aria-haspopup="dialog"
            onClick={() => setReadingTerms(true)}
          >
            {t('storefront.deal_terms')}
          </button>
        )}
        {readingTerms &&
          hasTerms &&
          createPortal(
            <div onPointerDown={(e) => e.stopPropagation()}>
              <Dialog
                title={t('storefront.deal_terms')}
                className="sf-deal-dialog"
                onClose={() => setReadingTerms(false)}
              >
                <div className="sf-deal-terms-body">
                  <h3>
                    {item.name}
                    {deal.code ? ` · ${deal.code}` : ''}
                  </h3>
                  {deal.description.trim() && <p>{deal.description}</p>}
                  {terms.length > 0 && (
                    <ul>
                      {terms.map((term, index) => (
                        <li key={index}>{term}</li>
                      ))}
                    </ul>
                  )}
                  <p className="sf-deal-terms-note">
                    {t('storefront.deal_checkout_terms')}
                  </p>
                </div>
              </Dialog>
            </div>,
            document.querySelector<HTMLElement>('.sf-default') ?? document.body,
          )}
      </div>
    </article>
  );
}
