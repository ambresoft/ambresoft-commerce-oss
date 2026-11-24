import { router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { useTranslation } from '../../lib/useTranslation';
import type { SharedProps } from '../../types/page';
import Dropdown from './Dropdown';
import { Icon, type IconName } from './list/IconButton';

const kinds: Record<string, { icon: IconName; tone: string }> = {
  'order.placed': { icon: 'receipt', tone: 'bg-emerald-50 text-emerald-700' },
  'security.login_failed': { icon: 'shield', tone: 'bg-red-50 text-red-700' },
  'review.pending': { icon: 'chat', tone: 'bg-amber-50 text-amber-700' },
  'call.requested': { icon: 'user', tone: 'bg-sky-50 text-sky-700' },
};

/** "5 minutes ago" for the last week, then the date. */
function ago(iso: string, locale: string): string {
  const seconds = Math.round((new Date(iso).getTime() - Date.now()) / 1000);
  const relative = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });
  for (const [unit, size] of [
    ['day', 86400],
    ['hour', 3600],
    ['minute', 60],
  ] as const) {
    if (Math.abs(seconds) >= size && Math.abs(seconds) < 7 * 86400)
      return relative.format(Math.round(seconds / size), unit);
  }
  if (Math.abs(seconds) < 60) return relative.format(0, 'second');
  return new Date(iso).toLocaleString(locale, {
    dateStyle: 'medium',
    timeStyle: 'short',
  });
}

type Notice = {
  id: string;
  type: string;
  title: string;
  message: string;
  url: string | null;
  created_at: string;
  read_at: string | null;
};
type Inbox = {
  revision: string;
  unread: number;
  items: Notice[];
  next: string | null;
};
class RequestError extends Error {
  constructor(public status: number) {
    super('Notification request failed');
  }
}
async function request(
  url: string,
  signal: AbortSignal,
  action?: string,
): Promise<Inbox> {
  const token =
    document.cookie
      .split('; ')
      .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
      ?.slice(11) ?? '';
  const response = await fetch(url, {
    credentials: 'same-origin',
    signal,
    method: action ? 'POST' : 'GET',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-XSRF-TOKEN': decodeURIComponent(token),
    },
    ...(action ? { body: JSON.stringify({ action }) } : {}),
  });
  if (!response.ok) throw new RequestError(response.status);
  return response.json() as Promise<Inbox>;
}

export default function NotificationMenu() {
  const t = useTranslation();
  const { auth, i18n } = usePage<SharedProps>().props;
  const [inbox, setInbox] = useState<Inbox | null>(null);
  const [error, setError] = useState(false);
  const [offline, setOffline] = useState(false);
  const [denied, setDenied] = useState(false);
  const [busy, setBusy] = useState(false);
  const [older, setOlder] = useState(false);
  const [open, setOpen] = useState(false);
  const [unreadOnly, setUnreadOnly] = useState(false);
  const source = useRef<EventSource | null>(null);
  const abort = useRef<AbortController | null>(null);
  const serial = useRef(0);
  const revision = useRef(-1n);
  const refresh = useCallback(
    async (before?: string, action?: string, id?: string) => {
      const controller = abort.current;
      if (!controller || controller.signal.aborted) return false;
      const ticket = ++serial.current;
      try {
        const url = action
          ? `/admin/notifications/${id ?? 'read-all'}`
          : `/admin/notifications${before ? `?before=${before}` : ''}`;
        const result = await request(url, controller.signal, action);
        if (controller.signal.aborted) return false;
        // A slower GET must never overwrite a newer committed mutation or SSE refresh.
        if (
          BigInt(result.revision) > revision.current ||
          (BigInt(result.revision) === revision.current &&
            ticket === serial.current)
        ) {
          revision.current = BigInt(result.revision);
          setInbox(result);
          setOlder(Boolean(before));
          setError(false);
        }
        return true;
      } catch (failure) {
        if (controller.signal.aborted) return false;
        if (
          failure instanceof RequestError &&
          [401, 403, 419].includes(failure.status)
        ) {
          source.current?.close();
          setInbox(null);
          setDenied(true);
        } else setError(true);
        return false;
      }
    },
    [],
  );

  useEffect(() => {
    const controller = new AbortController();
    abort.current = controller;
    revision.current = -1n;
    const stream = new EventSource('/admin/notifications/stream');
    source.current = stream;
    const sync = () => {
      setOffline(false);
      void refresh();
    };
    const revoked = () => {
      stream.close();
      setInbox(null);
      setDenied(true);
    };
    stream.addEventListener('notifications.sync', sync);
    stream.addEventListener('notification.created', sync);
    stream.addEventListener('notifications.changed', sync);
    stream.addEventListener('stream.revoked', revoked);
    let rotating = false;
    stream.addEventListener('stream.rotate', () => {
      rotating = true;
    });
    stream.onerror = () => {
      setOffline(!rotating);
      rotating = false;
      void refresh();
    };
    const focus = () => {
      void refresh();
    };
    window.addEventListener('focus', focus);
    void refresh();
    return () => {
      controller.abort();
      stream.close();
      window.removeEventListener('focus', focus);
    };
  }, [auth.user?.id, i18n.locale, refresh]);

  async function change(action: string, notice?: Notice, navigate = false) {
    setBusy(true);
    const ok = await refresh(undefined, action, notice?.id);
    setBusy(false);
    if (ok && navigate && notice?.url) {
      setOpen(false);
      router.visit(notice.url);
    }
  }
  if (denied) return null;
  return (
    <Dropdown
      label={t('notifications.title')}
      open={open}
      onOpenChange={(value) => {
        setOpen(value);
        if (value) void refresh();
      }}
      triggerClassName="admin-header-control relative size-8 justify-center"
      panelClassName="notification-panel w-96 max-w-[calc(100vw-1rem)] p-0"
      trigger={
        <>
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
            <path d="M6 9.5a6 6 0 1 1 12 0c0 4.5 1.5 6 2 6.5H4c.5-.5 2-2 2-6.5Z" />
            <path d="M10 19.5a2 2 0 0 0 4 0" />
          </svg>
          {Boolean(inbox?.unread) && (
            <span
              aria-label={t('notifications.unread', { count: inbox!.unread })}
              className="absolute -top-1 -right-1 rounded-full bg-primary px-1 text-[10px] font-bold text-white"
            >
              {inbox!.unread > 99 ? '99+' : inbox!.unread}
            </span>
          )}
        </>
      }
    >
      {() => (
        <section
          aria-label={t('notifications.title')}
          className="max-h-[min(36rem,calc(100dvh-5rem))] overflow-y-auto"
        >
          <div className="sticky top-0 z-10 border-b border-slate-200 bg-white px-4 pt-4 pb-3">
            <div className="flex items-center justify-between gap-2">
              <h2 className="font-semibold">{t('notifications.title')}</h2>
              <button
                type="button"
                className="text-xs font-medium text-primary-hover hover:underline disabled:opacity-50 disabled:hover:no-underline"
                disabled={busy || !inbox?.unread}
                onClick={() => void change('read')}
              >
                {t('notifications.read_all')}
              </button>
            </div>
            <div className="mt-3 flex items-center justify-between gap-2 text-xs">
              <div
                role="group"
                aria-label={t('notifications.title')}
                className="inline-flex rounded-lg bg-slate-100 p-0.5"
              >
                {([false, true] as const).map((value) => (
                  <button
                    key={String(value)}
                    type="button"
                    aria-pressed={unreadOnly === value}
                    onClick={() => setUnreadOnly(value)}
                    className={`rounded-md px-2.5 py-1 font-medium ${unreadOnly === value ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-800'}`}
                  >
                    {t(
                      value
                        ? 'notifications.show_unread'
                        : 'notifications.show_all',
                    )}
                  </button>
                ))}
              </div>
              <span aria-live="polite" className="text-slate-500">
                {t('notifications.unread', { count: inbox?.unread ?? 0 })}
              </span>
            </div>
          </div>
          {offline && (
            <p role="status" className="px-4 pt-3 text-xs text-slate-500">
              {t('notifications.offline')}
            </p>
          )}
          {error && (
            <div role="alert" className="p-4 text-sm">
              {t('notifications.error')}{' '}
              <button
                type="button"
                className="underline"
                onClick={() => void refresh()}
              >
                {t('notifications.retry')}
              </button>
            </div>
          )}
          {!inbox && !error && (
            <p role="status" className="p-6 text-sm">
              {t('notifications.loading')}
            </p>
          )}
          {inbox &&
            inbox.items.filter((notice) => !unreadOnly || !notice.read_at)
              .length === 0 && (
              <p className="p-6 text-center text-sm text-slate-500">
                {t(
                  unreadOnly && inbox.items.length > 0
                    ? 'notifications.empty_unread'
                    : 'notifications.empty',
                )}
              </p>
            )}
          <ul className="divide-y divide-slate-100">
            {inbox?.items
              .filter((notice) => !unreadOnly || !notice.read_at)
              .map((notice) => {
                const kind = kinds[notice.type] ?? kinds['order.placed']!;
                const body = (
                  <>
                    <span
                      className={`flex size-9 shrink-0 items-center justify-center rounded-lg ${kind.tone}`}
                    >
                      <Icon name={kind.icon} className="size-5" />
                    </span>
                    <span className="min-w-0 flex-1">
                      <span className="flex items-center gap-2">
                        <span
                          className={`truncate text-sm ${notice.read_at ? 'font-medium text-slate-700' : 'font-semibold text-slate-900'}`}
                        >
                          {notice.title}
                        </span>
                        {!notice.read_at && (
                          <span
                            aria-hidden="true"
                            className="size-2 shrink-0 rounded-full bg-primary"
                          />
                        )}
                      </span>
                      <span className="mt-0.5 block text-sm text-slate-600">
                        {notice.message}
                      </span>
                      <span className="mt-1 block text-xs text-slate-500">
                        <time
                          dateTime={notice.created_at}
                          title={new Date(notice.created_at).toLocaleString(
                            i18n.locale,
                          )}
                        >
                          {ago(notice.created_at, i18n.locale)}
                        </time>
                        {!notice.url && (
                          <> · {t('notifications.unavailable')}</>
                        )}
                      </span>
                    </span>
                  </>
                );
                return (
                  <li
                    key={notice.id}
                    className={`group relative flex items-start gap-1 pr-2 ${notice.read_at ? 'bg-white' : 'bg-primary/5'}`}
                  >
                    {notice.url ? (
                      <a
                        href={notice.url}
                        className="flex min-w-0 flex-1 items-start gap-3 p-4 text-left hover:bg-slate-50 focus-visible:bg-slate-50 focus-visible:outline-none"
                        onClick={(event) => {
                          event.preventDefault();
                          if (!busy) void change('read', notice, true);
                        }}
                      >
                        {body}
                      </a>
                    ) : (
                      <div className="flex min-w-0 flex-1 items-start gap-3 p-4">
                        {body}
                      </div>
                    )}
                    <div className="flex shrink-0 flex-col gap-1 pt-3 opacity-100 sm:opacity-0 sm:group-focus-within:opacity-100 sm:group-hover:opacity-100">
                      {!notice.read_at && (
                        <button
                          type="button"
                          disabled={busy}
                          aria-label={t('notifications.read')}
                          title={t('notifications.read')}
                          className="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-primary-hover"
                          onClick={() => void change('read', notice)}
                        >
                          <Icon name="check" />
                        </button>
                      )}
                      <button
                        type="button"
                        disabled={busy}
                        aria-label={t('notifications.delete')}
                        title={t('notifications.delete')}
                        className="rounded p-1 text-slate-400 hover:bg-red-50 hover:text-red-600"
                        onClick={() => void change('delete', notice)}
                      >
                        <Icon name="trash" />
                      </button>
                    </div>
                  </li>
                );
              })}
          </ul>
          {(older || inbox?.next) && (
            <div className="flex justify-between gap-3 border-t border-slate-200 p-4 text-sm">
              {older && (
                <button
                  type="button"
                  className="underline"
                  onClick={() => void refresh()}
                >
                  {t('notifications.recent')}
                </button>
              )}
              {inbox?.next && (
                <button
                  type="button"
                  className="underline"
                  onClick={() => void refresh(inbox.next!)}
                >
                  {t('notifications.older')}
                </button>
              )}
            </div>
          )}
        </section>
      )}
    </Dropdown>
  );
}
