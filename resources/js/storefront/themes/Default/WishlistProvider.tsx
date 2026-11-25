import { router } from '@inertiajs/react';
import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
  type FormEvent,
  type ReactNode,
} from 'react';
import { useTranslation } from '../../../lib/useTranslation';
import type { PublicItem, WishlistSummary } from '../../types';
import { emitStorefrontEvent, type StorefrontEvent } from '../../events';
import Dialog from './Dialog';
import { WishlistContext, type WishlistApi } from './wishlistContext';

const csrf = () =>
  decodeURIComponent(
    document.cookie
      .split('; ')
      .find((row) => row.startsWith('XSRF-TOKEN='))
      ?.slice('XSRF-TOKEN='.length) ?? '',
  );

type Reply = {
  lists: WishlistSummary[];
  favorite: boolean;
  /** Id of a list created by this call. */
  created: string | null;
};

/** One call to the wishlist API; a signed-out session goes to sign-in, a refused change returns its message. */
const wishlistEvent = (item: PublicItem): StorefrontEvent => ({
  type: 'add_to_wishlist',
  currency: item.currency,
  items: [
    {
      id: item.id,
      name: item.name,
      sku: item.sku,
      price: item.price,
      quantity: 1,
    },
  ],
});

export async function wishlistRequest(
  method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE',
  url: string,
  body?: Record<string, unknown>,
): Promise<{ ok: true; data: Reply } | { ok: false; message: string }> {
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
  if (response.status === 401 || response.status === 419) {
    window.location.href = '/login';
    return { ok: false, message: '' };
  }
  const json = await response.json().catch(() => ({}));
  if (!response.ok) {
    const first = Object.values(json.errors ?? {})[0] as string[] | undefined;
    return { ok: false, message: first?.[0] ?? json.message ?? '' };
  }
  return { ok: true, data: json as Reply };
}

function LockIcon() {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      width="16"
      height="16"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <rect x="5" y="11" width="14" height="9" rx="2" />
      <path d="M8 11V8a4 4 0 0 1 8 0v3" />
    </svg>
  );
}

function ListRow({
  list,
  busy,
  onToggle,
}: {
  list: WishlistSummary;
  busy: boolean;
  onToggle: () => void;
}) {
  const t = useTranslation();
  return (
    <button
      type="button"
      className="sf-wl-row"
      disabled={busy}
      aria-pressed={list.has}
      onClick={onToggle}
    >
      <span className="sf-wl-thumb" aria-hidden="true">
        {list.thumb && <img src={list.thumb} alt="" loading="lazy" />}
      </span>
      <span className="sf-wl-name">
        {list.default ? t('storefront.wishlist_all') : list.name}
        <small>
          <LockIcon /> {t('storefront.wishlist_private')}
        </small>
      </span>
      <span className={`sf-wl-state ${list.has ? 'is-in' : ''}`}>
        <span className="sf-visually-hidden">
          {t(
            list.has
              ? 'storefront.wishlist_in_list'
              : 'storefront.wishlist_add',
          )}
        </span>
        {list.has ? (
          <svg
            aria-hidden="true"
            viewBox="0 0 24 24"
            width="26"
            height="26"
            fill="none"
            stroke="currentColor"
            strokeWidth="2.4"
            strokeLinecap="round"
            strokeLinejoin="round"
          >
            <path d="m5 12.5 4.5 4.5L19 7.5" />
          </svg>
        ) : (
          <svg
            aria-hidden="true"
            viewBox="0 0 24 24"
            width="30"
            height="30"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.6"
            strokeLinecap="round"
          >
            <circle cx="12" cy="12" r="9.5" />
            <path d="M12 8v8M8 12h8" />
          </svg>
        )}
      </span>
    </button>
  );
}

/** The "add to list" window: the general list, a way to start a new list, and the named lists. */
function ListWindow({
  item,
  signedIn,
  onChange,
  onClose,
}: {
  item: PublicItem;
  signedIn: boolean;
  onChange: (favourite: boolean) => void;
  onClose: () => void;
}) {
  const t = useTranslation();
  const [lists, setLists] = useState<WishlistSummary[] | null>(null);
  const [creating, setCreating] = useState(false);
  const [name, setName] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const apply = useCallback(
    (result: Awaited<ReturnType<typeof wishlistRequest>>) => {
      if (result.ok) {
        setLists(result.data.lists);
        setError('');
        onChange(result.data.favorite);
      } else setError(result.message || t('storefront.wishlist_error'));
    },
    [onChange, t],
  );
  // Load the lists once when the window opens (the latest callbacks are read through a ref, so re-renders never refetch).
  const latest = useRef(apply);
  useEffect(() => {
    latest.current = apply;
  });
  useEffect(() => {
    if (signedIn)
      void wishlistRequest('GET', `/wishlists/product/${item.id}`).then((r) =>
        latest.current(r),
      );
  }, [signedIn, item.id]);
  const toggle = async (list: WishlistSummary) => {
    setBusy(true);
    const result = await wishlistRequest(
      list.has ? 'DELETE' : 'PUT',
      `/wishlists/${list.id}/products/${item.id}`,
    );
    if (result.ok && !list.has) emitStorefrontEvent(wishlistEvent(item));
    apply(result);
    setBusy(false);
  };
  const create = async (e: FormEvent) => {
    e.preventDefault();
    setBusy(true);
    const result = await wishlistRequest('POST', '/wishlists', {
      name,
      product_id: item.id,
    });
    setBusy(false);
    apply(result);
    if (result.ok) {
      emitStorefrontEvent(wishlistEvent(item));
      setCreating(false);
      setName('');
    }
  };
  return (
    <Dialog
      title={t('storefront.wishlist_add_to_list')}
      onClose={onClose}
      className="sf-wl-dialog"
    >
      {error && (
        <p role="alert" className="sf-pdp-form-error">
          {error}
        </p>
      )}
      <ul className="sf-wl-lists">
        {(lists ?? []).slice(0, 1).map((list) => (
          <li key={list.id}>
            <ListRow list={list} busy={busy} onToggle={() => toggle(list)} />
          </li>
        ))}
        <li>
          {creating ? (
            <form className="sf-wl-create" onSubmit={create}>
              <label>
                <span className="sf-visually-hidden">
                  {t('storefront.wishlist_name')}
                </span>
                <input
                  autoFocus
                  value={name}
                  maxLength={60}
                  placeholder={t('storefront.wishlist_name')}
                  onChange={(e) => setName(e.target.value)}
                />
              </label>
              <button
                type="submit"
                className="sf-pdp-add"
                disabled={busy || name.trim() === ''}
              >
                {t('storefront.wishlist_create')}
              </button>
            </form>
          ) : (
            <button
              type="button"
              className="sf-wl-row sf-wl-new"
              onClick={() => setCreating(true)}
            >
              <span className="sf-wl-thumb sf-wl-thumb-new" aria-hidden="true">
                <svg
                  viewBox="0 0 24 24"
                  width="26"
                  height="26"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="1.8"
                  strokeLinecap="round"
                >
                  <path d="M4 7h11M4 12h11M4 17h6M18 14v6M15 17h6" />
                </svg>
              </span>
              <span className="sf-wl-name">
                {t('storefront.wishlist_new_list')}
              </span>
            </button>
          )}
        </li>
        {(lists ?? []).slice(1).map((list) => (
          <li key={list.id}>
            <ListRow list={list} busy={busy} onToggle={() => toggle(list)} />
          </li>
        ))}
      </ul>
    </Dialog>
  );
}

/** Keeps the hearts of a storefront page and the "add to list" window in step. */
export default function WishlistProvider({
  signedIn,
  enabled = true,
  children,
}: {
  signedIn: boolean;
  enabled?: boolean;
  children: ReactNode;
}) {
  const [target, setTarget] = useState<PublicItem | null>(null);
  const [saved, setSaved] = useState<Record<string, boolean>>({});
  const [changed, setChanged] = useState(false);
  const api = useMemo<WishlistApi>(
    () => ({
      enabled,
      isFavourite: (item) => saved[item.id] ?? !!item.favorite,
      open: (item) => {
        if (!signedIn) {
          window.location.href = '/login';
          return;
        }
        setChanged(false);
        setTarget(item);
      },
    }),
    [saved, signedIn, enabled],
  );
  const close = () => {
    setTarget(null);
    // On the wishlist page the grid follows what was changed.
    if (changed && window.location.pathname === '/wishlist')
      router.reload({ only: ['storefront'] });
  };
  return (
    <WishlistContext.Provider value={api}>
      {children}
      {target && (
        <ListWindow
          key={target.id}
          item={target}
          signedIn={signedIn}
          onChange={(favourite) => {
            setSaved((s) => ({ ...s, [target.id]: favourite }));
            setChanged(true);
          }}
          onClose={close}
        />
      )}
    </WishlistContext.Provider>
  );
}
