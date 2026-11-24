import { fireEvent, render, screen, within } from '@testing-library/react';
import { router } from '@inertiajs/react';
import type { AnchorHTMLAttributes } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import Index from '../pages/Admin/CallRequests/Index';
import Form from '../pages/Admin/CallRequests/Form';
import type { SharedProps } from '../types/page';
import type { CallRequestRow, CallStatus } from '../types/callRequests';
import { english } from './translations';

const shared = vi.hoisted(() => ({ props: {} as SharedProps }));
vi.mock('@inertiajs/react', async (original) => ({
  ...(await original<typeof import('@inertiajs/react')>()),
  usePage: () => ({ url: '/admin', props: shared.props }),
  Head: () => null,
  Link: ({ children, ...props }: AnchorHTMLAttributes<HTMLAnchorElement>) => (
    <a {...props}>{children}</a>
  ),
}));
const shell = { navigation: [], breadcrumbs: [] };
const statuses: CallStatus[] = ['new', 'in_progress', 'completed', 'no_answer'];
const row: CallRequestRow = {
  id: '7',
  version: 3,
  name: 'Maria',
  phone: '+30 210 1234567',
  convenient_time: '09:00–20:00',
  status: 'new',
  responsible_id: '',
  responsible_name: null,
  responsible_active: false,
  order_id: null,
  order_number: '',
  order_status: null,
  created_at: '2026-10-13T09:00:00Z',
  updated_at: '2026-10-13T09:00:00Z',
  notes_preview: 'Please call about delivery',
  notes_truncated: true,
  product_count: 0,
};
const requests = {
  data: [row],
  total: 1,
  current_page: 1,
  last_page: 1,
  prev_page_url: null,
  next_page_url: null,
};
const labels = {
  'calls.title': 'Call requests',
  'calls.create': 'New call request',
  'calls.notes': 'Notes',
  'calls.delete': 'Delete call request',
  'calls.confirm_delete': 'Delete :count call request?',
  'calls.status_new': 'New',
  'calls.status_completed': 'Completed',
  'calls.status_in_progress': 'In progress',
  'calls.status_no_answer': 'No answer',
  'calls.status_id': 'Status of request #:id',
  'calls.open_id': 'Open call request #:id',
  'calls.select_id': 'Select request #:id',
  'calls.delete_selected': 'Delete selected',
};
beforeEach(() => {
  vi.restoreAllMocks();
  shared.props = {
    app: { name: 'CMS' },
    auth: {
      user: {
        id: '1',
        name: 'Admin',
        email: 'a@example.test',
        emailVerified: true,
      },
      canAccessAdmin: true,
    },
    flash: { status: null },
    capabilities: {
      'call-requests.view': true,
      'call-requests.manage': true,
      'call-requests.delete': true,
    },
    i18n: {
      locale: 'en',
      direction: 'ltr',
      strings: { ...english, ...labels },
      locales: [{ code: 'en', name: 'English' }],
    },
    links: { logout: '/logout' } as SharedProps['links'],
  } as SharedProps;
});
it('keeps a truncated notes link on the same request row', () => {
  render(
    <Index
      {...shell}
      requests={requests}
      filters={{}}
      statuses={statuses}
      assignees={[]}
    />,
  );
  const tr = screen.getByText('Maria').closest('tr')!;
  const notes = within(tr).getByText('Please call about delivery…');
  expect(notes).toHaveClass('truncate');
  expect(notes).toHaveAttribute('href', '/admin/call-requests/7/edit');
  expect(screen.getAllByRole('row')).toHaveLength(2);
});
it('sends the current version when changing a request status', () => {
  const patch = vi.spyOn(router, 'patch').mockImplementation(() => undefined);
  render(
    <Index
      {...shell}
      requests={requests}
      filters={{}}
      statuses={statuses}
      assignees={[]}
    />,
  );
  fireEvent.click(
    screen.getByRole('combobox', { name: 'Status of request #7' }),
  );
  fireEvent.click(screen.getByRole('option', { name: 'Completed' }));
  expect(patch).toHaveBeenCalledWith(
    '/admin/call-requests/7',
    expect.objectContaining({ version: 3, status: 'completed' }),
    expect.any(Object),
  );
});
it('hides mutations for view-only staff and shows full notes in details', () => {
  shared.props.capabilities = { 'call-requests.view': true };
  const view = render(
    <Index
      {...shell}
      requests={requests}
      filters={{}}
      statuses={statuses}
      assignees={[]}
    />,
  );
  expect(screen.queryByRole('link', { name: 'New call request' })).toBeNull();
  expect(
    screen.queryByRole('combobox', { name: 'Status of request #7' }),
  ).toBeNull();
  expect(screen.queryByRole('checkbox')).toBeNull();
  view.unmount();
  const { notes_preview, notes_truncated, product_count, ...detail } = row;
  void notes_preview;
  void notes_truncated;
  void product_count;
  const fullNotes = 'Please call about delivery\n' + 'Full detail '.repeat(100);
  render(
    <Form
      {...shell}
      callRequest={{ ...detail, notes: fullNotes, products: [] }}
      statuses={statuses}
      assignees={[]}
      productOptions={[]}
    />,
  );
  expect(
    screen.getByText(
      (_, element) =>
        element?.tagName === 'P' && element.textContent === fullNotes,
    ),
  ).toBeVisible();
  expect(screen.queryByRole('button', { name: 'Save' })).toBeNull();
});

it('deletes only selected visible requests using their saved versions', () => {
  const remove = vi.spyOn(router, 'delete').mockImplementation(() => undefined);
  vi.spyOn(window, 'confirm').mockReturnValue(true);
  const second = { ...row, id: '8', version: 4, name: 'Second caller' };
  const view = render(
    <Index
      {...shell}
      requests={{ ...requests, data: [row, second], total: 2 }}
      filters={{}}
      statuses={statuses}
      assignees={[]}
    />,
  );
  fireEvent.click(screen.getByRole('checkbox', { name: 'Select request #7' }));
  fireEvent.click(screen.getByRole('checkbox', { name: 'Select request #8' }));
  fireEvent.click(screen.getByRole('button', { name: 'Delete selected' }));
  expect(remove).toHaveBeenCalledWith(
    '/admin/call-requests/bulk',
    expect.objectContaining({
      data: expect.objectContaining({
        rows: [
          { id: '7', version: 3 },
          { id: '8', version: 4 },
        ],
      }),
    }),
  );
  view.rerender(
    <Index
      {...shell}
      requests={{ ...requests, data: [{ ...row, id: '9' }] }}
      filters={{}}
      statuses={statuses}
      assignees={[]}
    />,
  );
  expect(screen.queryByRole('button', { name: 'Delete selected' })).toBeNull();
});

it('keeps Delete in the header menu after Save and confirms with the shared dialog', () => {
  const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  const confirm = vi.spyOn(window, 'confirm');
  render(
    <Form
      {...shell}
      callRequest={{ ...row, notes: '', products: [] }}
      statuses={statuses}
      assignees={[]}
      productOptions={[]}
    />,
  );
  const actions = screen.getByRole('button', { name: 'Actions' });
  expect(
    screen.queryByRole('button', { name: 'Delete call request' }),
  ).toBeNull();
  expect(
    screen
      .getByRole('button', { name: 'Save changes' })
      .compareDocumentPosition(actions) & Node.DOCUMENT_POSITION_FOLLOWING,
  ).toBeTruthy();
  fireEvent.click(actions);
  const trash = screen.getByRole('button', { name: 'Delete call request' });
  expect(trash.querySelector('svg')).not.toBeNull();
  fireEvent.click(trash);
  expect(visit).not.toHaveBeenCalled();
  fireEvent.click(
    within(screen.getByRole('alertdialog')).getByRole('button', {
      name: 'Delete call request',
    }),
  );
  expect(confirm).not.toHaveBeenCalled();
  expect(visit.mock.calls[0][0]).toBe('/admin/call-requests/7');
  expect(visit.mock.calls[0][1]).toMatchObject({
    method: 'delete',
    data: { version: 3 },
  });
  expect(visit).toHaveBeenCalledTimes(1);
});

it('hides the delete action without its permission', () => {
  shared.props.capabilities['call-requests.delete'] = false;
  render(
    <Form
      {...shell}
      callRequest={{ ...row, notes: '', products: [] }}
      statuses={statuses}
      assignees={[]}
      productOptions={[]}
    />,
  );
  expect(
    screen.queryByRole('button', { name: 'Delete call request' }),
  ).not.toBeInTheDocument();
});
