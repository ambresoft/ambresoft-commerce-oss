import { useState, type CSSProperties, type FormEvent } from 'react';
import { useTranslation } from '../../../lib/useTranslation';
import type { PublicBlock } from '../../types';
import { AccountMenu } from './AccountParts';
import Dialog from './Dialog';
import { Product } from './parts';
import { wishlistRequest } from './WishlistProvider';

type Dialogs = 'new' | 'rename' | 'delete' | null;

/** The wishlist page's main content block: the customer's lists and the saved products of the chosen list. */
export default function Wishlist({ block }: { block: PublicBlock }) {
  const t = useTranslation();
  const data = block.content.wishlist;
  const [dialog, setDialog] = useState<Dialogs>(null);
  const [name, setName] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  if (!data) return null;
  const columns = Math.min(8, Math.max(1, block.content.per_row ?? 5));
  const current =
    data.lists.find((l) => l.id === data.current) ?? data.lists[0];
  const title = (list: { name: string | null }) =>
    list.name ?? t('storefront.wishlist_all');
  const visit = (id: string | null) => {
    window.location.href = id ? `/wishlist?list=${id}` : '/wishlist';
  };
  const run = async (
    action: () => ReturnType<typeof wishlistRequest>,
    after: (created: string | null) => void,
  ) => {
    setBusy(true);
    const result = await action();
    setBusy(false);
    if (result.ok) after(result.data.created);
    else setError(result.message || t('storefront.wishlist_error'));
  };
  const open = (kind: Dialogs) => {
    setError('');
    setName(kind === 'rename' ? (current.name ?? '') : '');
    setDialog(kind);
  };
  const submit = (e: FormEvent) => {
    e.preventDefault();
    if (dialog === 'new')
      void run(
        () => wishlistRequest('POST', '/wishlists', { name }),
        (created) => visit(created),
      );
    else if (dialog === 'rename')
      void run(
        () => wishlistRequest('PATCH', `/wishlists/${current.id}`, { name }),
        () => visit(current.id),
      );
  };
  const display = data.display;
  const style = {
    '--sf-wl-cols': columns,
    '--sf-w': `${display.image_width ?? 300}px`,
    '--sf-h': `${display.image_height ?? 260}px`,
  } as CSSProperties;
  return (
    <div className="sf-account">
      <h1>{t('account.menu_title')}</h1>
      <div className="sf-acc-layout">
        <AccountMenu current="wishlist" wishlist />
        <div className="sf-acc-panel">
          <div className="sf-wishlist">
            <h2>{t('storefront.wishlist_title')}</h2>
            <nav
              className="sf-wl-tabs"
              aria-label={t('storefront.wishlist_title')}
            >
              {data.lists.map((list) => (
                <a
                  key={list.id}
                  href={
                    list.default ? '/wishlist' : `/wishlist?list=${list.id}`
                  }
                  aria-current={list.id === current.id ? 'page' : undefined}
                  className={list.id === current.id ? 'is-current' : undefined}
                >
                  {title(list)} <span>({list.count})</span>
                </a>
              ))}
              <button
                type="button"
                className="sf-wl-add-list"
                onClick={() => open('new')}
              >
                + {t('storefront.wishlist_new_list')}
              </button>
            </nav>
            {!current.default && (
              <p className="sf-wl-actions">
                <button type="button" onClick={() => open('rename')}>
                  {t('storefront.wishlist_rename')}
                </button>
                <button type="button" onClick={() => open('delete')}>
                  {t('storefront.wishlist_delete')}
                </button>
              </p>
            )}
            {data.items.length === 0 ? (
              <p className="sf-pdp-empty">{t('storefront.wishlist_empty')}</p>
            ) : (
              <div
                className="sf-wishlist-grid sf-sized"
                style={style}
                data-testid="wishlist-grid"
              >
                {data.items.map((item) => (
                  <Product
                    key={item.id}
                    item={item}
                    display={display}
                    format={data.currency_format}
                  />
                ))}
              </div>
            )}
            {(dialog === 'new' || dialog === 'rename') && (
              <Dialog
                title={t(
                  dialog === 'new'
                    ? 'storefront.wishlist_new_list'
                    : 'storefront.wishlist_rename',
                )}
                onClose={() => setDialog(null)}
              >
                <form className="sf-wl-create" onSubmit={submit}>
                  {error && (
                    <p role="alert" className="sf-pdp-form-error">
                      {error}
                    </p>
                  )}
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
                    {t(
                      dialog === 'new'
                        ? 'storefront.wishlist_create'
                        : 'storefront.wishlist_save',
                    )}
                  </button>
                </form>
              </Dialog>
            )}
            {dialog === 'delete' && (
              <Dialog
                title={t('storefront.wishlist_delete')}
                onClose={() => setDialog(null)}
              >
                <p>
                  {t('storefront.wishlist_delete_help', {
                    name: title(current),
                  })}
                </p>
                {error && (
                  <p role="alert" className="sf-pdp-form-error">
                    {error}
                  </p>
                )}
                <div className="sf-wl-confirm">
                  <button
                    type="button"
                    className="sf-pdp-outline"
                    onClick={() => setDialog(null)}
                  >
                    {t('storefront.wishlist_cancel')}
                  </button>
                  <button
                    type="button"
                    className="sf-pdp-add"
                    disabled={busy}
                    onClick={() =>
                      void run(
                        () =>
                          wishlistRequest('DELETE', `/wishlists/${current.id}`),
                        () => visit(null),
                      )
                    }
                  >
                    {t('storefront.wishlist_delete')}
                  </button>
                </div>
              </Dialog>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
