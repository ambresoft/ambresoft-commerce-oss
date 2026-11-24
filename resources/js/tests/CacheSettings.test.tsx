import { act, fireEvent, render, screen } from '@testing-library/react';
import { router } from '@inertiajs/react';
import type { ReactNode, AnchorHTMLAttributes } from 'react';
import { expect, it, vi } from 'vitest';
import CacheSettings from '../pages/Admin/Settings/Cache';

vi.mock('../lib/useTranslation', () => ({
  useTranslation:
    () =>
    (key: string, replacements: Record<string, string> = {}) =>
      key + (replacements.group ? ':' + replacements.group : ''),
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
const props = {
  navigation: [],
  breadcrumbs: [],
  settings: { enabled: false, version: 1 },
  reachable: true,
  groups: ['blocks', 'products', 'categories', 'brands', 'media'] as const,
};
it('offers independent category clearing and full storefront clearing', () => {
  const post = vi.spyOn(router, 'post').mockImplementation(() => {});
  render(<CacheSettings {...props} groups={[...props.groups]} />);
  fireEvent.click(
    screen.getByRole('button', { name: 'cache.clear_group:cache.categories' }),
  );
  expect(post).toHaveBeenCalledWith(
    '/admin/cache/clear',
    { group: 'categories' },
    expect.any(Object),
  );
  fireEvent.click(screen.getByRole('button', { name: 'cache.clear_all' }));
  expect(post).toHaveBeenLastCalledWith(
    '/admin/cache/clear',
    { group: 'all' },
    expect.any(Object),
  );
});
it('saves enabled state and version through the header and explains unavailable Redis', () => {
  const put = vi.spyOn(router, 'put').mockImplementation(() => {});
  render(
    <CacheSettings {...props} reachable={false} groups={[...props.groups]} />,
  );
  expect(screen.getByRole('status')).toHaveTextContent('cache.unavailable');
  fireEvent.click(screen.getByRole('combobox', { name: 'cache.status' }));
  fireEvent.click(screen.getByRole('option', { name: 'cache.enabled' }));
  fireEvent.click(screen.getByRole('button', { name: 'cache.save' }));
  expect(put).toHaveBeenCalledWith(
    '/admin/cache',
    { enabled: true, version: 1 },
    expect.any(Object),
  );
});

it('shows partial-clear confirmation beside the action, including repeated clears', async () => {
  const post = vi.spyOn(router, 'post').mockImplementation(() => {});
  render(<CacheSettings {...props} groups={[...props.groups]} />);
  const button = screen.getByRole('button', {
    name: 'cache.clear_group:cache.categories',
  });
  for (let attempt = 0; attempt < 2; attempt++) {
    fireEvent.click(button);
    const options = post.mock.calls.at(-1)![2]!;
    await act(async () => {
      await options.onSuccess?.({
        props: { flash: { status: 'Category cache cleared successfully.' } },
      } as never);
    });
    expect(button.parentElement).toHaveTextContent(
      'Category cache cleared successfully.',
    );
    expect(
      button.parentElement?.querySelector('[role="status"]'),
    ).toHaveTextContent('Category cache cleared successfully.');
  }
});
