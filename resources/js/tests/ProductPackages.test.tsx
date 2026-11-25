import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import type { ReactNode } from 'react';
import Packages from '../pages/Admin/ProductPackages/Index';
import { router } from '@inertiajs/react';

vi.mock('../layouts/admin/AdminLayout', () => ({
  default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));
vi.mock('../lib/useUnsavedChanges', () => ({ useDirtyForm: vi.fn() }));
vi.mock('../lib/useTranslation', () => ({
  useTranslation: () => (key: string) => key,
}));
vi.mock('@inertiajs/react', async (original) => {
  const actual = await original<typeof import('@inertiajs/react')>();
  return {
    ...actual,
    usePage: () => ({
      props: {
        i18n: {
          locale: 'en',
          locales: [
            { code: 'en', name: 'English' },
            { code: 'el', name: 'Ελληνικά' },
          ],
        },
        capabilities: { 'products.update': true },
      },
    }),
    router: Object.assign(actual.router, { get: vi.fn() }),
  };
});
const product = {
  id: '11',
  name: 'Protective case',
  sku: 'CASE-11',
  image: null,
  price: '€20.00',
  status: 'active',
  deleted: false,
};
const props = {
  navigation: [],
  breadcrumbs: [],
  packages: [{ id: '1', name: 'Phone accessories' }],
  selected: {
    id: '1',
    version: 1,
    translations: { en: 'Phone accessories', el: 'Αξεσουάρ' },
    items: [] as string[],
    assigned: [],
    assigned_count: 0,
    assigned_page: 1,
  },
  items: [],
  candidates: [],
  categories: [{ id: '2', name: 'Phones' }],
};
beforeEach(() => vi.clearAllMocks());
it('starts with an empty search and submits only the requested query', () => {
  render(<Packages {...props} />);
  expect(
    within(
      screen.getByRole('tabpanel', { name: /packages.contents/ }),
    ).getByText('packages.search_empty'),
  ).toBeVisible();
  expect(screen.queryByText(product.name)).not.toBeInTheDocument();
  const search = screen.getAllByRole('searchbox', { name: 'common.search' })[0]
    .id;
  // There are separate searches for packages and products.
  expect(search).toBe('package-search');
  const input = document.getElementById('contents-search')!;
  fireEvent.change(input, { target: { value: 'CASE-11' } });
  fireEvent.keyDown(input, { key: 'Enter' });
  expect(router.get).toHaveBeenCalledWith(
    '/admin/product-packages',
    { package: '1', search: 'CASE-11' },
    expect.objectContaining({
      only: ['candidates', 'productSearch'],
      preserveState: true,
    }),
  );
});
it('adds and removes a search result and keeps translated names while switching languages', () => {
  render(<Packages {...props} candidates={[product]} productSearch="case" />);
  fireEvent.click(screen.getByRole('button', { name: 'Ελληνικά' }));
  expect(screen.getByRole('textbox', { name: 'packages.name' })).toHaveValue(
    'Αξεσουάρ',
  );
  fireEvent.change(screen.getByRole('textbox', { name: 'packages.name' }), {
    target: { value: 'Θήκες' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'English' }));
  expect(screen.getByRole('textbox', { name: 'packages.name' })).toHaveValue(
    'Phone accessories',
  );
  fireEvent.click(screen.getByRole('button', { name: 'Ελληνικά' }));
  expect(screen.getByRole('textbox', { name: 'packages.name' })).toHaveValue(
    'Θήκες',
  );
  fireEvent.click(screen.getByRole('button', { name: 'packages.add' }));
  const included = screen.getByRole('region', {
    name: 'packages.selected_products',
  });
  expect(within(included).getByText(product.name)).toBeVisible();
  expect(screen.getByRole('button', { name: 'packages.added' })).toBeDisabled();
  fireEvent.click(within(included).getByRole('button'));
  expect(within(included).getByText('packages.contents_empty')).toBeVisible();
});
it('switches assignment scope without leaving a previous product selection active', () => {
  render(<Packages {...props} candidates={[product]} productSearch="case" />);
  fireEvent.click(screen.getByRole('tab', { name: /packages.assignments/ }));
  const panel = screen.getByRole('tabpanel', { name: /packages.assignments/ });
  fireEvent.click(within(panel).getByRole('checkbox'));
  expect(
    within(panel).getByRole('button', { name: 'packages.assign' }),
  ).toBeEnabled();
  fireEvent.click(
    within(panel).getByRole('tab', { name: 'packages.by_category' }),
  );
  expect(
    within(panel).getByRole('button', { name: 'packages.assign' }),
  ).toBeDisabled();
  fireEvent.click(
    within(panel).getByRole('tab', { name: 'packages.by_products' }),
  );
  expect(within(panel).getByRole('checkbox')).not.toBeChecked();
});

it('assigns a selected package with the shared picker and hides stale package contents', async () => {
  const { default: Assignment } =
    await import('../components/admin/ProductPackageAssignment');
  const { choose } = await import('./select');
  const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
  render(
    <Assignment
      product="22"
      value={{
        id: '1',
        options: [
          { id: '1', name: 'Laptop set' },
          { id: '2', name: 'Travel set' },
        ],
        contents: [{ id: '7', name: 'Laptop bag' }],
      }}
    />,
  );
  expect(
    screen.getByRole('button', { name: 'packages.assign' }),
  ).toBeDisabled();
  choose(
    screen.getByRole('combobox', { name: 'packages.choose_package' }),
    'Travel set',
  );
  expect(screen.queryByText('Laptop bag')).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'packages.assign' }));
  expect(post).toHaveBeenCalledWith(
    '/admin/product-packages/assign',
    { package: '2', products: ['22'] },
    expect.objectContaining({ preserveScroll: true }),
  );
});

it('allows removing an existing package assignment', async () => {
  const { default: Assignment } =
    await import('../components/admin/ProductPackageAssignment');
  const { choose } = await import('./select');
  const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
  render(
    <Assignment
      product="22"
      value={{
        id: '1',
        options: [{ id: '1', name: 'Laptop set' }],
        contents: [],
      }}
    />,
  );
  choose(
    screen.getByRole('combobox', { name: 'packages.choose_package' }),
    'packages.no_package',
  );
  fireEvent.click(
    screen.getByRole('button', { name: 'packages.remove_assignment' }),
  );
  expect(post).toHaveBeenCalledWith(
    '/admin/product-packages/assign',
    { package: '', products: ['22'] },
    expect.anything(),
  );
});
