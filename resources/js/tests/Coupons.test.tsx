import { fireEvent, render, screen } from '@testing-library/react';
import { router } from '@inertiajs/react';
import type { AnchorHTMLAttributes } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import Form from '../pages/Admin/Coupons/Form';
import Index from '../pages/Admin/Coupons/Index';
import type { CouponDetail } from '../types/coupons';
import type { SharedProps } from '../types/page';
import { english } from './translations';

const shared = vi.hoisted(() => ({ props: {} as SharedProps }));
vi.mock('@inertiajs/react', async (original) => ({
  ...(await original<typeof import('@inertiajs/react')>()),
  usePage: () => ({ props: shared.props, url: '/admin/coupons/1/edit' }),
  Head: () => null,
  Link: ({ children, ...props }: AnchorHTMLAttributes<HTMLAnchorElement>) => (
    <a {...props}>{children}</a>
  ),
}));
const shell = { navigation: [], breadcrumbs: [] };
const coupon: CouponDetail = {
  id: '1',
  version: 2,
  deleted_at: null,
  name: 'Summer',
  code: 'SUMMER',
  type: 'percentage',
  amount: '10',
  minimum: '0',
  login_required: false,
  free_shipping: false,
  all_products: true,
  starts_on: '',
  ends_on: '',
  total_limit: '',
  customer_limit: '',
  status: 'active',
  product_ids: [],
  category_ids: [],
};
const history = {
  data: [],
  total: 0,
  current_page: 1,
  last_page: 1,
  prev_page_url: null,
  next_page_url: null,
};
const props = {
  ...shell,
  coupon,
  currency: { code: 'EUR', decimals: 2 },
  productOptions: [{ id: '12', name: 'Book' }],
  categoryOptions: [],
  history,
};
beforeEach(() => {
  vi.restoreAllMocks();
  window.history.replaceState({}, '', '/admin/coupons/1/edit');
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
      'coupons.view': true,
      'coupons.manage': true,
      'coupons.delete': true,
    },
    i18n: {
      locale: 'en',
      direction: 'ltr',
      strings: {
        ...english,
        'coupons.general': 'General',
        'coupons.history': 'Usage history',
        'coupons.scope': 'Eligible products',
        'coupons.restricted': 'Selected products and categories',
        'coupons.product_ids': 'Products',
        'coupons.add': 'Add',
        'coupons.history_empty': 'No uses yet',
        'coupons.title': 'Coupons',
        'coupons.create': 'Create coupon',
        'coupons.amount': 'Discount',
        'coupons.percentage': 'Percentage',
      },
      locales: [{ code: 'en', name: 'English' }],
    },
    links: { logout: '/logout' } as SharedProps['links'],
  } as SharedProps;
  vi.spyOn(router, 'replace').mockImplementation(() => undefined);
});
it('switches tabs without submitting the form and shows real empty history', () => {
  const put = vi.spyOn(router, 'put').mockImplementation(() => undefined);
  render(<Form {...props} />);
  fireEvent.click(screen.getByRole('tab', { name: 'Usage history' }));
  expect(screen.getByText('No uses yet')).toBeVisible();
  expect(screen.getByRole('tab', { name: 'Usage history' })).toHaveAttribute(
    'aria-selected',
    'true',
  );
  expect(put).not.toHaveBeenCalled();
});
it('keeps selected targets while changing scope and sends the versioned definition', () => {
  const put = vi.spyOn(router, 'put').mockImplementation(() => undefined);
  render(<Form {...props} />);
  fireEvent.click(screen.getByRole('combobox', { name: 'Eligible products' }));
  fireEvent.click(
    screen.getByRole('option', { name: 'Selected products and categories' }),
  );
  fireEvent.click(screen.getByRole('combobox', { name: 'Products' }));
  fireEvent.click(screen.getByRole('option', { name: 'Book' }));
  fireEvent.click(screen.getAllByRole('button', { name: 'Add' })[0]!);
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
  expect(put).toHaveBeenCalledWith(
    '/admin/coupons/1',
    expect.objectContaining({
      version: 2,
      all_products: false,
      product_ids: ['12'],
      customer_limit: '',
      total_limit: '',
    }),
    expect.any(Object),
  );
});
it('hides mutations for view-only staff and retains history access', () => {
  shared.props.capabilities = { 'coupons.view': true };
  render(<Form {...props} />);
  expect(screen.queryByRole('button', { name: 'Save changes' })).toBeNull();
  expect(screen.queryByRole('button', { name: 'Delete' })).toBeNull();
  expect(screen.getByRole('tab', { name: 'Usage history' })).toBeVisible();
});
it('lists coupon codes and discounts in the dashboard table', () => {
  render(
    <Index
      {...shell}
      filters={{ sort: 'name', direction: 'asc' }}
      coupons={{
        ...history,
        data: [
          {
            id: '1',
            version: 2,
            name: 'Summer',
            code: 'SUMMER',
            status: 'active',
            discount: '10%',
            free_shipping: false,
            starts_on: null,
            ends_on: null,
            used: 2,
            total_limit: 5,
          },
        ],
      }}
    />,
  );
  expect(screen.getByRole('link', { name: 'Summer' })).toHaveAttribute(
    'href',
    '/admin/coupons/1/edit',
  );
  expect(screen.getByText('SUMMER').closest('table')).toHaveClass('data-table');
  expect(screen.getByText('10%')).toBeVisible();
});
