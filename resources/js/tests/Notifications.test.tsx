import {
  act,
  fireEvent,
  render,
  screen,
  waitFor,
} from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import NotificationMenu from '../components/admin/NotificationMenu';

const visit = vi.hoisted(() => vi.fn());
const words: Record<string, string> = {
  'notifications.title': 'Notifications',
  'notifications.unread': ':count unread',
  'notifications.read_all': 'Mark all as read',
  'notifications.read': 'Mark as read',
  'notifications.delete': 'Delete notification',
  'notifications.empty': 'No notifications yet.',
  'notifications.error': 'Could not update',
  'notifications.retry': 'Try again',
  'notifications.offline': 'Reconnecting',
  'notifications.loading': 'Loading',
  'notifications.older': 'Older notifications',
  'notifications.recent': 'Recent notifications',
};
vi.mock('@inertiajs/react', () => ({
  router: { visit },
  usePage: () => ({
    props: {
      auth: { user: { id: '1' } },
      i18n: { locale: 'en', strings: words },
    },
  }),
}));
class Stream extends EventTarget {
  static instances: Stream[] = [];
  onerror: (() => void) | null = null;
  close = vi.fn();
  constructor(public url: string) {
    super();
    Stream.instances.push(this);
  }
}
const notice = {
  id: '1',
  title: 'New order received',
  message: 'Order #100001 has been placed.',
  url: '/admin/orders/1',
  created_at: '2026-10-28T12:00:00Z',
  read_at: null as string | null,
};
let state = {
  revision: '1',
  unread: 1,
  items: [notice],
  next: null as string | null,
};
let fail = false;
beforeEach(() => {
  Stream.instances = [];
  visit.mockReset();
  fail = false;
  state = { revision: '1', unread: 1, items: [{ ...notice }], next: null };
  vi.stubGlobal('EventSource', Stream);
  vi.stubGlobal(
    'fetch',
    vi.fn(async (url: string, options: RequestInit) => {
      if (fail) throw new Error('offline');
      if (options.method === 'POST') {
        const { action } = JSON.parse(options.body as string);
        state = {
          ...state,
          revision: String(Number(state.revision) + 1),
          unread: 0,
          items:
            action === 'delete'
              ? []
              : state.items.map((item) => ({
                  ...item,
                  read_at: '2026-10-28T12:01:00Z',
                })),
        };
      }
      return { ok: true, json: async () => structuredClone(state) };
    }),
  );
});
afterEach(() => vi.unstubAllGlobals());
async function open() {
  render(<NotificationMenu />);
  await screen.findByLabelText('1 unread');
  fireEvent.click(screen.getByRole('button', { name: 'Notifications' }));
  await screen.findByText(notice.message);
}
it('renders the persisted badge, caps it and hides zero; replayed SSE reconciles without duplicates', async () => {
  await open();
  state.unread = 104;
  state.revision = '2';
  act(() =>
    Stream.instances[0].dispatchEvent(new Event('notification.created')),
  );
  await screen.findByText('99+');
  act(() =>
    Stream.instances[0].dispatchEvent(new Event('notification.created')),
  );
  await waitFor(() =>
    expect(screen.getAllByText(notice.message)).toHaveLength(1),
  );
  state.unread = 0;
  state.revision = '3';
  act(() =>
    Stream.instances[0].dispatchEvent(new Event('notifications.changed')),
  );
  await waitFor(() =>
    expect(screen.queryByText('99+')).not.toBeInTheDocument(),
  );
});
it('marks read before opening the existing order route', async () => {
  await open();
  fireEvent.click(screen.getByRole('link', { name: new RegExp(notice.title) }));
  await waitFor(() => expect(visit).toHaveBeenCalledWith('/admin/orders/1'));
  expect(state.items[0].read_at).not.toBeNull();
  expect(screen.queryByLabelText('1 unread')).not.toBeInTheDocument();
});
it('supports individual read, deletion and read-all with confirmed state', async () => {
  await open();
  fireEvent.click(screen.getByRole('button', { name: 'Mark as read' }));
  await waitFor(() =>
    expect(
      screen.queryByRole('button', { name: 'Mark as read' }),
    ).not.toBeInTheDocument(),
  );
  fireEvent.click(screen.getByRole('button', { name: 'Delete notification' }));
  await screen.findByText('No notifications yet.');
  state = { revision: '5', unread: 1, items: [{ ...notice }], next: null };
  act(() =>
    Stream.instances[0].dispatchEvent(new Event('notification.created')),
  );
  await screen.findByText(notice.message);
  fireEvent.click(screen.getByRole('button', { name: 'Mark all as read' }));
  await waitFor(() => expect(state.unread).toBe(0));
});
it('keeps persisted state on network failure and reconciles after reconnect', async () => {
  await open();
  fail = true;
  fireEvent.click(screen.getByRole('button', { name: 'Delete notification' }));
  await screen.findByRole('alert');
  expect(screen.getByText(notice.message)).toBeVisible();
  expect(screen.getByLabelText('1 unread')).toBeVisible();
  fail = false;
  state.unread = 2;
  state.revision = '2';
  act(() => Stream.instances[0].dispatchEvent(new Event('notifications.sync')));
  await screen.findByLabelText('2 unread');
  expect(screen.queryByRole('alert')).not.toBeInTheDocument();
});
it('closes on Escape with focus restored and releases its single stream on unmount', async () => {
  const view = render(<NotificationMenu />);
  await screen.findByLabelText('1 unread');
  const trigger = screen.getByRole('button', { name: 'Notifications' });
  fireEvent.click(trigger);
  await screen.findByText(notice.message);
  fireEvent.keyDown(document, { key: 'Escape' });
  expect(trigger).toHaveFocus();
  expect(screen.queryByText(notice.message)).not.toBeInTheDocument();
  view.rerender(<NotificationMenu />);
  expect(Stream.instances).toHaveLength(1);
  view.unmount();
  expect(Stream.instances[0].close).toHaveBeenCalledOnce();
});
it('clears private content and closes the stream on revocation', async () => {
  await open();
  act(() => Stream.instances[0].dispatchEvent(new Event('stream.revoked')));
  expect(
    screen.queryByRole('button', { name: 'Notifications' }),
  ).not.toBeInTheDocument();
  expect(Stream.instances[0].close).toHaveBeenCalled();
});

it('does not let a delayed older GET overwrite a confirmed mutation', async () => {
  await open();
  let finish: (value: unknown) => void = () => {};
  vi.mocked(fetch).mockImplementationOnce(
    () =>
      new Promise((resolve) => {
        finish = resolve as typeof finish;
      }),
  );
  act(() => Stream.instances[0].dispatchEvent(new Event('notifications.sync')));
  fireEvent.click(screen.getByRole('button', { name: 'Mark all as read' }));
  await waitFor(() =>
    expect(screen.queryByLabelText('1 unread')).not.toBeInTheDocument(),
  );
  await act(async () => {
    finish({
      ok: true,
      json: async () => ({
        revision: '1',
        unread: 1,
        items: [notice],
        next: null,
      }),
    });
  });
  expect(screen.queryByLabelText('1 unread')).not.toBeInTheDocument();
});

it('loads older pages using the server cursor', async () => {
  state.next = '10';
  await open();
  fireEvent.click(screen.getByRole('button', { name: 'Older notifications' }));
  await waitFor(() =>
    expect(fetch).toHaveBeenCalledWith(
      '/admin/notifications?before=10',
      expect.objectContaining({ method: 'GET' }),
    ),
  );
  await screen.findByRole('button', { name: 'Recent notifications' });
});

it('applies a newer mutation revision even if a later-started GET returns old data', async () => {
  await open();
  let finish: (value: unknown) => void = () => {};
  vi.mocked(fetch).mockImplementationOnce(
    () =>
      new Promise((resolve) => {
        finish = resolve as typeof finish;
      }),
  );
  fireEvent.click(screen.getByRole('button', { name: 'Mark all as read' }));
  act(() => Stream.instances[0].dispatchEvent(new Event('notifications.sync')));
  await act(async () => {
    finish({
      ok: true,
      json: async () => ({
        revision: '2',
        unread: 0,
        items: [{ ...notice, read_at: '2026-10-28T12:00:00Z' }],
        next: null,
      }),
    });
  });
  expect(screen.queryByLabelText('1 unread')).not.toBeInTheDocument();
});
