import SearchHealthWarning from '../../components/admin/SearchHealthWarning';
import { useStickyEditorBars } from '../../lib/useStickyEditorBars';
import { useUnsavedWarning } from '../../lib/useUnsavedChanges';
import { useTranslation } from '../../lib/useTranslation';
import DocumentLocale from '../../components/DocumentLocale';
import { Head, usePage } from '@inertiajs/react';
import { useRef, useState, type ReactNode } from 'react';
import Breadcrumbs from '../../components/admin/Breadcrumbs';
import Header from '../../components/admin/Header';
import Sidebar from '../../components/admin/Sidebar';
import Notifications from '../../components/Notifications';
import type { Breadcrumb, NavigationItem, SharedProps } from '../../types/page';

export default function AdminLayout({
  title,
  navigation,
  breadcrumbs,
  children,
}: {
  title: string;
  navigation: NavigationItem[];
  breadcrumbs: Breadcrumb[];
  children: ReactNode;
}) {
  const t = useTranslation();
  useUnsavedWarning();
  const {
    props: { app, searchHealth },
    url,
  } = usePage<SharedProps>();
  const content = useStickyEditorBars(url);
  const dialog = useRef<HTMLDialogElement>(null);
  const [collapsed, setCollapsed] = useState(() => {
    try {
      return localStorage.getItem('cms-sidebar-collapsed') === 'true';
    } catch {
      return false;
    }
  });
  const toggleSidebar = () => {
    const next = !collapsed;
    setCollapsed(next);
    try {
      localStorage.setItem('cms-sidebar-collapsed', String(next));
    } catch {
      /* The sidebar still works when browser storage is unavailable. */
    }
  };
  const collapseLabel = t(
    collapsed ? 'navigation.expand_sidebar' : 'navigation.collapse_sidebar',
  );

  return (
    <div
      className="admin-shell"
      data-sidebar-collapsed={collapsed ? '' : undefined}
    >
      <DocumentLocale />
      <Head title={`${title} · ${app.name}`} />
      <a className="skip-link" href="#main-content">
        {t('navigation.skip')}
      </a>
      <Header onOpenNavigation={() => dialog.current?.showModal()} />
      <aside className="admin-sidebar" id="desktop-navigation">
        <div className="min-h-0 flex-1">
          <Sidebar navigation={navigation} collapsed={collapsed} />
        </div>
        <button
          type="button"
          className="admin-sidebar-toggle admin-nav-row mx-2 mb-2 flex shrink-0 items-center gap-2.5 rounded-md px-2 text-sm"
          aria-label={collapseLabel}
          title={collapseLabel}
          aria-expanded={!collapsed}
          aria-controls="desktop-navigation"
          onClick={toggleSidebar}
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
            <path d="M4 4v16M20 12H8m5-5-5 5 5 5" />
          </svg>
          <span className={collapsed ? 'sr-only' : 'truncate'}>
            {collapseLabel}
          </span>
        </button>
      </aside>
      <dialog
        id="mobile-navigation"
        ref={dialog}
        aria-label={t('navigation.label')}
        className="navigation-dialog"
        onClick={(e) => {
          if (e.target === e.currentTarget) dialog.current?.close();
        }}
      >
        <div className="flex h-full w-60 max-w-[85vw] flex-col bg-sidebar text-ink">
          <div className="flex shrink-0 items-center justify-between px-4 pt-2 text-sm">
            <span>{t('navigation.workspace')}</span>
            <button
              type="button"
              className="admin-header-control size-8 justify-center"
              aria-label={t('navigation.close')}
              onClick={() => dialog.current?.close()}
            >
              ✕
            </button>
          </div>
          <div className="min-h-0 flex-1">
            <Sidebar
              navigation={navigation}
              onNavigate={() => dialog.current?.close()}
            />
          </div>
        </div>
      </dialog>
      <div className="admin-workspace" scroll-region="">
        <main
          ref={content}
          id="main-content"
          tabIndex={-1}
          className="admin-page"
        >
          <Breadcrumbs items={breadcrumbs} />
          <Notifications />
          {!url.startsWith('/admin/settings/search') && (
            <SearchHealthWarning health={searchHealth} />
          )}
          {children}
        </main>
      </div>
    </div>
  );
}
