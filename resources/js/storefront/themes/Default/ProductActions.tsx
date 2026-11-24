import { useEffect, useRef, useState } from 'react';
import { useTranslation } from '../../../lib/useTranslation';
import type { ProductPageData } from '../../types';
import { emitStorefrontEvent } from '../../events';
import { comparisonRequest } from './comparisonApi';
import { useWishlist } from './wishlistContext';

const icon = {
  width: 24,
  height: 24,
  viewBox: '0 0 24 24',
  fill: 'none',
  stroke: 'currentColor',
  strokeWidth: 1.8,
  strokeLinecap: 'round' as const,
  strokeLinejoin: 'round' as const,
  'aria-hidden': true,
};

/** Round buttons beside the main picture: save to a wishlist (when wishlists are on) and share the page. */
export default function ProductActions({
  product,
}: {
  product: ProductPageData;
}) {
  const t = useTranslation();
  const wishlist = useWishlist();
  const saved = wishlist.isFavourite(product);
  const comparison = product.comparison;
  const [compared, setCompared] = useState<string[]>(comparison?.ids ?? []);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState('');
  const [noticeLink, setNoticeLink] = useState(false);
  const timer = useRef<number | undefined>(undefined);
  useEffect(() => () => window.clearTimeout(timer.current), []);
  const say = (message: string, link = false) => {
    setNotice(message);
    setNoticeLink(link);
    window.clearTimeout(timer.current);
    timer.current = window.setTimeout(
      () => {
        setNotice('');
        setNoticeLink(false);
      },
      link ? 5000 : 3200,
    );
  };
  const inComparison = compared.includes(product.id);
  const toggleComparison = async () => {
    if (!comparison || busy) return;
    if (!inComparison && compared.length >= comparison.max) {
      say(t('storefront.comparison_full', { max: comparison.max }));
      return;
    }
    setBusy(true);
    const result = await comparisonRequest(
      inComparison ? 'DELETE' : 'PUT',
      `/comparison/products/${product.id}`,
    );
    setBusy(false);
    if (!result.ok) {
      say(result.message || t('storefront.wishlist_error'));
      return;
    }
    setCompared(result.data.ids);
    if (!inComparison)
      emitStorefrontEvent({
        type: 'add_to_comparison',
        items: [
          { id: product.id, name: product.name, sku: product.sku, quantity: 1 },
        ],
      });
    if (inComparison) say(t('storefront.comparison_removed'));
    else say(t('storefront.comparison_added'), true);
  };
  const share = async () => {
    const url = new URL(product.url, window.location.origin).toString();
    try {
      // The device's own share sheet where there is one; otherwise the link is copied.
      if (typeof navigator.share === 'function') {
        await navigator.share({ title: product.name, url });
        return;
      }
      await navigator.clipboard.writeText(url);
      say(t('storefront.link_copied'));
    } catch (error) {
      // Closing the share sheet is not a failure.
      if ((error as Error)?.name !== 'AbortError')
        say(t('storefront.share_failed'));
    }
  };
  const label = t(
    saved ? 'storefront.wishlist_saved' : 'storefront.add_to_favourites',
  );
  return (
    <div className="sf-pdp-actions">
      {wishlist.enabled && (
        <button
          type="button"
          className={`sf-pdp-action ${saved ? 'is-saved' : ''}`}
          aria-label={label}
          aria-pressed={saved}
          title={label}
          onClick={() => wishlist.open(product)}
        >
          <svg {...icon}>
            <path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z" />
          </svg>
        </button>
      )}
      {comparison && (
        <button
          type="button"
          className={`sf-pdp-action ${inComparison ? 'is-active' : ''}`}
          aria-label={t(
            inComparison
              ? 'storefront.comparison_remove'
              : 'storefront.comparison_add',
          )}
          aria-pressed={inComparison}
          title={t(
            inComparison
              ? 'storefront.comparison_remove'
              : 'storefront.comparison_add',
          )}
          disabled={busy}
          onClick={toggleComparison}
        >
          <svg {...icon}>
            <path d="M4 14h6v6M20 10h-6V4M14 10l7-7M3 21l7-7" />
          </svg>
        </button>
      )}
      <button
        type="button"
        className="sf-pdp-action"
        aria-label={t('storefront.share')}
        title={t('storefront.share')}
        onClick={share}
      >
        <svg {...icon}>
          <circle cx="18" cy="5.5" r="2.5" />
          <circle cx="6" cy="12" r="2.5" />
          <circle cx="18" cy="18.5" r="2.5" />
          <path d="m8.2 10.8 7.6-4M8.2 13.2l7.6 4" />
        </svg>
      </button>
      <p className="sf-pdp-action-note" role="status">
        {notice}
        {noticeLink && (
          <a href="/comparison">{t('storefront.comparison_view')}</a>
        )}
      </p>
    </div>
  );
}
