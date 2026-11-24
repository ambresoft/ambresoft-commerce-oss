import { act, fireEvent, render, screen, within } from '@testing-library/react';
import type { AnchorHTMLAttributes } from 'react';
import { afterEach, expect, it, vi } from 'vitest';
import Sidebar from '../components/admin/Sidebar';
import { english } from './translations';

vi.mock('@inertiajs/react', () => ({
  usePage: () => ({ props: { i18n: { strings: english } } }),
  Link: ({ children, ...props }: AnchorHTMLAttributes<HTMLAnchorElement>) => (
    <a {...props}>{children}</a>
  ),
}));

const item = (id: string, label: string, group?: [string, string]) => ({
  id,
  label,
  href: '/admin/' + id,
  active: false,
  ...(group ? { group: { id: group[0], label: group[1] } } : {}),
});

afterEach(() => vi.useRealTimers());

it('opens a collapsed group on hover and closes it with Escape while retaining its active route', () => {
  vi.useFakeTimers();
  render(
    <Sidebar
      collapsed
      navigation={[
        item('core.dashboard', 'Dashboard'),
        {
          ...item('core.order-list', 'All orders', ['core.orders', 'Orders']),
          active: true,
        },
      ]}
    />,
  );
  const trigger = screen.getByRole('button', { name: 'Orders' });
  expect(trigger).toHaveAttribute('aria-expanded', 'false');
  expect(screen.queryByRole('link', { name: 'All orders' })).toBeNull();
  fireEvent.mouseEnter(trigger.parentElement!);
  expect(trigger).toHaveAttribute('aria-expanded', 'false');
  act(() => vi.advanceTimersByTime(120));
  expect(trigger).toHaveAttribute('aria-expanded', 'true');
  expect(screen.getByRole('link', { name: 'All orders' })).toHaveAttribute(
    'aria-current',
    'page',
  );
  expect(screen.queryByRole('link', { name: 'Staff users' })).toBeNull();
  fireEvent.keyDown(document, { key: 'Escape' });
  expect(trigger).toHaveFocus();
  expect(screen.queryByRole('link', { name: 'All orders' })).toBeNull();
});

it('ignores quick hover passes and keeps only the most recently hovered group open', () => {
  vi.useFakeTimers();
  render(
    <Sidebar
      collapsed
      navigation={[
        item('core.order-list', 'All orders', ['core.orders', 'Orders']),
        item('core.products', 'All products', ['core.catalog', 'Catalog']),
      ]}
    />,
  );
  const orders = screen.getByRole('button', { name: 'Orders' });
  const catalog = screen.getByRole('button', { name: 'Catalog' });
  fireEvent.mouseEnter(orders.parentElement!);
  act(() => vi.advanceTimersByTime(60));
  fireEvent.mouseLeave(orders.parentElement!);
  fireEvent.mouseEnter(catalog.parentElement!);
  act(() => vi.advanceTimersByTime(120));
  expect(orders).toHaveAttribute('aria-expanded', 'false');
  expect(catalog).toHaveAttribute('aria-expanded', 'true');
  fireEvent.mouseLeave(catalog.parentElement!);
  fireEvent.mouseEnter(orders.parentElement!);
  act(() => vi.advanceTimersByTime(120));
  expect(orders).toHaveAttribute('aria-expanded', 'true');
  expect(catalog).toHaveAttribute('aria-expanded', 'false');
  act(() => vi.advanceTimersByTime(150));
  expect(orders).toHaveAttribute('aria-expanded', 'true');
  expect(screen.getAllByRole('button', { expanded: true })).toHaveLength(1);
});

it('keeps nested navigation available from the icon rail and closes the flyout after navigation', () => {
  const onNavigate = vi.fn();
  render(
    <Sidebar
      collapsed
      onNavigate={onNavigate}
      navigation={[
        {
          ...item('core.products', 'All products'),
          group: {
            id: 'core.catalog-products',
            label: 'Products',
            parent: { id: 'core.catalog', label: 'Catalog' },
          },
        },
      ]}
    />,
  );
  const trigger = screen.getByRole('button', { name: 'Catalog' });
  fireEvent.click(trigger);
  fireEvent.click(screen.getByText('Products'));
  const link = screen.getByRole('link', { name: 'All products' });
  link.addEventListener('click', (event) => event.preventDefault());
  fireEvent.click(link);
  expect(onNavigate).toHaveBeenCalledOnce();
  expect(trigger).toHaveAttribute('aria-expanded', 'false');
});

it('orders real sections in their registered order', () => {
  render(
    <Sidebar
      navigation={[
        item('core.dashboard', 'Dashboard'),
        item('core.order-list', 'All orders', ['core.orders', 'Orders']),
        item('core.products', 'Products', [
          'core.catalog-products',
          'Products',
        ]),
        item('core.categories', 'Categories'),
        item('core.users', 'Staff users', ['core.access', 'Users']),
        item('core.banners', 'Banners', ['core.marketing', 'Marketing']),
        item('core.menus', 'Menus', ['core.website', 'Website']),
        item('core.addons', 'Add-ons'),
        item('core.logs', 'Audit Logs'),
        item('core.data-transfer', 'Imports / Exports'),
        item('core.languages', 'Languages', ['core.settings', 'Settings']),
      ]}
    />,
  );
  const nav = screen.getByRole('navigation', { name: 'Administration' });
  const rows = within(nav)
    .getAllByRole('listitem')
    .filter((li) => li.parentElement?.parentElement === nav)
    .map((li) => li.querySelector('.truncate')?.textContent);
  expect(rows).toEqual([
    'Dashboard',
    'Orders',
    'Products',
    'Categories',
    'Users',
    'Marketing',
    'Website',
    'Add-ons',
    'Audit Logs',
    'Imports / Exports',
    'Settings',
  ]);
  // Every row is a real section: no "coming soon" placeholders remain.
  expect(within(nav).queryByText('Soon')).toBeNull();
  expect(within(nav).getByRole('link', { name: 'All orders' })).toHaveAttribute(
    'href',
    '/admin/core.order-list',
  );
  expect(within(nav).getByRole('link', { name: 'Banners' })).toHaveAttribute(
    'href',
    '/admin/core.banners',
  );
  expect(within(nav).getByRole('link', { name: 'Add-ons' })).toHaveAttribute(
    'href',
    '/admin/core.addons',
  );
  expect(within(nav).getAllByRole('link')).toHaveLength(11);
});
