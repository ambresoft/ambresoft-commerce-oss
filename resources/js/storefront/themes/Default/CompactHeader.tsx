import HeaderAccountMenu from './HeaderAccountMenu';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { useTranslation } from '../../../lib/useTranslation';
import type { PublicBlock, PublicMenuNode } from '../../types';
import { LanguageSwitcher } from './Menu';
import HeaderCart from './MiniCart';
import CallRequest from './CallRequest';
import AddonPageLinks from '../../components/AddonPageLinks';
import HeaderSearch from './HeaderSearch';
import { useWishlist } from './wishlistContext';
import { Picture } from './parts';

const ICON_ORDER = ['language', 'account', 'call', 'favorites', 'cart'];
const path = (d: string) => <path d={d} />;
function Glyph({
  name,
  size = 22,
}: {
  name:
    'search' | 'menu' | 'user' | 'heart' | 'close' | 'next' | 'back' | 'phone';
  size?: number;
}) {
  const shapes: Record<typeof name, ReactNode> = {
    search: [path('M11 4a7 7 0 1 0 0 14 7 7 0 0 0 0-14Z'), path('m20 20-4-4')],
    menu: [path('M4 7h16'), path('M4 12h16'), path('M4 17h16')],
    user: [
      path('M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z'),
      path('M4 20a8 8 0 0 1 16 0'),
    ],
    heart: path(
      'M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10Z',
    ),
    close: [path('M6 6l12 12'), path('M18 6 6 18')],
    next: path('m9 6 6 6-6 6'),
    back: path('m15 6-6 6 6 6'),
    phone: path(
      'M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A15 15 0 0 1 3 6a2 2 0 0 1 2-2Z',
    ),
  };
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      width={size}
      height={size}
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      {shapes[name]}
    </svg>
  );
}

function Thumb({ node }: { node: PublicMenuNode }) {
  return node.image ? (
    <span className="sf-cdrawer-thumb">
      <Picture image={node.image} alt="" />
    </span>
  ) : null;
}

/**
 * The menu drawer: top-level items on the left (with their first sub-items as a hint), the chosen item's sub-items on
 * the right. On phones one column at a time, with a way back.
 */
function MenuDrawer({
  items,
  onClose,
}: {
  items: PublicMenuNode[];
  onClose: () => void;
}) {
  const t = useTranslation();
  // The open path on the right: a top item, then each sub-item opened inside it, down to the last level.
  const first = items.find((i) => i.children.length > 0);
  const [trail, setTrail] = useState<PublicMenuNode[]>(first ? [first] : []);
  const active = trail[trail.length - 1] ?? null;
  const [drilled, setDrilled] = useState(false);
  const sub = useRef<HTMLDivElement>(null);
  const show = (path: PublicMenuNode[]) => {
    setTrail(path);
    if (sub.current) sub.current.scrollTop = 0;
  };
  const close = useRef<HTMLButtonElement>(null);
  useEffect(() => {
    close.current?.focus();
    const escape = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
    document.addEventListener('keydown', escape);
    const overflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', escape);
      document.body.style.overflow = overflow;
    };
  }, [onClose]);
  return (
    <div
      className="sf-cdrawer"
      role="dialog"
      aria-modal="true"
      aria-label={t('storefront.menu')}
    >
      <button
        type="button"
        className="sf-cdrawer-backdrop"
        aria-label={t('storefront.close')}
        tabIndex={-1}
        onClick={onClose}
      />
      <div className={`sf-cdrawer-panel ${drilled ? 'is-drilled' : ''}`}>
        <div className="sf-cdrawer-main">
          <button
            ref={close}
            type="button"
            className="sf-cdrawer-close"
            aria-label={t('storefront.close')}
            onClick={onClose}
          >
            <Glyph name="close" size={26} />
          </button>
          <ul>
            {items.map((item) => {
              const open = item.children.length > 0;
              const body = (
                <>
                  <Thumb node={item} />
                  <span className="sf-cdrawer-text">
                    <strong>{item.name}</strong>
                    {open && (
                      <small>
                        {item.children
                          .slice(0, 4)
                          .map((c) => c.name)
                          .join(', ')}
                      </small>
                    )}
                  </span>
                  {open && <Glyph name="next" size={20} />}
                </>
              );
              return (
                <li key={item.id}>
                  {open ? (
                    <button
                      type="button"
                      className={`sf-cdrawer-item ${trail[0]?.id === item.id ? 'is-active' : ''}`}
                      aria-expanded={trail[0]?.id === item.id}
                      onMouseEnter={() =>
                        window.matchMedia('(hover: hover)').matches &&
                        trail.length <= 1 &&
                        show([item])
                      }
                      onClick={() => {
                        show([item]);
                        setDrilled(true);
                      }}
                    >
                      {body}
                    </button>
                  ) : (
                    <a
                      className="sf-cdrawer-item"
                      href={item.url ?? '#'}
                      target={item.new_window ? '_blank' : undefined}
                      rel={item.new_window ? 'noopener' : undefined}
                    >
                      {body}
                    </a>
                  )}
                </li>
              );
            })}
          </ul>
        </div>
        {active && (
          <div className="sf-cdrawer-sub" ref={sub}>
            <button
              type="button"
              className={`sf-cdrawer-back ${trail.length > 1 ? 'is-deep' : ''}`}
              onClick={() =>
                trail.length > 1 ? show(trail.slice(0, -1)) : setDrilled(false)
              }
            >
              <Glyph name="back" size={20} />
              {t('storefront.back')}
            </button>
            <h2>{active.name}</h2>
            <ul>
              {active.url && (
                <li>
                  <a
                    className="sf-cdrawer-item sf-cdrawer-all"
                    href={active.url}
                  >
                    <Thumb node={active} />
                    <span className="sf-cdrawer-text">
                      <strong>{t('storefront.view_all')}</strong>
                    </span>
                  </a>
                </li>
              )}
              {active.children.map((child) => (
                <li key={child.id}>
                  {child.children.length > 0 ? (
                    // A sub-item with its own sub-items opens them here instead of leaving the drawer.
                    <button
                      type="button"
                      className="sf-cdrawer-item"
                      onClick={() => show([...trail, child])}
                    >
                      <Thumb node={child} />
                      <span className="sf-cdrawer-text">{child.name}</span>
                      <Glyph name="next" size={18} />
                    </button>
                  ) : (
                    <a
                      className="sf-cdrawer-item"
                      href={child.url ?? '#'}
                      target={child.new_window ? '_blank' : undefined}
                      rel={child.new_window ? 'noopener' : undefined}
                    >
                      <Thumb node={child} />
                      <span className="sf-cdrawer-text">{child.name}</span>
                    </a>
                  )}
                </li>
              ))}
            </ul>
          </div>
        )}
      </div>
    </div>
  );
}

/**
 * The compact header (header block, "Compact" style): logo, a search field with the menu button inside, the language
 * and the account, favorites and cart icons on one line, each following the same block settings as the classic header.
 */
export default function CompactHeader({ block }: { block: PublicBlock }) {
  const t = useTranslation();
  const d = block.content.display ?? {};
  const wishlist = useWishlist();
  const [open, setOpen] = useState(false);
  const items = block.content.drawer ?? [];
  const languages = block.content.languages ?? [];
  const favourites = d.show_favorites !== false && wishlist.enabled;
  const [calling, setCalling] = useState(false);
  const callForm =
    d.show_call_request === true ? block.content.call_request_form : null;
  // The merchant's icon order; anything missing (an older setting) follows the original header's order.
  const order = [
    ...(d.icon_order ?? []),
    ...ICON_ORDER.filter((icon) => !(d.icon_order ?? []).includes(icon)),
  ];
  const menuButton = items.length > 0 && (
    <button
      type="button"
      className="sf-chead-menu"
      aria-label={t('storefront.menu')}
      aria-haspopup="dialog"
      aria-expanded={open}
      onClick={() => setOpen(true)}
    >
      <Glyph name="menu" />
    </button>
  );
  return (
    <div className="sf-chead">
      {d.show_logo !== false && (
        <a className="sf-logo" href="/" aria-label={block.content.name}>
          {block.content.logo ? (
            <Picture
              image={block.content.logo}
              mobile={block.content.mobile_logo}
              alt={block.content.name ?? ''}
              eager
            />
          ) : (
            <strong>{block.content.name}</strong>
          )}
        </a>
      )}
      <div className="sf-chead-search">
        {d.show_search !== false && (
          <HeaderSearch display={d} icon={<Glyph name="search" />} />
        )}
        {menuButton}
      </div>
      <AddonPageLinks />
      <div className="sf-chead-end">
        {order.map((icon) => {
          if (icon === 'search') return null;
          if (icon === 'language')
            return languages.length > 0 ? (
              <div key={icon} className="sf-chead-pill">
                <LanguageSwitcher languages={languages} />
              </div>
            ) : null;
          if (icon === 'account')
            return d.show_account !== false ? (
              <HeaderAccountMenu
                key={icon}
                signedIn={!!block.content.account_signed_in}
                className={`sf-chead-icon ${block.content.account_signed_in ? 'is-signed-in' : ''}`}
                aria-label={t('storefront.account')}
              >
                <Glyph name="user" />
              </HeaderAccountMenu>
            ) : null;
          if (icon === 'call')
            return callForm ? (
              <button
                key={icon}
                type="button"
                className="sf-chead-icon"
                aria-label={t('storefront.call_request_title')}
                aria-haspopup="dialog"
                onClick={() => setCalling(true)}
              >
                <Glyph name="phone" />
              </button>
            ) : null;
          if (icon === 'favorites')
            return favourites ? (
              <a
                key={icon}
                href="/wishlist"
                className="sf-chead-icon"
                aria-label={t('storefront.favorites')}
              >
                <Glyph name="heart" />
              </a>
            ) : null;
          return d.show_cart !== false ? (
            <span key={icon} className="sf-chead-icon-cart">
              <HeaderCart mini={d.show_mini_cart !== false} />
            </span>
          ) : null;
        })}
      </div>
      {calling && callForm && (
        <CallRequest form={callForm} onClose={() => setCalling(false)} />
      )}
      {/* At page level: inside the header the following header blocks would paint over it. */}
      {open &&
        createPortal(
          <MenuDrawer items={items} onClose={() => setOpen(false)} />,
          document.querySelector('.sf-default') ?? document.body,
        )}
    </div>
  );
}
