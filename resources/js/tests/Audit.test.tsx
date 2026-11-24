import { fireEvent, render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import AuditIndex from '../pages/Admin/Audit/Index';
import AuditShow from '../pages/Admin/Audit/Show';
import type { AuditDetails } from '../types/audit';
const mocks = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock('@inertiajs/react', () => ({
  router: { get: mocks.get },
  Link: ({ href, children }: { href: string; children: React.ReactNode }) => (
    <a href={href}>{children}</a>
  ),
  usePage: () => ({
    props: {
      errors: {},
      i18n: {
        locale: 'en',
        strings: Object.fromEntries(
          [
            'title',
            'anonymous',
            'ip_address',
            'source',
            'advanced',
            'summary',
            'before',
            'after',
            'field',
            'no_changes',
            'technical',
            'not_available',
            'search',
            'actor',
            'action',
            'entity',
            'outcome',
            'from',
            'to',
            'sort',
            'apply',
            'time',
            'description',
            'details',
            'empty',
            'system',
            'loading',
            'changes',
            'metadata',
            'success',
            'failure',
            'denied',
            'all',
            'back',
            'asc',
            'desc',
          ]
            .map((k) => ['audit.' + k, k])
            .concat([
              ['common.search', 'Search'],
              ['common.all', 'All'],
              ['audit.entities.product', 'Product'],
              ['audit.verbs.updated', 'Edited'],
              ['audit.system_source', 'System automation'],
            ]),
        ),
      },
    },
  }),
}));
vi.mock('../layouts/admin/AdminLayout', () => ({
  default: ({ children }: { children: React.ReactNode }) => (
    <main>{children}</main>
  ),
}));
const shell = { navigation: [], breadcrumbs: [] };
const log: AuditDetails = {
  id: '1',
  occurred_at: '2026-10-10T12:00:00Z',
  actor_id: '5',
  actor_email: 'staff@example.test',
  actor_roles: ['auditor'],
  action: 'product.updated',
  entity_type: 'product',
  entity_id: '9',
  entity_label: '<img src=x onerror=alert(1)>',
  description: 'product updated',
  status: 'success',
  source: 'admin_ui',
  request_id: 'test',
  ip_address: '192.0.2.10',
  user_agent: null,
  changes: { price: { before: '49.990000', after: '59.990000' } },
  metadata: {},
};
it('submits server filters and displays outcome, pagination and safe entity labels', () => {
  render(
    <AuditIndex
      {...shell}
      filters={{}}
      logs={{
        data: [log],
        current_page: 1,
        last_page: 2,
        prev_page_url: null,
        next_page_url: '/admin/audit-logs?page=2',
        total: 31,
      }}
    />,
  );
  fireEvent.change(screen.getByLabelText('Search'), {
    target: { value: 'staff@example.test' },
  });
  fireEvent.submit(screen.getByRole('search'));
  expect(mocks.get).toHaveBeenCalledWith(
    '/admin/audit-logs',
    { search: 'staff@example.test' },
    expect.any(Object),
  );
  fireEvent.change(screen.getByLabelText('actor'), { target: { value: '5' } });
  fireEvent.click(screen.getByRole('button', { name: 'apply' }));
  expect(mocks.get).toHaveBeenCalledWith(
    '/admin/audit-logs',
    expect.objectContaining({
      actor: '5',
    }),
    expect.any(Object),
  );
  expect(screen.getByText('192.0.2.10')).toBeInTheDocument();
  expect(screen.getByText('Product · Edited')).toBeInTheDocument();
  expect(screen.getByText('success')).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'details' })).toHaveAttribute(
    'href',
    '/admin/audit-logs/1',
  );
  expect(document.querySelector('img')).toBeNull();
  expect(screen.getByText(log.entity_label!)).toBeInTheDocument();
});
it('shows empty state and structured, escaped change details', () => {
  const { unmount } = render(
    <AuditIndex
      {...shell}
      filters={{}}
      logs={{
        data: [],
        current_page: 1,
        last_page: 1,
        prev_page_url: null,
        next_page_url: null,
        total: 0,
      }}
    />,
  );
  expect(screen.getByText('empty')).toBeInTheDocument();
  unmount();
  render(<AuditShow {...shell} log={log} />);
  expect(screen.getByText(/49.990000/)).toBeInTheDocument();
  expect(screen.getByText(/59.990000/)).toBeInTheDocument();
  expect(document.querySelector('img')).toBeNull();
});

it('distinguishes a system task from an unauthenticated visitor and keeps technical data secondary', () => {
  const { unmount } = render(
    <AuditShow
      {...shell}
      log={{
        ...log,
        actor_id: null,
        actor_email: null,
        source: 'system',
        changes: {},
      }}
    />,
  );
  expect(screen.getByText('system')).toBeInTheDocument();
  expect(screen.queryByText('anonymous')).toBeNull();
  expect(screen.getByText('no_changes')).toBeInTheDocument();
  expect(screen.getByText('technical').closest('details')).not.toHaveAttribute(
    'open',
  );
  unmount();
  render(
    <AuditShow
      {...shell}
      log={{ ...log, actor_id: null, actor_email: null, source: 'web' }}
    />,
  );
  expect(screen.getByText('anonymous')).toBeInTheDocument();
});
