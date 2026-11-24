import { fireEvent, render, screen } from '@testing-library/react';
import { router } from '@inertiajs/react';
import type { AnchorHTMLAttributes } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import MenuForm from '../pages/Admin/Menus/Form';
import ItemForm from '../pages/Admin/Menus/ItemForm';
import Items from '../pages/Admin/Menus/Items';
import type { Menu } from '../types/menus';
import { choose } from './select';
import type { SharedProps } from '../types/page';
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
const strings = {
  'menus.title': 'Menus',
  'menus.edit': 'Edit menu',
  'menus.create': 'Create menu',
  'menus.tab_general': 'General',
  'menus.tab_settings': 'Settings',
  'menus.tab_appearance': 'Appearance',
  'menus.source': 'Menu content',
  'menus.placement': 'Purpose',
  'menus.manual': 'Manual items',
  'menus.categories': 'All categories',
  'menus.header': 'Header',
  'menus.footer': 'Footer',
  'menus.other': 'Other',
  'menus.active': 'Active',
  'menus.disabled': 'Disabled',
  'menus.mode_confirm': 'Confirm preserved :count manual items',
  'menus.back': 'Back to menus',
  'menus.root': 'Top level',
  'menus.parent': 'Parent item',
  'menus.position': 'Position',
  'menus.target': 'Link target',
  'menus.url': 'Custom link',
  'menus.category': 'Category',
  'menus.heading': 'Heading',
  'menus.generate_children': 'Generate category submenu',
  'menus.description': 'Short description',
  'menus.image_alt': 'Image alternative text',
  'menus.image': 'Menu item image',
  'menus.new_window': 'Open in a new window',
  'menus.add_item': 'Add item',
  'menus.edit_item': 'Edit item',
  'menus.add_child': 'Add child',
  'menus.settings': 'Menu settings',
  'menus.generated_help': 'Live read-only category preview',
  'menus.generated_submenu': 'Category submenu',
  'menus.children_count': ':count children',
  'menus.find_category': 'Find category',
  'menus.find_parent': 'Find parent',
  'menus.delete_item_confirm': 'Delete subtree?',
};
const shell = { navigation: [], breadcrumbs: [] };
const menu: Menu = {
  id: '1',
  status: 'active',
  placement: 'header',
  source: 'manual',
  version: 5,
  item_count: 3,
  translations: { en: { name: 'Main' }, el: { name: 'Κεντρικό' } },
};
beforeEach(() => {
  vi.restoreAllMocks();
  shared.props = {
    app: { name: 'CMS' },
    auth: {
      user: {
        id: '1',
        name: 'Admin',
        email: 'admin@example.test',
        emailVerified: true,
      },
      canAccessAdmin: true,
    },
    flash: { status: null },
    capabilities: { 'menus.view': true, 'menus.manage': true },
    i18n: {
      locale: 'en',
      direction: 'ltr',
      strings: { ...english, ...strings },
      locales: [
        { code: 'en', name: 'English' },
        { code: 'el', name: 'Ελληνικά' },
      ],
    },
    links: { logout: '/logout' } as SharedProps['links'],
  };
});

it('keeps independent translated names and confirms preserved manual items when changing modes', () => {
  const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
  render(<MenuForm {...shell} menu={menu} defaultLocale="en" />);
  fireEvent.change(screen.getByLabelText('Name · en'), {
    target: { value: 'Updated main' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Ελληνικά' }));
  expect(screen.getByLabelText('Name · el')).toHaveValue('Κεντρικό');
  expect(screen.getByLabelText('Name · el')).not.toHaveAttribute(
    'aria-required',
  );
  fireEvent.click(screen.getByRole('tab', { name: 'Settings' }));
  choose(
    screen.getByRole('combobox', { name: 'Menu content' }),
    'All categories',
  );
  fireEvent.click(
    screen.getByRole('checkbox', { name: 'Confirm preserved 3 manual items' }),
  );
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
  expect(post.mock.calls[0][0]).toBe('/admin/menus/1');
  expect(post.mock.calls[0][1]).toMatchObject({
    version: 5,
    source: 'categories',
    confirmed: true,
    translations: { en: { name: 'Updated main' }, el: { name: 'Κεντρικό' } },
  });
});

it('builds a nested category item and preserves fields across appearance tabs', () => {
  const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
  render(
    <ItemForm
      {...shell}
      menu={menu}
      item={null}
      initialParent="2"
      parents={[{ id: '2', name: 'Technology' }]}
      categories={[{ id: '8', name: 'Laptops', status: 'active' }]}
      defaultLocale="en"
    />,
  );
  fireEvent.change(screen.getByLabelText('Name · en'), {
    target: { value: 'Laptops' },
  });
  choose(screen.getByRole('combobox', { name: 'Link target' }), 'Category');
  choose(screen.getByRole('combobox', { name: 'Category' }), 'Laptops (#8)');
  fireEvent.click(
    screen.getByRole('checkbox', { name: 'Generate category submenu' }),
  );
  fireEvent.click(screen.getByRole('tab', { name: 'Appearance' }));
  fireEvent.change(screen.getByLabelText('Short description'), {
    target: { value: 'Our portable computers' },
  });
  fireEvent.click(screen.getByRole('tab', { name: 'General' }));
  expect(screen.getByLabelText('Name · en')).toHaveValue('Laptops');
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
  expect(post.mock.calls[0][1]).toMatchObject({
    version: 5,
    parent_id: '2',
    kind: 'category',
    category_id: '8',
    generate_children: true,
    translations: { en: { description: 'Our portable computers' } },
  });
});

it('renders generated category branches without manual edit or delete actions', () => {
  render(
    <Items
      {...shell}
      menu={{ ...menu, source: 'categories' }}
      name="Main"
      parent={null}
      generated
      generatedFrom={null}
      categoryParent={null}
      items={{
        data: [
          {
            id: '8',
            name: 'Technology',
            status: 'active',
            position: 10,
            children_count: 1,
          },
        ],
        current_page: 1,
        last_page: 1,
        total: 1,
        prev_page_url: null,
        next_page_url: null,
      }}
    />,
  );
  expect(screen.getByText('Live read-only category preview')).toBeVisible();
  expect(screen.getByRole('link', { name: '1 children' })).toHaveAttribute(
    'href',
    '/admin/menus/1?category_parent=8',
  );
  expect(
    screen.queryByRole('link', { name: 'Add item' }),
  ).not.toBeInTheDocument();
  expect(
    screen.queryByRole('button', { name: 'Delete' }),
  ).not.toBeInTheDocument();
});

it('hides mutation controls and disables inputs for menu readers', () => {
  shared.props.capabilities['menus.manage'] = false;
  render(<MenuForm {...shell} menu={menu} defaultLocale="en" />);
  expect(screen.getByLabelText('Name · en')).toBeDisabled();
  fireEvent.click(screen.getByRole('button', { name: 'Ελληνικά' }));
  expect(screen.getByLabelText('Name · el')).toHaveValue('Κεντρικό');
  expect(screen.getByLabelText('Name · el')).toBeDisabled();
  expect(
    screen.queryByRole('button', { name: 'Save changes' }),
  ).not.toBeInTheDocument();
  expect(
    screen.queryByRole('button', { name: 'Delete' }),
  ).not.toBeInTheDocument();
});
