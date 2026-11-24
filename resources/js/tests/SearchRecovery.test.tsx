import { fireEvent, render, screen } from '@testing-library/react';
import { router } from '@inertiajs/react';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { expect, it, vi } from 'vitest';
import SearchHealthWarning from '../components/admin/SearchHealthWarning';
import SearchSettings from '../pages/Admin/Settings/Search';

vi.mock('../lib/useTranslation', () => ({
  useTranslation: () => (key: string) => key,
}));
vi.mock('../layouts/admin/AdminLayout', () => ({
  default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));
vi.mock('@inertiajs/react', async (original) => ({
  ...(await original<typeof import('@inertiajs/react')>()),
  Link: ({ children, ...props }: AnchorHTMLAttributes<HTMLAnchorElement>) => (
    <a {...props}>{children}</a>
  ),
  usePage: () => ({ props: { errors: {}, preferences: {} } }),
}));

const attention = {
  status: 'attention' as const,
  pending: 3,
  hidden: 2,
  errors: 2,
  failed: 1,
  stalled: 0,
};
const props = {
  navigation: [],
  breadcrumbs: [],
  recovery: attention,
  engine: {
    id: 'test',
    name: 'Engine',
    version: 1,
    fields: [],
    built: true,
    health: {
      reachable: true,
      ready: true,
      current: true,
      documents: 10,
      index: 'products',
      version: '1',
      message: null,
    },
  },
  catalog: { eligible: 10 },
  outbox: { pending: 3, critical: 2, failed: 1, oldest: null, errors: [] },
  state: {
    built_at: null,
    last_sync_at: null,
    last_reconcile_at: null,
    last_reconcile: null,
    last_error_at: null,
    last_error: null,
  },
  runs: [],
};
it('warns about active indexing problems and links to recovery; healthy and absent state stay quiet', () => {
  const view = render(<SearchHealthWarning health={attention} />);
  expect(screen.getByRole('alert')).toHaveTextContent(
    'search.health_attention',
  );
  expect(screen.getByRole('link')).toHaveAttribute(
    'href',
    '/admin/settings/search',
  );
  view.rerender(
    <SearchHealthWarning health={{ ...attention, status: 'healthy' }} />,
  );
  expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  view.rerender(<SearchHealthWarning health={null} />);
  expect(screen.queryByRole('alert')).not.toBeInTheDocument();
});
it('offers a one-click repair and keeps technical details collapsed', () => {
  const post = vi.spyOn(router, 'post').mockImplementation(() => {});
  const { container } = render(<SearchSettings {...props} />);
  expect(screen.getByRole('status')).toHaveTextContent(
    'search.health_attention',
  );
  expect(container.querySelector('details')).not.toHaveAttribute('open');
  fireEvent.click(screen.getByRole('button', { name: 'search.repair_action' }));
  expect(post).toHaveBeenCalledWith(
    '/admin/settings/search/repair',
    {},
    expect.objectContaining({ preserveScroll: true }),
  );
});
it('explains an unreachable service and prevents an ineffective repair', () => {
  render(
    <SearchSettings
      {...props}
      engine={{
        ...props.engine,
        health: { ...props.engine.health, reachable: false },
      }}
    />,
  );
  expect(
    screen.getByRole('button', { name: 'search.repair_action' }),
  ).toBeDisabled();
  expect(screen.getByText('search.repair_unreachable')).toBeVisible();
});
