import LanguageSelector from './LanguageSelector';
import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from '../../lib/useTranslation';
import { applyTheme, currentTheme, type Theme } from '../../lib/theme';
import type { SharedProps } from '../../types/page';
import NotificationMenu from './NotificationMenu';
import UserMenu from './UserMenu';

function ProductSearch() {
  const t = useTranslation();
  const input = useRef<HTMLInputElement>(null);
  const [search, setSearch] = useState('');
  const mac = /Mac|iPhone|iPad/.test(navigator.userAgent);
  useEffect(() => {
    const focus = (event: KeyboardEvent) => {
      if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        input.current?.focus();
      }
    };
    window.addEventListener('keydown', focus);
    return () => window.removeEventListener('keydown', focus);
  }, []);
  return (
    <form
      role="search"
      className="admin-product-search relative min-w-0"
      onSubmit={(event) => {
        event.preventDefault();
        router.get('/admin/products', search.trim() ? { search } : {});
      }}
    >
      <label className="sr-only" htmlFor="admin-search">
        {t('navigation.search_label')}
      </label>
      <svg
        aria-hidden="true"
        viewBox="0 0 20 20"
        fill="none"
        className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400"
      >
        <circle cx="9" cy="9" r="5.5" stroke="currentColor" strokeWidth="1.5" />
        <path d="m13.5 13.5 3 3" stroke="currentColor" strokeWidth="1.5" />
      </svg>
      <input
        id="admin-search"
        ref={input}
        type="search"
        maxLength={100}
        value={search}
        placeholder={t('navigation.search')}
        onChange={(event) => setSearch(event.target.value)}
        className="h-8 w-full rounded-lg border border-slate-300 bg-white pr-14 pl-9 text-sm placeholder:text-slate-400 focus:border-primary-hover"
      />
      <kbd
        aria-hidden="true"
        className="pointer-events-none absolute top-1/2 right-2.5 -translate-y-1/2 rounded border border-slate-200 bg-white px-1.5 py-0.5 font-sans text-[11px] text-slate-400"
      >
        {mac ? '⌘K' : 'Ctrl K'}
      </kbd>
    </form>
  );
}

/** Opens the public site in a new tab; the storefront itself is a later phase, so this is the site root. */
function StorefrontLink() {
  const t = useTranslation();
  return (
    <a
      href="/"
      target="_blank"
      rel="noopener noreferrer"
      title={t('navigation.view_storefront')}
      aria-label={t('navigation.view_storefront')}
      className="admin-header-control size-8 justify-center"
    >
      <svg
        aria-hidden="true"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.6"
        strokeLinecap="round"
        strokeLinejoin="round"
        className="size-5 shrink-0"
      >
        <path d="M4 9.5 5.5 4h13L20 9.5" />
        <path d="M4 9.5a2.7 2.7 0 0 0 5.3 0 2.7 2.7 0 0 0 5.4 0 2.7 2.7 0 0 0 5.3 0" />
        <path d="M5.5 12.5V20h13v-7.5" />
        <path d="M10 20v-4.5h4V20" />
      </svg>
    </a>
  );
}

function ThemeToggle() {
  const t = useTranslation();
  const [theme, setTheme] = useState<Theme>(currentTheme);
  const dark = theme === 'dark';
  return (
    <button
      type="button"
      aria-pressed={dark}
      aria-label={t('navigation.dark_theme')}
      title={t('navigation.dark_theme')}
      className="admin-header-control size-8 justify-center"
      onClick={() => {
        const next = dark ? 'light' : 'dark';
        applyTheme(next);
        setTheme(next);
      }}
    >
      <svg
        aria-hidden="true"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.6"
        strokeLinecap="round"
        strokeLinejoin="round"
        className="size-5"
      >
        {dark ? (
          <>
            <circle cx="12" cy="12" r="4" />
            <path d="M12 2.5v2M12 19.5v2M4.6 4.6l1.4 1.4M18 18l1.4 1.4M2.5 12h2M19.5 12h2M4.6 19.4 6 18M18 6l1.4-1.4" />
          </>
        ) : (
          <path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5Z" />
        )}
      </svg>
    </button>
  );
}

export default function Header({
  onOpenNavigation,
}: {
  onOpenNavigation: () => void;
}) {
  const t = useTranslation();
  const { app, capabilities } = usePage<SharedProps>().props;
  return (
    <header className="admin-topbar">
      <div className="flex min-w-0 items-center gap-2">
        <button
          type="button"
          className="admin-header-control size-8 justify-center lg:hidden"
          onClick={onOpenNavigation}
          aria-label={t('navigation.menu')}
          aria-haspopup="dialog"
          aria-controls="mobile-navigation"
        >
          <svg
            aria-hidden="true"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.6"
            strokeLinecap="round"
            className="size-5"
          >
            <path d="M4 6h16M4 12h16M4 18h16" />
          </svg>
        </button>
        <Link href="/admin" className="admin-brand" aria-label={app.name}>
          <img
            src="/brand/ambresoft-lockup-green.svg"
            alt=""
            className="admin-logo-light"
          />
          <img
            src="/brand/ambresoft-lockup-white.svg"
            alt=""
            className="admin-logo-dark"
          />
        </Link>
      </div>
      {capabilities['products.view'] && <ProductSearch />}
      <div className="admin-header-actions flex shrink-0 items-center gap-1 sm:gap-2">
        <StorefrontLink />
        <span aria-hidden="true" className="h-5 w-px shrink-0 bg-ink/25" />
        <ThemeToggle />
        {capabilities['orders.view'] && capabilities['orders.update'] && (
          <NotificationMenu />
        )}
        <LanguageSelector />
        <UserMenu />
      </div>
    </header>
  );
}
