import HeaderAccountMenu from './HeaderAccountMenu';
import { createPortal } from 'react-dom';
import { useCart } from './cartContext';
import { useEffect, useId, useLayoutEffect, useRef, useState } from 'react';
import { useTranslation } from '../../../lib/useTranslation';
import type {
  PublicBlock,
  PublicDisplay,
  PublicLanguage,
  PublicMenuNode,
} from '../../types';

import { Picture } from './parts';
import HeaderCart from './MiniCart';
import CallRequest from './CallRequest';
import { FullscreenSearch } from './HeaderSearch';
import { useWishlist } from './wishlistContext';

type IconName =
  | 'down'
  | 'right'
  | 'left'
  | 'close'
  | 'fashion_burger'
  | 'burger'
  | 'search'
  | 'user'
  | 'star'
  | 'bag'
  | 'phone';
const icons: Record<IconName, string[]> = {
  phone: [
    'M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A15 15 0 0 1 3 6a2 2 0 0 1 2-2Z',
  ],
  search: ['M11 3a8 8 0 1 0 0 16 8 8 0 0 0 0-16Z', 'm17 17 4 4'],
  user: ['M12 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8Z', 'M4 21l2-7h12l2 7'],
  star: ['m12 2 3 6.5 7 1-5 5 1.2 7-6.2-3.3L5.8 21l1.2-6.5-5-5 7-1Z'],
  bag: ['M3 7h18v15H3Z', 'M8 7V3h8v4'],
  down: ['m6 9 6 6 6-6'],
  right: ['m9 5 7 7-7 7'],
  left: ['m15 5-7 7 7 7'],
  close: ['M6 6l12 12M18 6 6 18'],
  burger: ['M4 7h16M4 12h16M4 17h16'],
  fashion_burger: ['M3 7h18M3 17h18'],
};
function Icon({ name, size = 18 }: { name: IconName; size?: number }) {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      width={size}
      height={size}
      fill="none"
      stroke="currentColor"
      strokeWidth={['search', 'user', 'star', 'bag'].includes(name) ? 1.5 : 2.2}
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      {icons[name].map((d) => (
        <path key={d} d={d} />
      ))}
    </svg>
  );
}
const visibility = (
  d: PublicDisplay,
  slot: 'main' | 'secondary',
  extra = '',
): string =>
  [
    d[`${slot}_mobile`] === false ? 'sf-hide-mobile' : '',
    d[`${slot}_tablet`] === false ? 'sf-hide-tablet' : '',
    d[`${slot}_desktop`] === false ? 'sf-hide-desktop' : '',
    extra,
  ]
    .filter(Boolean)
    .join(' ');

function NodeLink({
  node,
  className,
  children,
}: {
  node: PublicMenuNode;
  className: string;
  children: React.ReactNode;
}) {
  return node.url ? (
    <a
      href={node.url}
      className={className}
      target={node.new_window ? '_blank' : undefined}
      rel={node.new_window ? 'noopener noreferrer' : undefined}
    >
      {children}
    </a>
  ) : (
    <span className={className}>{children}</span>
  );
}

/** Hover intent: moving the pointer quickly across entries does not switch panels; it must rest for a moment. */
const HOVER_DELAY = 140;
const FASHION_ICONS = [
  'language',
  'search',
  'account',
  'call',
  'favorites',
  'cart',
];

/** Columns of an open top-level item: each hovered entry shows its own children in the next column. */
function MegaPanel({ root }: { root: PublicMenuNode }) {
  const timer = useRef<number | undefined>(undefined);
  useEffect(() => () => window.clearTimeout(timer.current), []);
  const [path, setPath] = useState<string[]>(() => {
    const first: string[] = [];
    let node = root;
    for (;;) {
      const next = node.children.find((c) => c.children.length > 0);
      if (!next) break;
      first.push(next.id);
      node = next;
    }
    return first;
  });
  const columns: PublicMenuNode[] = [root];
  let node = root;
  for (const id of path) {
    const next = node.children.find((c) => c.id === id);
    if (!next) break;
    columns.push(next);
    node = next;
  }
  return (
    <div className="sf-mega-columns">
      <div className="sf-mega-track">
        {columns.map((column, index) => (
          <div className="sf-mega-col" key={column.id}>
            <p className="sf-mega-title">{column.name}</p>
            <ul>
              {column.children.map((child) => (
                <li
                  key={child.id}
                  className={path[index] === child.id ? 'is-active' : ''}
                  onMouseEnter={() => {
                    window.clearTimeout(timer.current);
                    timer.current = window.setTimeout(
                      () =>
                        setPath((old) =>
                          child.children.length > 0
                            ? [...old.slice(0, index), child.id]
                            : old.slice(0, index),
                        ),
                      HOVER_DELAY,
                    );
                  }}
                  onMouseLeave={() => window.clearTimeout(timer.current)}
                >
                  <NodeLink node={child} className="sf-mega-row">
                    {child.image && (
                      <img
                        src={child.image.url}
                        alt=""
                        width={child.image.width}
                        height={child.image.height}
                        loading="lazy"
                      />
                    )}
                    <span>{child.name}</span>
                    {child.children.length > 0 && <Icon name="right" />}
                  </NodeLink>
                </li>
              ))}
            </ul>
          </div>
        ))}
      </div>
    </div>
  );
}

/** The directory design: the top categories in a list, and the hovered one's subcategories as groups of links in a grid beside it. */
function DirectoryPanel({ root }: { root: PublicMenuNode }) {
  const timer = useRef<number | undefined>(undefined);
  useEffect(() => () => window.clearTimeout(timer.current), []);
  const [active, setActive] = useState(
    () =>
      (root.children.find((c) => c.children.length > 0) ?? root.children[0])
        ?.id,
  );
  const current = root.children.find((c) => c.id === active);
  return (
    <div className="sf-mega-columns sf-dir">
      <div className="sf-mega-track">
        <ul className="sf-dir-nav">
          {root.children.map((child) => (
            <li
              key={child.id}
              className={child.id === active ? 'is-active' : ''}
              onMouseEnter={() => {
                window.clearTimeout(timer.current);
                timer.current = window.setTimeout(
                  () => setActive(child.id),
                  HOVER_DELAY,
                );
              }}
              onMouseLeave={() => window.clearTimeout(timer.current)}
            >
              <NodeLink node={child} className="sf-dir-row">
                {child.image && (
                  <img
                    src={child.image.url}
                    alt=""
                    width={child.image.width}
                    height={child.image.height}
                    loading="lazy"
                  />
                )}
                <span>{child.name}</span>
                {child.children.length > 0 && <Icon name="right" />}
              </NodeLink>
            </li>
          ))}
        </ul>
        {current && current.children.length > 0 && (
          <div className="sf-dir-body" key={current.id}>
            {current.children.map((group) => (
              <div className="sf-dir-group" key={group.id}>
                <NodeLink node={group} className="sf-dir-title">
                  {group.name}
                </NodeLink>
                {group.children.length > 0 && (
                  <ul>
                    {group.children.map((link) => (
                      <li key={link.id}>
                        <NodeLink node={link} className="sf-dir-link">
                          {link.name}
                        </NodeLink>
                      </li>
                    ))}
                  </ul>
                )}
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}

/** All groups stay visible together; deeper headings stay in their parent column. */
function FashionLinks({ nodes }: { nodes: PublicMenuNode[] }) {
  return (
    <ul>
      {nodes.map((node) => (
        <li key={node.id}>
          <NodeLink
            node={node}
            className={
              node.children.length ? 'sf-fashion-heading' : 'sf-fashion-link'
            }
          >
            {node.name}
          </NodeLink>
          {node.children.length > 0 && <FashionLinks nodes={node.children} />}
        </li>
      ))}
    </ul>
  );
}
function FashionPanel({ root }: { root: PublicMenuNode }) {
  return (
    <div className="sf-mega-columns sf-fashion-panel">
      <div className="sf-fashion-groups">
        {root.children.map((group) => (
          <div className="sf-fashion-group" key={group.id}>
            <NodeLink node={group} className="sf-fashion-heading">
              {group.name}
            </NodeLink>
            {group.children.length > 0 && (
              <FashionLinks nodes={group.children} />
            )}
          </div>
        ))}
      </div>
      {root.image && (
        <NodeLink node={root} className="sf-fashion-promotion">
          <Picture image={root.image} alt={root.name} />
        </NodeLink>
      )}
    </div>
  );
}

export function LanguageSwitcher({
  languages,
}: {
  languages: PublicLanguage[];
}) {
  const t = useTranslation();
  const [open, setOpen] = useState(false);
  const box = useRef<HTMLDivElement>(null);
  useEffect(() => {
    if (!open) return;
    const close = (e: PointerEvent) => {
      if (!box.current?.contains(e.target as Node)) setOpen(false);
    };
    const escape = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false);
    document.addEventListener('pointerdown', close);
    document.addEventListener('keydown', escape);
    return () => {
      document.removeEventListener('pointerdown', close);
      document.removeEventListener('keydown', escape);
    };
  }, [open]);
  const current = languages.find((l) => l.active) ?? languages[0];
  return (
    <div className="sf-lang" ref={box}>
      <button
        type="button"
        aria-expanded={open}
        aria-label={t('storefront.language')}
        onClick={() => setOpen(!open)}
      >
        {current.code.toUpperCase()}
        <Icon name="down" size={16} />
      </button>
      {open && (
        <ul>
          {languages.map((l) => (
            <li key={l.code}>
              <a href={l.url} hrefLang={l.code} aria-current={l.active}>
                {l.name}
              </a>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

/** Full-screen mobile menu: lists drill down level by level with a back button. */
function Drawer({
  items,
  start,
  secondary,
  languages,
  fashionBlock,
  onClose,
}: {
  items: PublicMenuNode[];
  start: PublicMenuNode | null;
  secondary: PublicMenuNode[];
  languages: PublicLanguage[];
  fashionBlock?: PublicBlock;
  onClose: () => void;
}) {
  const t = useTranslation();
  const cart = useCart();
  const wishlist = useWishlist();
  const d = fashionBlock?.content.display ?? {};
  const fashion = !!fashionBlock;
  const mobileLinks = fashionBlock?.content.mobile_links ?? {
    title: '',
    items: [],
  };
  const links = mobileLinks.items.filter(
    (item) =>
      (cart.enabled || item.url.split(/[?#]/)[0] !== '/cart') &&
      (wishlist.enabled || !/^\/wishlist(?:[/?#]|$)/.test(item.url)),
  );

  const modal = useRef<HTMLDivElement>(null);
  const [stack, setStack] = useState<PublicMenuNode[]>(start ? [start] : []);
  const closeButton = useRef<HTMLButtonElement>(null);
  const current = stack[stack.length - 1];
  const list = current ? current.children : items;
  useEffect(() => {
    const previous = document.body.style.overflow;
    const previousFocus = document.activeElement as HTMLElement | null;
    document.body.style.overflow = 'hidden';
    closeButton.current?.focus();
    const escape = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
      if (!fashion || e.key !== 'Tab') return;
      const controls = modal.current?.querySelectorAll<HTMLElement>(
        'a[href], button:not([disabled])',
      );
      const first = controls?.[0];
      const last = controls?.[controls.length - 1];
      if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last?.focus();
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first?.focus();
      }
    };
    document.addEventListener('keydown', escape);
    return () => {
      document.body.style.overflow = previous;
      document.removeEventListener('keydown', escape);
      previousFocus?.focus();
    };
  }, [onClose, fashion]);
  return (
    <div
      ref={modal}
      className={`sf-drawer${fashion ? ' sf-drawer-fashion' : ''}`}
      role="dialog"
      aria-modal="true"
      aria-label={t('storefront.menu')}
    >
      <div className="sf-drawer-head">
        {stack.length > 0 ? (
          <button
            type="button"
            aria-label={t('storefront.back')}
            onClick={() => setStack(stack.slice(0, -1))}
          >
            <Icon name="left" size={22} />
          </button>
        ) : (
          <span />
        )}
        <strong>{current?.name ?? t('storefront.menu')}</strong>
        <button
          type="button"
          ref={closeButton}
          aria-label={t('storefront.close')}
          onClick={onClose}
        >
          <Icon name="close" size={22} />
        </button>
      </div>
      <div className="sf-drawer-body">
        {current?.url && (
          <a className="sf-drawer-row sf-drawer-all" href={current.url}>
            <span>
              {t('storefront.view_all')} {current.name}
            </span>
          </a>
        )}
        <ul>
          {list.map((node) => (
            <li key={node.id}>
              {node.children.length > 0 ? (
                <button
                  type="button"
                  className="sf-drawer-row"
                  onClick={() => setStack([...stack, node])}
                >
                  {node.image && (
                    <img src={node.image.url} alt="" width={50} height={50} />
                  )}
                  <span>{node.name}</span>
                  <Icon name="right" />
                </button>
              ) : (
                <NodeLink node={node} className="sf-drawer-row">
                  {node.image && (
                    <img src={node.image.url} alt="" width={50} height={50} />
                  )}
                  <span>{node.name}</span>
                </NodeLink>
              )}
            </li>
          ))}
        </ul>
        {!current && secondary.length > 0 && (
          <ul className="sf-drawer-secondary">
            {secondary.map((node) => (
              <li key={node.id}>
                <NodeLink node={node} className="sf-drawer-row">
                  <span>{node.name}</span>
                </NodeLink>
              </li>
            ))}
          </ul>
        )}
        {!current &&
          fashion &&
          d.show_mobile_account_links !== false &&
          links.length > 0 && (
            <section
              className="sf-fashion-account"
              aria-label={mobileLinks.title || t('storefront.menu')}
            >
              {mobileLinks.title && <h2>{mobileLinks.title}</h2>}
              {links.map((item, index) =>
                item.url === '/account' &&
                item.label === t('storefront.account') &&
                !fashionBlock?.content.account_signed_in ? (
                  <p key={index}>
                    <a
                      href="/login"
                      target={item.new_window ? '_blank' : undefined}
                      rel={item.new_window ? 'noopener noreferrer' : undefined}
                    >
                      {t('storefront.menu_sign_in')}
                    </a>
                    <span aria-hidden="true"> / </span>
                    <a
                      href="/register"
                      target={item.new_window ? '_blank' : undefined}
                      rel={item.new_window ? 'noopener noreferrer' : undefined}
                    >
                      {t('storefront.menu_register')}
                    </a>
                  </p>
                ) : (
                  <a
                    key={index}
                    href={item.url}
                    target={item.new_window ? '_blank' : undefined}
                    rel={item.new_window ? 'noopener noreferrer' : undefined}
                  >
                    {item.label}
                  </a>
                ),
              )}
            </section>
          )}
        {!current && languages.length > 0 && (
          <div className="sf-drawer-langs">
            {languages.map((l) => (
              <a
                key={l.code}
                href={l.url}
                hrefLang={l.code}
                aria-current={l.active}
              >
                {l.name}
              </a>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}

/** Menu bar under the header: main menu with mega dropdowns, secondary links and the language switcher. */
export default function MenuBlock({
  block,
  fashion = false,
}: {
  block: PublicBlock;
  fashion?: boolean;
}) {
  const t = useTranslation();
  const d = block.content.display ?? {};
  const [calling, setCalling] = useState(false);
  const callForm =
    d.show_call_request === true ? block.content.call_request_form : null;
  const order = d.icon_order ?? FASHION_ICONS;
  const wishlist = useWishlist();
  const [searching, setSearching] = useState(false);
  const searchId = useId();
  const searchButton = useRef<HTMLButtonElement>(null);
  const main = block.content.main ?? [];
  const secondary = block.content.secondary ?? [];
  const languages =
    d.show_language_switcher === false ? [] : (block.content.languages ?? []);
  const burger = d.mobile_style !== 'inline';
  const [open, setOpen] = useState<PublicMenuNode | null>(null);
  const [drawer, setDrawer] = useState<{ start: PublicMenuNode | null } | null>(
    null,
  );
  const box = useRef<HTMLDivElement>(null);
  const hover = useRef<number | undefined>(undefined);
  useEffect(() => () => window.clearTimeout(hover.current), []);
  // While a panel is open on desktop and tablet the page stays put: only the panel scrolls, so the header never scrolls away.
  useEffect(() => {
    if (!open || !window.matchMedia('(min-width: 768px)').matches) return;
    const root = document.documentElement;
    const before = {
      overflow: root.style.overflow,
      padding: root.style.paddingRight,
    };
    const bar = window.innerWidth - root.clientWidth;
    root.style.overflow = 'hidden';
    if (bar > 0) root.style.paddingRight = `${bar}px`;
    return () => {
      root.style.overflow = before.overflow;
      root.style.paddingRight = before.padding;
    };
  }, [open]);
  // A full-width panel reaches the bottom of the screen, so no page shows beneath it (like bol).
  const mega = useRef<HTMLDivElement>(null);
  const fullWidth = fashion || !!d.full_width_dropdown;
  useLayoutEffect(() => {
    const panel = mega.current;
    if (!open || !fullWidth || !panel) return;
    const fit = () => {
      if (!window.matchMedia('(min-width: 768px)').matches) {
        panel.style.height = '';
        return;
      }
      const top = panel.getBoundingClientRect().top;
      panel.style.height = `${Math.max(320, window.innerHeight - top)}px`;
    };
    fit();
    window.addEventListener('scroll', fit, { passive: true });
    window.addEventListener('resize', fit);
    return () => {
      window.removeEventListener('scroll', fit);
      window.removeEventListener('resize', fit);
    };
  }, [open, fullWidth]);
  useEffect(() => {
    if (!open) return;
    const close = (e: PointerEvent) => {
      if (!box.current?.contains(e.target as Node)) setOpen(null);
    };
    const escape = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(null);
    document.addEventListener('pointerdown', close);
    document.addEventListener('keydown', escape);
    return () => {
      document.removeEventListener('pointerdown', close);
      document.removeEventListener('keydown', escape);
    };
  }, [open]);
  const mobile = () => window.matchMedia('(max-width: 767px)').matches;
  const fine = () => window.matchMedia('(hover: hover)').matches;
  const hasBurger =
    burger &&
    ((d.main_mobile !== false && main.length > 0) ||
      (d.secondary_mobile === true && secondary.length > 0) ||
      languages.length > 0 ||
      (fashion && d.show_mobile_account_links !== false));
  const drawerView = drawer ? (
    <Drawer
      items={d.main_mobile === false ? [] : main}
      start={drawer.start}
      secondary={d.secondary_mobile === true ? secondary : []}
      languages={languages}
      fashionBlock={fashion ? block : undefined}
      onClose={() => setDrawer(null)}
    />
  ) : null;
  return (
    <div
      className={`sf-menu${fashion ? ' sf-menu-fashion' : ''}`}
      ref={box}
      onMouseLeave={() => {
        window.clearTimeout(hover.current);
        // Opened by click, the panel stays until a click elsewhere, the same item or Escape.
        if (fine() && !d.open_on_click) setOpen(null);
      }}
    >
      <div className="sf-menu-bar">
        {fashion && d.show_logo !== false && (
          <a href="/" className="sf-fashion-logo">
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
        {hasBurger && (
          <button
            type="button"
            className="sf-menu-burger"
            aria-label={t('storefront.menu')}
            aria-haspopup="dialog"
            aria-expanded={!!drawer}
            onClick={() => {
              setSearching(false);
              setDrawer({ start: null });
            }}
          >
            <Icon
              name={fashion ? 'fashion_burger' : 'burger'}
              size={fashion ? 28 : 26}
            />
          </button>
        )}
        {main.length > 0 && (
          <ul
            className={visibility(
              d,
              'main',
              `sf-menu-main ${burger ? 'sf-menu-main-burger' : ''}`,
            )}
          >
            {main.map((node) => (
              <li
                key={node.id}
                className={open?.id === node.id ? 'is-open' : ''}
                onMouseEnter={() => {
                  window.clearTimeout(hover.current);
                  if (
                    !d.open_on_click &&
                    fine() &&
                    !mobile() &&
                    node.children.length > 0
                  )
                    hover.current = window.setTimeout(
                      () => setOpen(node),
                      HOVER_DELAY,
                    );
                }}
                onMouseLeave={() => window.clearTimeout(hover.current)}
              >
                {node.children.length > 0 ? (
                  <button
                    type="button"
                    aria-expanded={open?.id === node.id}
                    onClick={() => {
                      if (mobile()) setDrawer({ start: node });
                      else {
                        setSearching(false);
                        setOpen(open?.id === node.id ? null : node);
                      }
                    }}
                  >
                    {node.name}
                    <Icon name="down" />
                  </button>
                ) : (
                  <NodeLink node={node} className="sf-menu-link">
                    {node.name}
                  </NodeLink>
                )}
              </li>
            ))}
          </ul>
        )}
        <div className="sf-menu-right">
          {secondary.length > 0 && (
            <ul className={visibility(d, 'secondary', 'sf-menu-secondary')}>
              {secondary.map((node) => (
                <li key={node.id}>
                  <NodeLink node={node} className="sf-menu-link">
                    {node.name}
                  </NodeLink>
                </li>
              ))}
            </ul>
          )}
          {!fashion && languages.length > 0 && (
            <LanguageSwitcher languages={languages} />
          )}
          {fashion && (
            <div className="sf-fashion-actions">
              {order.map((icon) => {
                if (icon === 'language')
                  return languages.length > 0 ? (
                    <div key={icon} data-fashion-action="language">
                      <LanguageSwitcher languages={languages} />
                    </div>
                  ) : null;
                if (icon === 'search')
                  return d.show_search !== false ? (
                    <button
                      key={icon}
                      type="button"
                      data-fashion-action="search"
                      ref={searchButton}
                      aria-label={t('storefront.search')}
                      aria-expanded={searching}
                      aria-controls={searchId}
                      aria-haspopup="dialog"
                      onClick={() => {
                        setOpen(null);
                        setSearching(!searching);
                      }}
                    >
                      <Icon name="search" size={28} />
                    </button>
                  ) : null;
                if (icon === 'account')
                  return d.show_account !== false ? (
                    <HeaderAccountMenu
                      key={icon}
                      fashion
                      signedIn={!!block.content.account_signed_in}
                      aria-label={t('storefront.account')}
                    >
                      <Icon name="user" size={28} />
                    </HeaderAccountMenu>
                  ) : null;
                if (icon === 'call')
                  return callForm ? (
                    <button
                      key={icon}
                      type="button"
                      data-fashion-action="call"
                      aria-label={t('storefront.call_request_title')}
                      aria-haspopup="dialog"
                      onClick={() => setCalling(true)}
                    >
                      <Icon name="phone" size={28} />
                    </button>
                  ) : null;
                if (icon === 'favorites')
                  return wishlist.enabled && d.show_favorites !== false ? (
                    <a
                      key={icon}
                      data-fashion-action="favorites"
                      href="/wishlist"
                      aria-label={t('storefront.favorites')}
                    >
                      <Icon name="star" size={28} />
                    </a>
                  ) : null;
                if (icon === 'cart')
                  return d.show_cart !== false ? (
                    <span key={icon} data-fashion-action="cart">
                      <HeaderCart
                        mini={d.show_mini_cart !== false}
                        icon={<Icon name="bag" size={28} />}
                      />
                    </span>
                  ) : null;
                return null;
              })}
            </div>
          )}
        </div>
      </div>
      {fashion && calling && callForm && (
        <CallRequest form={callForm} onClose={() => setCalling(false)} />
      )}
      {fashion &&
        d.show_search !== false &&
        searching &&
        createPortal(
          <FullscreenSearch
            icon={<Icon name="search" size={28} />}
            id={searchId}
            display={d}
            onClose={() => {
              setSearching(false);
              searchButton.current?.focus();
            }}
          />,
          document.querySelector('.sf-default') ?? document.body,
        )}
      {open && d.dim_page_on_open && (
        <div className="sf-menu-backdrop" aria-hidden="true" />
      )}
      {open && (
        <div
          ref={mega}
          className={`sf-mega${fullWidth ? ' is-full-width' : ''}`}
        >
          {fashion ? (
            <FashionPanel key={open.id} root={open} />
          ) : d.dropdown_style === 'directory' ? (
            <DirectoryPanel key={open.id} root={open} />
          ) : (
            <MegaPanel key={open.id} root={open} />
          )}
        </div>
      )}
      {drawerView &&
        (fashion
          ? createPortal(
              drawerView,
              document.querySelector('.sf-default') ?? document.body,
            )
          : drawerView)}
    </div>
  );
}
