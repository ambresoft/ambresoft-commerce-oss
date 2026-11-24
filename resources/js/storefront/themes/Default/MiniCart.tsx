import { useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { useTranslation } from '../../../lib/useTranslation';
import type { CartLine, CartPageData } from '../../types';
import { cartItem, emitStorefrontEvent } from '../../events';
import { useCart } from './cartContext';
import { CartIcon, NoImage, Picture, TrashIcon } from './parts';

/** Phones always go to the cart page; the mini cart is for wider screens. */
const PHONE = '(max-width: 639px)';

/**
 * The header's cart link with the number of units. On desktop and tablet (unless the header switches it off) it opens
 * a mini cart with the lines and a link to the cart instead of leaving the page.
 */
export default function HeaderCart({
  mini,
  icon,
}: {
  mini: boolean;
  icon?: ReactNode;
}) {
  const t = useTranslation();
  const api = useCart();
  const [open, setOpen] = useState(false);
  const [cart, setCart] = useState<CartPageData | null>(null);
  const [failed, setFailed] = useState(false);
  const box = useRef<HTMLDivElement>(null);
  const button = useRef<HTMLAnchorElement>(null);
  const panelId = useId();
  useEffect(() => {
    if (!open) return;
    const close = (e: PointerEvent) => {
      if (!box.current?.contains(e.target as Node)) setOpen(false);
    };
    const escape = (e: KeyboardEvent) => {
      if (e.key !== 'Escape') return;
      setOpen(false);
      button.current?.focus();
    };
    document.addEventListener('pointerdown', close);
    document.addEventListener('keydown', escape);
    return () => {
      document.removeEventListener('pointerdown', close);
      document.removeEventListener('keydown', escape);
    };
  }, [open]);
  // Read fresh each time it opens: products may have been added on this page since.
  const toggle = async () => {
    if (open) {
      setOpen(false);
      return;
    }
    setOpen(true);
    setFailed(false);
    const reply = await api.peek();
    if (reply.ok) setCart(reply.cart);
    else setFailed(true);
  };
  const remove = async (line: CartLine) => {
    const reply = await api.change('DELETE', line.id);
    if (reply.ok) {
      emitStorefrontEvent({
        type: 'remove_from_cart',
        currency: reply.cart.currency_format.code,
        items: [cartItem(line)],
      });
      setCart(reply.cart);
    } else setFailed(true);
  };
  const count = api.count;
  const label =
    count > 0 ? t('storefront.cart_badge', { count }) : t('storefront.cart');
  const badge = count > 0 && (
    <span className="sf-cart-badge" aria-hidden="true">
      {count > 99 ? '99+' : count}
    </span>
  );
  if (!api.enabled) return null;
  // On the cart page itself the icon only shows the count.
  if (window.location.pathname === '/cart') {
    return (
      <span className="sf-header-cart" role="img" aria-label={label}>
        {icon ?? <CartIcon />}
        {badge}
      </span>
    );
  }
  return (
    <div className="sf-minicart" ref={box}>
      <a
        ref={button}
        href="/cart"
        className="sf-header-cart"
        aria-label={label}
        aria-haspopup={mini ? 'dialog' : undefined}
        aria-expanded={mini ? open : undefined}
        aria-controls={mini ? panelId : undefined}
        onClick={(event) => {
          if (!mini || window.matchMedia(PHONE).matches) return;
          event.preventDefault();
          toggle();
        }}
      >
        {icon ?? <CartIcon />}
        {badge}
      </a>
      {open && (
        <div
          id={panelId}
          className="sf-minicart-panel"
          role="dialog"
          aria-label={t('storefront.cart_heading')}
        >
          <h2>
            {t('storefront.cart_heading')} ({count})
          </h2>
          {failed ? (
            <p className="sf-minicart-note" role="alert">
              {t('storefront.cart_error')}
            </p>
          ) : cart === null ? (
            <p className="sf-minicart-note" aria-busy="true" />
          ) : cart.lines.length === 0 ? (
            <p className="sf-minicart-note">{t('storefront.cart_empty')}</p>
          ) : (
            <ul>
              {cart.lines.map((line) => (
                <li key={line.id}>
                  <a
                    className="sf-minicart-picture"
                    href={line.url ?? '/cart'}
                    tabIndex={-1}
                    aria-hidden="true"
                  >
                    {line.image ? (
                      <Picture image={line.image} alt="" />
                    ) : (
                      <NoImage />
                    )}
                  </a>
                  <div className="sf-minicart-info">
                    <a className="sf-minicart-name" href={line.url ?? '/cart'}>
                      {line.name}
                    </a>
                    <span className="sf-minicart-qty">
                      {t('storefront.quantity')}:{' '}
                      <strong>{line.quantity}</strong>
                    </span>
                  </div>
                  <button
                    type="button"
                    className="sf-cart-remove"
                    aria-label={t('storefront.cart_remove_item', {
                      name: line.name ?? '',
                    })}
                    onClick={() => remove(line)}
                  >
                    <TrashIcon />
                  </button>
                </li>
              ))}
            </ul>
          )}
          <div className="sf-minicart-foot">
            <a className="sf-minicart-go" href="/cart">
              {t('storefront.cart_view')}
            </a>
          </div>
        </div>
      )}
    </div>
  );
}
