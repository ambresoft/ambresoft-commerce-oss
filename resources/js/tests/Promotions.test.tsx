import { fireEvent, render, screen, within } from '@testing-library/react';
import { router } from '@inertiajs/react';
import type { AnchorHTMLAttributes } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import Form from '../pages/Admin/Promotions/Form';
import Index from '../pages/Admin/Promotions/Index';
import type { SharedProps } from '../types/page';
import type { PromotionDetail } from '../types/promotions';
import { english } from './translations';

const shared = vi.hoisted(() => ({ props: {} as SharedProps }));
vi.mock('@inertiajs/react', async (original) => ({
  ...(await original<typeof import('@inertiajs/react')>()),
  usePage: () => ({ props: shared.props, url: '/admin/promotions/create' }),
  Head: () => null,
  Link: ({ children, ...props }: AnchorHTMLAttributes<HTMLAnchorElement>) => (
    <a {...props}>{children}</a>
  ),
}));
const props = {
  navigation: [],
  breadcrumbs: [],
  promotion: null,
  currency: { code: 'EUR', decimals: 2 },
  timezone: 'Europe/Athens',
  options: {
    products: [{ id: '1', name: 'Book' }],
    categories: [{ id: '2', name: 'Books' }],
    groups: [],
    shipping: [],
    coupons: [{ id: '3', name: 'test123 · test' }],
  },
};
beforeEach(() => {
  vi.restoreAllMocks();
  shared.props = {
    app: { name: 'CMS' },
    auth: { user: null, canAccessAdmin: true },
    flash: { status: null },
    links: {} as SharedProps['links'],
    capabilities: { 'promotions.view': true, 'promotions.manage': true },
    i18n: {
      locale: 'en',
      direction: 'ltr',
      locales: [{ code: 'en', name: 'English' }],
      strings: {
        ...english,
        'promotions.general': 'General settings',
        'promotions.conditions': 'Conditions',
        'promotions.benefits': 'Benefits',
        'promotions.name': 'Name',
        'promotions.disable': 'Disable',
        'promotions.all_shipping': 'Any eligible quoted shipping method',
        'promotions.create': 'Create promotion',
        'promotions.mode': 'Application mode',
        'promotions.automatic': 'Automatic',
        'promotions.coupon': 'Existing coupon',
        'promotions.benefit': 'Benefit type',
        'promotions.percentage': 'Percentage discount',
        'promotions.fixed': 'Fixed amount discount',
        'promotions.free_shipping': 'Free shipping',
        'promotions.existing_coupon': 'Select existing coupon',
        'promotions.coupon_help': 'The existing coupon supplies its discount.',
        'promotions.match_all': 'All conditions must match (AND)',
        'promotions.match_any': 'Any condition may match (OR)',
        'promotions.match': 'Condition matching',
        'promotions.add_condition': 'Add condition',
        'promotions.condition_subtotal': 'Merchandise subtotal',
        'promotions.condition_products': 'Selected products present',
        'promotions.condition_categories': 'Selected categories present',
        'promotions.condition_quantity': 'Minimum quantity',
        'promotions.condition_groups': 'Customer groups',
        'promotions.condition_shipping': 'Shipping method',
        'promotions.minimum': 'Minimum (inclusive)',
        'promotions.maximum': 'Maximum (inclusive)',
        'promotions.amount': 'Discount amount',
        'promotions.select': 'Select…',
        'promotions.status': 'Status',
        'promotions.scope': 'Apply discount to',
        'promotions.scope_order': 'Whole order subtotal',
        'promotions.scope_products': 'Selected products',
        'promotions.scope_products_help':
          'Apply the discount to product prices. Choose all products or specific products and categories below.',
        'promotions.active': 'Active',
        'promotions.disabled': 'Disabled',
        'promotions.remove': 'Remove',
      },
    },
  };
});
const select = (label: string, option: string) => {
  fireEvent.click(screen.getByRole('combobox', { name: label }));
  fireEvent.click(screen.getByRole('option', { name: option }));
};
it('defaults to automatic and only displays fields for the selected benefit', () => {
  render(<Form {...props} />);
  expect(
    screen.getByRole('combobox', { name: 'Application mode' }),
  ).toHaveTextContent('Automatic');
  expect(
    screen.queryByLabelText('Discount amount (%)'),
  ).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('tab', { name: 'Benefits' }));
  expect(screen.getByLabelText('Discount amount (%)')).toBeInTheDocument();
  select('Apply discount to', 'Selected products');
  expect(
    screen.getByText(
      'Apply the discount to product prices. Choose all products or specific products and categories below.',
    ),
  ).toBeInTheDocument();
  select('Benefit type', 'Free shipping');
  expect(
    screen.queryByLabelText('Discount amount (%)'),
  ).not.toBeInTheDocument();
  expect(
    screen.getByText('Any eligible quoted shipping method'),
  ).toBeInTheDocument();
  fireEvent.click(screen.getByRole('tab', { name: 'General settings' }));
  select('Application mode', 'Existing coupon');
  select('Select existing coupon', 'test123 · test');
  expect(
    screen.getByRole('combobox', { name: 'Select existing coupon' }),
  ).toHaveTextContent('test123');
  fireEvent.click(screen.getByRole('tab', { name: 'Benefits' }));
  expect(
    screen.queryByRole('combobox', { name: 'Benefit type' }),
  ).not.toBeInTheDocument();
  expect(
    screen.getByText('The existing coupon supplies its discount.'),
  ).toBeInTheDocument();
});
it('builds flat conditions with AND/OR, independent subtotal bounds and removal', () => {
  render(<Form {...props} />);
  fireEvent.click(screen.getByRole('tab', { name: 'Conditions' }));
  fireEvent.click(screen.getByRole('button', { name: 'Add condition' }));
  expect(
    screen.getByRole('combobox', { name: 'Add condition' }),
  ).toHaveTextContent('Selected products present');
  fireEvent.change(screen.getByLabelText('Minimum (inclusive) (EUR)'), {
    target: { value: '100' },
  });
  fireEvent.change(screen.getByLabelText('Maximum (inclusive) (EUR)'), {
    target: { value: '200' },
  });
  select('Condition matching', 'Any condition may match (OR)');
  expect(screen.getByLabelText('Minimum (inclusive) (EUR)')).toHaveValue('100');
  fireEvent.click(screen.getByRole('button', { name: 'Remove' }));
  expect(
    screen.queryByLabelText('Minimum (inclusive) (EUR)'),
  ).not.toBeInTheDocument();
});
it('makes the entire editor read only without the manage capability', () => {
  shared.props.capabilities = { 'promotions.view': true };
  render(<Form {...props} />);
  expect(
    screen.queryByRole('button', { name: 'Save changes' }),
  ).not.toBeInTheDocument();
  expect(screen.getByRole('combobox', { name: 'Status' })).toBeDisabled();
  fireEvent.click(screen.getByRole('tab', { name: 'Conditions' }));
  expect(screen.getByRole('button', { name: 'Add condition' })).toBeDisabled();
});

it('keeps edits and the single status dropdown across URL-persisted tabs', () => {
  const promotion: PromotionDetail = {
    id: '7',
    version: 1,
    name: 'Black Friday',
    status: 'active',
    state: 'active',
    translations: { en: { name: 'Black Friday', description: '' } },
    mode: 'automatic',
    coupon_id: null,
    starts_at: null,
    ends_at: null,
    priority: '100',
    stop_other: false,
    match: 'all',
    benefit: 'percentage',
    scope: 'order',
    amount: '20',
    all_products: true,
    exclude_sale: false,
    all_shipping: true,
    conditions: [],
    products: [],
    categories: [],
    shipping: [],
    excluded_products: [],
    excluded_categories: [],
  };
  render(<Form {...props} promotion={promotion} />);
  expect(screen.getAllByText('Active')).toHaveLength(1);
  fireEvent.change(screen.getByLabelText('Name'), {
    target: { value: 'Winter sale' },
  });
  fireEvent.click(screen.getByRole('tab', { name: 'Conditions' }));
  expect(window.location.search).toBe('?tab=conditions');
  expect(screen.getByRole('combobox', { name: 'Status' })).toBeEnabled();
  expect(
    screen.getByRole('button', { name: 'Save changes' }),
  ).toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Add condition' }));
  fireEvent.change(screen.getByLabelText('Minimum (inclusive) (EUR)'), {
    target: { value: '50' },
  });
  fireEvent.click(screen.getByRole('tab', { name: 'General settings' }));
  expect(screen.getByLabelText('Name')).toHaveValue('Winter sale');
  fireEvent.click(screen.getByRole('tab', { name: 'Conditions' }));
  expect(screen.getByLabelText('Minimum (inclusive) (EUR)')).toHaveValue('50');
});
it('shows linked coupon usage and sends an optimistic version with disable', () => {
  const patch = vi.spyOn(router, 'patch').mockImplementation(() => {});
  render(
    <Index
      navigation={[]}
      breadcrumbs={[]}
      filters={{}}
      promotions={{
        data: [
          {
            id: '7',
            version: 2,
            name: 'Summer',
            mode: 'coupon',
            benefit: 'coupon',
            state: 'active',
            status: 'active',
            coupon_code: 'test123',
            used: 3,
            total_limit: 5,
            priority: '1',
            amount: null,
            starts_at: null,
            ends_at: null,
          },
        ],
        total: 1,
        current_page: 1,
        last_page: 1,
        prev_page_url: null,
        next_page_url: null,
      }}
    />,
  );
  const main = within(screen.getByRole('main'));
  expect(main.getByText('test123')).toBeInTheDocument();
  expect(main.getByText('3 / 5')).toBeInTheDocument();
  const statusSwitch = main.getByRole('switch', { name: 'Status: Summer' });
  expect(statusSwitch).toBeChecked();
  fireEvent.click(statusSwitch);
  expect(statusSwitch).toBeDisabled();
  expect(patch).toHaveBeenCalledWith(
    '/admin/promotions/7/status',
    { version: 2, status: 'disabled' },
    expect.objectContaining({
      preserveScroll: true,
      onFinish: expect.any(Function),
    }),
  );
});
