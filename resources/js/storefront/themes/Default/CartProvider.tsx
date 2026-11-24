import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react';
import { useTranslation } from '../../../lib/useTranslation';
import { CartContext, type CartApi, type CartReply } from './cartContext';
import { cartItem, emitStorefrontEvent } from '../../events';

const csrf = () =>
  decodeURIComponent(
    document.cookie
      .split('; ')
      .find((row) => row.startsWith('XSRF-TOKEN='))
      ?.slice('XSRF-TOKEN='.length) ?? '',
  );

/** One call to the cart; a refused change returns the server's message. */
async function cartRequest(
  method: 'GET' | 'POST' | 'PATCH' | 'DELETE',
  url: string,
  body?: Record<string, unknown>,
): Promise<CartReply> {
  try {
    const response = await fetch(url, {
      method,
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-XSRF-TOKEN': csrf(),
      },
      body: body ? JSON.stringify(body) : undefined,
    });
    const json = await response.json().catch(() => ({}));
    if (!response.ok) {
      const first = Object.values(json.errors ?? {})[0] as string[] | undefined;
      return { ok: false, message: first?.[0] ?? '' };
    }
    return { ok: true, cart: json.cart, count: json.count };
  } catch {
    return { ok: false, message: '' };
  }
}

type Toast = { tone: 'added' | 'error'; text: string; name?: string };

/** Keeps the cart count for the whole page and shows a short notice after each addition. */
export default function CartProvider({
  count: initial,
  enabled = true,
  children,
}: {
  count: number;
  enabled?: boolean;
  children: ReactNode;
}) {
  const t = useTranslation();
  const [count, setCount] = useState(initial);
  const [toast, setToast] = useState<Toast | null>(null);
  const timer = useRef<number | undefined>(undefined);
  useEffect(() => () => window.clearTimeout(timer.current), []);
  const show = useCallback((next: Toast) => {
    setToast(next);
    window.clearTimeout(timer.current);
    timer.current = window.setTimeout(() => setToast(null), 4000);
  }, []);
  const add = useCallback<CartApi['add']>(
    async (item, quantity = 1) => {
      const reply = await cartRequest(
        'POST',
        item.addPackage ? '/cart/package' : '/cart/items',
        {
          product_id: Number(item.id),
          quantity,
        },
      );
      if (reply.ok) {
        setCount(reply.count);
        const line = reply.cart.lines.find((l) => l.id === item.id);
        emitStorefrontEvent({
          type: 'add_to_cart',
          currency: reply.cart.currency_format.code,
          items: [
            line
              ? cartItem(line, quantity)
              : { id: item.id, name: item.name, quantity },
          ],
        });
        show({
          tone: 'added',
          text: t('storefront.cart_added'),
          name: item.name,
        });
      } else {
        show({
          tone: 'error',
          text: reply.message || t('storefront.cart_error'),
        });
      }
      return reply.ok;
    },
    [show, t],
  );
  const change = useCallback<CartApi['change']>(
    async (method, product, quantity) => {
      const reply = await cartRequest(
        method,
        `/cart/items/${product}`,
        method === 'PATCH' ? { quantity } : undefined,
      );
      if (reply.ok) setCount(reply.count);
      return reply;
    },
    [],
  );
  const clear = useCallback<CartApi['clear']>(async () => {
    const reply = await cartRequest('DELETE', '/cart/items');
    if (reply.ok) setCount(reply.count);
    return reply;
  }, []);
  const peek = useCallback<CartApi['peek']>(async () => {
    const reply = await cartRequest('GET', '/cart/items');
    if (reply.ok) setCount(reply.count);
    return reply;
  }, []);
  const code = useCallback<CartApi['code']>(
    (method, value) =>
      method === 'POST'
        ? cartRequest('POST', '/cart/codes', { code: value })
        : cartRequest('DELETE', `/cart/codes/${value}`),
    [],
  );
  const api = useMemo(
    () => ({ enabled, count, add, change, clear, peek, code }),
    [enabled, count, add, change, clear, peek, code],
  );
  return (
    <CartContext.Provider value={api}>
      {children}
      <div className="sf-cart-toast-zone" role="status" aria-live="polite">
        {toast && (
          <div className={`sf-cart-toast is-${toast.tone}`}>
            <span className="sf-cart-toast-icon" aria-hidden="true">
              {toast.tone === 'added' ? '✓' : '!'}
            </span>
            <span className="sf-cart-toast-text">
              <strong>{toast.text}</strong>
              {toast.name && <span>{toast.name}</span>}
            </span>
            {toast.tone === 'added' && (
              <a href="/cart" className="sf-cart-toast-link">
                {t('storefront.cart_view')}
              </a>
            )}
            <button
              type="button"
              className="sf-cart-toast-close"
              aria-label={t('storefront.close')}
              onClick={() => setToast(null)}
            >
              ×
            </button>
          </div>
        )}
      </div>
    </CartContext.Provider>
  );
}
