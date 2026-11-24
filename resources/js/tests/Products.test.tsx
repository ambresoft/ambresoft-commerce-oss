import { fireEvent, render, screen, within } from '@testing-library/react';
import { router } from '@inertiajs/react';
import type { AnchorHTMLAttributes } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import ProductForm from '../pages/Admin/Products/Form';
import type { SharedProps } from '../types/page';
import type { ProductEditor } from '../types/products';
import { english } from './translations';
import { choose } from './select';

const shared = vi.hoisted(() => ({ props: {} as SharedProps }));
vi.mock('@inertiajs/react', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@inertiajs/react')>();
  return {
    ...actual,
    usePage: () => ({ url: '/admin/products/1/edit', props: shared.props }),
    Head: () => null,
    Link: ({ children, ...props }: AnchorHTMLAttributes<HTMLAnchorElement>) => (
      <a {...props}>{children}</a>
    ),
  };
});

beforeEach(() => {
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
    capabilities: { 'products.update': true, 'products.status': true },
    links: {} as SharedProps['links'],
    i18n: {
      locale: 'en',
      direction: 'ltr',
      locales: [
        { code: 'en', name: 'English' },
        { code: 'el', name: 'Ελληνικά' },
      ],
      strings: {
        ...english,
        'products.edit': 'Edit product',
        'products.duplicate': 'Duplicate',
        'products.features': 'features',
        'products.variations': 'variations',
        'brands.brand': 'Brand',
        'brands.none': 'No brand',
        'brands.search': 'Search brands',
        ...Object.fromEntries(
          [
            'similar_products',
            'bought_together',
            'companion_add',
            'companion_remove',
            'general',
            'pricing',
            'sales',
            'categories',
            'main_category',
            'extra_categories',
            'extra_category_add',
            'extra_category_remove',
            'media',
            'attachments',
            'price_history',
            'shipping',
            'free_shipping',
            'box_max_items',
            'box_length',
            'available_from',
            'customer_groups_all',
            'seo',
            'sku',
            'ean',
            'mpn',
            'identifier_help_label',
            'isbn',
            'upc',
            'returnable',
            'type',
            'physical',
            'digital',
            'price',
            'translation_missing',
            'description',
            'incomplete',
            'back',
            'active',
            'disabled',
            'hidden',
            'upload_files',
            'upload_attachments',
            'downloads',
            'price_history_empty',
            'price_history_1m',
            'price_history_all',
            'price_history_list_price',
            'price_history_series',
            'display_name',
            'remove',
            'up',
            'down',
            'library',
            'upload_images',
            'weight',
            'height',
            'width',
            'length',
            'primary',
            'regenerate',
          ].map((key) => ['products.' + key, key]),
        ),
        'products.identifier_help_label': 'About :name',
        'products.ean_help': 'EAN barcode explanation',
        'products.isbn_help': 'ISBN book explanation',
        'products.upc_help': 'UPC barcode explanation',
        'sales.add': 'Add sale',
        'sales.all_customers': 'All customers',
        'sales.customer_group_id': 'Customer group',
        'sales.kind': 'Discount type',
        'sales.fixed': 'Sale price (:currency)',
        'sales.percentage': 'Percentage off',
        'sales.amount': 'Value',
        'sales.stock_limit': 'Sale stock',
        'sales.field_of': ':field · sale :row',
        'sales.remove': 'Remove sale :row',
        'products.price_currency': 'Price (:code)',
        'products.price_hint':
          'Enter in :name (:code), up to :decimals decimals',
        'products.section_pricing': 'Pricing',
        'availability.in_stock': 'In-stock status',
        'availability.out_of_stock': 'Out-of-stock status',
        'availability.inherit': 'Use default (:name)',
        'products.section_inventory': 'Inventory',
        'products.list_price_currency': 'List price (:code)',
        'products.list_price_hint': 'Original or reference price',
        'products.quantity': 'Stock quantity',
        'products.quantity_hint': 'Units in stock',
        'products.min_qty': 'Minimum order quantity',
        'products.max_qty': 'Maximum order quantity',
        'products.qty_step': 'Quantity step',
        'products.limits_hint': 'Optional',
        'products.qty_step_hint': 'Optional step',
        'products.out_of_stock_actions': 'When out of stock',
        'products.out_of_stock_N': 'Do not allow purchase',
        'products.out_of_stock_B': 'Allow backorders',
        'products.out_of_stock_hint': 'Applies when quantity is 0',
        'products.out_of_stock_inherit_current':
          'Use the general setting (:value)',
        'products.out_of_stock_following':
          'Follows the general setting: :value.',
        'products.out_of_stock_custom_note':
          'Overrides the general setting, currently: :value.',
        'products.out_of_stock_reset': 'Use the general setting instead',
        'products.out_of_stock_change_general': 'Change the general setting',
        'products.section_taxes': 'Taxes',
        'products.taxes_hint': 'Select applicable taxes',
        'products.taxes_none': 'No taxes available',
        'taxes.price_included': 'Included in price',
        'taxes.price_added': 'Added to price',
        'taxes.disabled': 'Disabled',
        'products.conversions': 'Saved price in other active currencies',
        'products.conversions_hint': 'Reference only; never saved',
      },
    },
  };
});
const product: ProductEditor = {
  id: '1',
  version: 1,
  sku: null,
  ean: null,
  mpn: null,
  isbn: null,
  upc: null,
  returnable: false,
  type: 'physical',
  status: 'disabled',
  price: null,
  list_price: null,
  quantity: 0,
  min_qty: null,
  max_qty: null,
  qty_step: null,
  out_of_stock_actions: null,
  general_out_of_stock_action: 'N',
  tax_ids: [],
  taxes: [],
  category_id: '',
  weight: '',
  length: '',
  width: '',
  height: '',
  free_shipping: false,
  shipping_cost: null,
  available_from: null,
  box_min_items: null,
  box_max_items: null,
  box_length: null,
  box_width: null,
  box_height: null,
  customer_groups: [],
  incomplete: true,
  translations: {},
  images: [],
  primary_image: '',
  files: [],
};
const props = {
  navigation: [],
  breadcrumbs: [],
  defaultLocale: 'en',
  returnTo: '/admin/products?page=2&per_page=25&search=chair',
  categories: [],
  units: { weight: 'kg', dimension: 'cm' },
  currency: { code: 'EUR', name: 'Euro', symbol: '€', decimals: 2 },
  conversions: [],
  customerGroups: [],
  priceHistory: [],
};

it('edits sitemap exclusion in Additional and submits it with the product', () => {
  Object.assign(shared.props.i18n.strings, {
    'products.additional': 'Additional',
    'products.exclude_from_sitemap': 'Exclude from XML sitemap',
  });
  const request = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  render(
    <ProductForm
      product={{ ...product, exclude_from_sitemap: true }}
      {...props}
    />,
  );
  fireEvent.click(screen.getByRole('tab', { name: 'Additional' }));
  const checkbox = screen.getByRole('checkbox', {
    name: 'Exclude from XML sitemap',
  });
  expect(checkbox).toBeChecked();
  fireEvent.click(checkbox);
  expect(request).not.toHaveBeenCalled();
  fireEvent.click(screen.getByRole('tab', { name: 'general' }));
  fireEvent.click(screen.getByRole('tab', { name: 'Additional' }));
  expect(
    screen.getByRole('checkbox', { name: 'Exclude from XML sitemap' }),
  ).not.toBeChecked();
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
  expect(request.mock.calls[0][1]?.data).toMatchObject({
    exclude_from_sitemap: false,
  });
  request.mockRestore();
});

it('preserves independent language and editor tab drafts and the original list URL', () => {
  render(<ProductForm product={product} {...props} />);
  fireEvent.change(screen.getByLabelText('Name · en'), {
    target: { value: 'Chair' },
  });
  fireEvent.click(screen.getByRole('button', { name: /Ελληνικά/ }));
  expect(screen.getByLabelText('Name · el')).toHaveValue('');
  fireEvent.change(screen.getByLabelText('Name · el'), {
    target: { value: 'Καρέκλα' },
  });
  fireEvent.click(screen.getByRole('tab', { name: 'pricing' }));
  fireEvent.change(screen.getByLabelText('Price (EUR)'), {
    target: { value: '0' },
  });
  expect(screen.getByRole('button', { name: 'Save changes' })).toBeVisible();
  fireEvent.click(screen.getByRole('tab', { name: 'general' }));
  expect(screen.getByLabelText('Name · el')).toHaveValue('Καρέκλα');
  fireEvent.click(screen.getByRole('button', { name: 'English' }));
  expect(screen.getByLabelText('Name · en')).toHaveValue('Chair');
  expect(screen.getByRole('link', { name: 'Back' })).toHaveAttribute(
    'href',
    props.returnTo,
  );
});

it('preserves private file metadata across product type and tab changes', () => {
  render(
    <ProductForm
      product={{
        ...product,
        type: 'digital',
        files: [
          { id: '8', original_name: 'source.pdf', display_name: 'Guide.pdf' },
        ],
      }}
      {...props}
    />,
  );
  fireEvent.click(screen.getByRole('tab', { name: 'attachments' }));
  fireEvent.change(screen.getByLabelText('display_name'), {
    target: { value: 'New guide.pdf' },
  });
  fireEvent.click(screen.getByRole('tab', { name: 'general' }));
  choose(screen.getByLabelText('type'), 'physical');
  fireEvent.click(screen.getByRole('tab', { name: 'attachments' }));
  expect(screen.queryByLabelText('display_name')).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('tab', { name: 'general' }));
  choose(screen.getByLabelText('type'), 'digital');
  fireEvent.click(screen.getByRole('tab', { name: 'attachments' }));
  expect(screen.getByLabelText('display_name')).toHaveValue('New guide.pdf');
});

it('shows only enabled languages and warns before discarding a modified draft', () => {
  shared.props.i18n.locales = [{ code: 'el', name: 'Ελληνικά' }];
  render(<ProductForm product={product} {...props} defaultLocale="el" />);
  expect(
    screen.queryByRole('button', { name: /English/ }),
  ).not.toBeInTheDocument();
  fireEvent.change(screen.getByLabelText('Name · el'), {
    target: { value: 'Πρόχειρο' },
  });
  const before = new Event('beforeunload', { cancelable: true });
  window.dispatchEvent(before);
  expect(before.defaultPrevented).toBe(true);
});

it('labels the base price with the primary currency and never submits converted display values', () => {
  const request = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  render(
    <ProductForm
      product={{ ...product, price: '100.00' }}
      {...props}
      conversions={[{ code: 'USD', formatted: '$110.00' }]}
    />,
  );
  fireEvent.click(screen.getByRole('tab', { name: 'pricing' }));
  const price = screen.getByLabelText('Price (EUR)');
  expect(price).toHaveValue('100.00');
  expect(price).toHaveAccessibleDescription(
    'Enter in Euro (EUR), up to 2 decimals',
  );
  expect(screen.getByText('$110.00')).toBeVisible();
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
  const data = request.mock.calls[0][1]?.data as Record<string, unknown>;
  expect(data.price).toBe('100.00');
  expect(JSON.stringify(data)).not.toMatch(/conversion|USD|110/);
  request.mockRestore();
});

it('keeps optional EAN leading zeros and the returnable flag when switching editor tabs', () => {
  render(<ProductForm {...props} product={product} />);
  fireEvent.change(screen.getByLabelText('ean'), {
    target: { value: '0123456789012' },
  });
  expect(screen.getByLabelText('returnable')).not.toBeChecked();
  fireEvent.click(screen.getByLabelText('returnable'));
  fireEvent.click(screen.getByRole('tab', { name: 'pricing' }));
  fireEvent.click(screen.getByRole('tab', { name: 'general' }));
  expect(screen.getByLabelText('ean')).toHaveValue('0123456789012');
  expect(screen.getByLabelText('returnable')).toBeChecked();
});

it('places Name first, keeps SKU and EAN together, and puts Returnable last in General', () => {
  render(<ProductForm {...props} product={product} />);
  const panel = screen.getByRole('tabpanel');
  const fields = [...panel.querySelectorAll('input, [contenteditable="true"]')];
  expect(fields[0]).toHaveAttribute('id', 'name-en');
  const sku = screen.getByLabelText('sku');
  const ean = screen.getByLabelText('ean');
  expect(sku.closest('.grid')).toBe(ean.closest('.grid'));
  const mpn = screen.getByLabelText('mpn');
  const type = screen.getByRole('combobox', { name: /type/ });
  expect(mpn.closest('.grid')).toBe(type.closest('.grid'));
  expect(mpn.closest('.grid')).toBe(
    screen.getByRole('combobox', { name: 'Brand' }).closest('.grid'),
  );
  expect(mpn.closest('.grid')).toHaveClass('sm:grid-cols-3');
  expect(
    screen.queryByRole('combobox', { name: 'Search brands' }),
  ).not.toBeInTheDocument();
  expect(fields[fields.length - 1]).toBe(screen.getByLabelText('returnable'));
  const isbn = screen.getByLabelText('isbn');
  const upc = screen.getByLabelText('upc');
  expect(isbn.closest('.grid')).toBe(upc.closest('.grid'));
  for (const identifier of ['ean', 'isbn', 'upc']) {
    const help = screen.getByRole('button', { name: `About ${identifier}` });
    expect(help).toHaveAttribute('type', 'button');
    expect(help).toHaveAttribute('aria-describedby', `${identifier}-tooltip`);
    expect(document.getElementById(`${identifier}-tooltip`)).toHaveAttribute(
      'role',
      'tooltip',
    );
    expect(
      document.getElementById(`${identifier}-hint`),
    ).not.toBeInTheDocument();
  }
  expect(
    mpn.compareDocumentPosition(isbn) & Node.DOCUMENT_POSITION_FOLLOWING,
  ).toBeTruthy();
  fireEvent.change(isbn, { target: { value: '0-306-40615-2' } });
  fireEvent.change(upc, { target: { value: '012345678905' } });
  fireEvent.change(mpn, { target: { value: '001-MODEL/A' } });
  fireEvent.click(screen.getByRole('tab', { name: 'pricing' }));
  fireEvent.click(screen.getByRole('tab', { name: 'general' }));
  expect(screen.getByLabelText('mpn')).toHaveValue('001-MODEL/A');
  expect(screen.getByLabelText('isbn')).toHaveValue('0-306-40615-2');
  expect(screen.getByLabelText('upc')).toHaveValue('012345678905');
});

it('marks required product fields without marking optional identifiers or translations', () => {
  render(<ProductForm product={product} {...props} />);
  const marked = (label: string) => {
    const control = screen.getByLabelText(label);
    expect(control).toHaveAttribute('aria-required', 'true');
    expect(
      document.querySelector(`label[for="${control.id}"]`),
    ).toHaveTextContent('*');
    expect(control).not.toHaveAttribute('required');
  };
  marked('Name · en');
  marked('sku');
  for (const label of ['ean', 'mpn', 'isbn', 'upc']) {
    expect(screen.getByLabelText(label)).not.toHaveAttribute('aria-required');
    expect(
      document.querySelector(`label[for="${label}"]`),
    ).not.toHaveTextContent('*');
  }
  expect(screen.getByRole('combobox', { name: /type/ })).toHaveAttribute(
    'aria-required',
    'true',
  );
  expect(screen.getByRole('combobox', { name: 'Status' })).toHaveAttribute(
    'aria-required',
    'true',
  );
  fireEvent.click(screen.getByRole('button', { name: /Ελληνικά/ }));
  expect(screen.getByLabelText('Name · el')).not.toHaveAttribute(
    'aria-required',
  );
  fireEvent.click(screen.getByRole('tab', { name: 'pricing' }));
  marked('Price (EUR)');
  fireEvent.click(screen.getByRole('tab', { name: 'categories' }));
  expect(
    screen.getByRole('combobox', { name: /main_category/ }),
  ).toHaveAttribute('aria-required', 'true');
  expect(
    screen.getByRole('combobox', { name: /extra_category_add/ }),
  ).not.toHaveAttribute('aria-required');
});

it('prefills pricing and inventory, keeps blank limits unset and submits the chosen out-of-stock action', () => {
  const request = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  render(
    <ProductForm
      product={{
        ...product,
        price: '100.00',
        list_price: '120.00',
        quantity: 7,
        min_qty: 2,
        max_qty: null,
        qty_step: 2,
        out_of_stock_actions: 'B',
      }}
      {...props}
    />,
  );
  fireEvent.click(screen.getByRole('tab', { name: 'pricing' }));
  expect(screen.getByLabelText('List price (EUR)')).toHaveValue('120.00');
  expect(screen.getByLabelText('Stock quantity')).toHaveValue(7);
  expect(screen.getByLabelText('Minimum order quantity')).toHaveValue(2);
  expect(screen.getByLabelText('Maximum order quantity')).toHaveValue(null);
  expect(screen.getByLabelText('Quantity step')).toHaveValue(2);
  expect(
    screen.getByRole('combobox', { name: 'When out of stock' }),
  ).toHaveTextContent('Allow backorders');
  choose(
    screen.getByRole('combobox', { name: 'When out of stock' }),
    'Do not allow purchase',
  );
  fireEvent.change(screen.getByLabelText('Stock quantity'), {
    target: { value: '0' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
  const data = request.mock.calls[0][1]?.data as Record<string, unknown>;
  expect(data).toMatchObject({
    list_price: '120.00',
    quantity: '0',
    min_qty: '2',
    max_qty: '',
    qty_step: '2',
    out_of_stock_actions: 'N',
  });
  request.mockRestore();
});

it('follows the general setting by default, submits it blank and links to the setting only with its capability', () => {
  const request = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  render(<ProductForm product={product} {...props} />);
  fireEvent.click(screen.getByRole('tab', { name: 'pricing' }));
  expect(
    screen.getByRole('combobox', { name: 'When out of stock' }),
  ).toHaveTextContent('Use the general setting (Do not allow purchase)');
  expect(
    screen.getByText('Follows the general setting: Do not allow purchase.'),
  ).toBeVisible();
  expect(
    screen.queryByRole('button', { name: 'Use the general setting instead' }),
  ).not.toBeInTheDocument();
  expect(
    screen.queryByRole('link', { name: 'Change the general setting' }),
  ).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
  expect(request.mock.calls[0][1]?.data).toMatchObject({
    out_of_stock_actions: '',
  });
  request.mockRestore();
});

it('shows a product override against the current general value and clears it with one action', () => {
  shared.props.capabilities = {
    ...shared.props.capabilities,
    'settings.manage': true,
  };
  const request = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  render(
    <ProductForm
      product={{
        ...product,
        out_of_stock_actions: 'B',
        general_out_of_stock_action: 'N',
      }}
      {...props}
    />,
  );
  fireEvent.click(screen.getByRole('tab', { name: 'pricing' }));
  expect(
    screen.getByRole('combobox', { name: 'When out of stock' }),
  ).toHaveTextContent('Allow backorders');
  expect(
    screen.getByText(
      'Overrides the general setting, currently: Do not allow purchase.',
    ),
  ).toBeVisible();
  expect(
    screen.getByRole('link', { name: 'Change the general setting' }),
  ).toHaveAttribute('href', '/admin/settings/general');
  fireEvent.click(
    screen.getByRole('button', { name: 'Use the general setting instead' }),
  );
  expect(
    screen.getByRole('combobox', { name: 'When out of stock' }),
  ).toHaveTextContent('Use the general setting (Do not allow purchase)');
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
  expect(request.mock.calls[0][1]?.data).toMatchObject({
    out_of_stock_actions: '',
  });
  request.mockRestore();
});

it('lists assignable taxes, keeps disabled assignments visible and submits the chosen set', () => {
  const request = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  render(
    <ProductForm
      product={{
        ...product,
        tax_ids: ['2'],
        taxes: [
          {
            id: '1',
            name: 'VAT',
            active: true,
            price_includes_tax: true,
            priority: 0,
          },
          {
            id: '2',
            name: 'Legacy fee',
            active: false,
            price_includes_tax: false,
            priority: 1,
          },
        ],
      }}
      {...props}
    />,
  );
  fireEvent.click(screen.getByRole('tab', { name: 'pricing' }));
  expect(screen.getByRole('checkbox', { name: /VAT/ })).not.toBeChecked();
  const legacy = screen.getByRole('checkbox', { name: /Legacy fee/ });
  expect(legacy).toBeChecked();
  expect(screen.getByText(/Added to price · Disabled/)).toBeVisible();
  fireEvent.click(screen.getByRole('checkbox', { name: /VAT/ }));
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
  const data = request.mock.calls[0][1]?.data as Record<string, unknown>;
  expect(data).toMatchObject({ taxes_submitted: true, tax_ids: ['2', '1'] });
  request.mockRestore();
});

it('submits an empty tax list explicitly so assignments can be cleared', () => {
  const request = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  render(
    <ProductForm
      product={{
        ...product,
        tax_ids: ['1'],
        taxes: [
          {
            id: '1',
            name: 'VAT',
            active: true,
            price_includes_tax: false,
            priority: 0,
          },
        ],
      }}
      {...props}
    />,
  );
  fireEvent.click(screen.getByRole('tab', { name: 'pricing' }));
  fireEvent.click(screen.getByRole('checkbox', { name: /VAT/ }));
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
  expect(request.mock.calls[0][1]?.data).toMatchObject({
    taxes_submitted: true,
    tax_ids: [],
  });
  request.mockRestore();
});

it('keeps shipping, packaging and customer groups in their own sections and submits them', () => {
  const request = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  render(
    <ProductForm
      product={{ ...product, customer_groups: ['2'] }}
      {...props}
      customerGroups={[
        { id: '1', name: 'Retail', active: true },
        { id: '2', name: 'Wholesale', active: false },
      ]}
    />,
  );
  fireEvent.click(screen.getByRole('tab', { name: 'shipping' }));
  fireEvent.click(screen.getByLabelText('free_shipping'));
  expect(screen.getByLabelText('box_length (cm)')).not.toHaveAttribute(
    'aria-required',
  );
  fireEvent.change(screen.getByLabelText('box_max_items'), {
    target: { value: '6' },
  });
  expect(screen.getByLabelText('box_length (cm)')).toHaveAttribute(
    'aria-required',
    'true',
  );
  fireEvent.click(screen.getByRole('tab', { name: 'pricing' }));
  fireEvent.change(screen.getByLabelText('available_from'), {
    target: { value: '2026-10-20' },
  });
  expect(screen.getByLabelText(/Wholesale/)).toBeChecked();
  fireEvent.click(screen.getByLabelText(/Wholesale/));
  expect(screen.getByLabelText('customer_groups_all')).toBeChecked();
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
  expect(request.mock.calls[0][1]?.data).toMatchObject({
    free_shipping: true,
    box_max_items: '6',
    available_from: '2026-10-20',
    customer_groups_submitted: true,
    customer_groups: [],
  });
  request.mockRestore();
});

it('offers attachments for physical products and keeps buyer downloads digital-only', () => {
  const request = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  render(
    <ProductForm
      product={{
        ...product,
        files: [
          {
            id: '8',
            original_name: 'sizes.pdf',
            display_name: 'Size chart',
            purpose: 'attachment',
          },
          {
            id: '9',
            original_name: 'book.pdf',
            display_name: 'Book',
            purpose: 'download',
          },
        ],
      }}
      {...props}
    />,
  );
  fireEvent.click(screen.getByRole('tab', { name: 'attachments' }));
  expect(screen.queryByLabelText('upload_files')).not.toBeInTheDocument();
  expect(screen.getByDisplayValue('Size chart')).toBeVisible();
  expect(screen.queryByDisplayValue('Book')).not.toBeInTheDocument();
  const chart = new File(['%PDF'], 'chart.pdf', { type: 'application/pdf' });
  fireEvent.change(
    screen.getByLabelText('upload_attachments', { selector: 'input' }),
    {
      target: { files: [chart] },
    },
  );
  expect(screen.getByText('chart.pdf')).toBeVisible();
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
  const data = request.mock.calls[0][1]?.data as Record<string, unknown>;
  expect(data.file_upload_purposes).toEqual(['attachment']);
  // Hidden downloads are kept, not dropped, while the product is physical.
  expect((data.files as { id: string }[]).map((f) => f.id)).toEqual(['8', '9']);
  request.mockRestore();
});

it('charts the saved price history with periods, a series switch and a table of changes', () => {
  render(
    <ProductForm
      product={{ ...product, price: '50.00' }}
      {...props}
      priceHistory={[
        { at: '2020-01-01T10:00:00+00:00', price: '65.00', list_price: null },
        {
          at: new Date(Date.now() - 86400000).toISOString(),
          price: '50.00',
          list_price: '70.00',
        },
      ]}
    />,
  );
  fireEvent.click(screen.getByRole('button', { name: 'More tabs' }));
  fireEvent.click(screen.getByRole('tab', { name: 'price_history' }));
  expect(within(screen.getByRole('main')).getByRole('img')).toBeInTheDocument();
  expect(
    screen.getByRole('button', { name: 'price_history_1m' }),
  ).toHaveAttribute('aria-pressed', 'true');
  fireEvent.click(screen.getByRole('button', { name: 'price_history_all' }));
  expect(
    screen.getByRole('button', { name: 'price_history_all' }),
  ).toHaveAttribute('aria-pressed', 'true');
  const rows = screen.getAllByRole('row');
  expect(rows).toHaveLength(3);
  expect(rows[1]).toHaveTextContent('50.00');
  expect(rows[2]).toHaveTextContent('65.00');
  expect(rows[2]).toHaveTextContent('—');
});

it('explains an empty price history instead of drawing an empty chart', () => {
  render(<ProductForm product={product} {...props} />);
  fireEvent.click(screen.getByRole('button', { name: 'More tabs' }));
  fireEvent.click(screen.getByRole('tab', { name: 'price_history' }));
  expect(screen.getByText('price_history_empty')).toBeVisible();
  expect(
    within(screen.getByRole('main')).queryByRole('img'),
  ).not.toBeInTheDocument();
});

it('keeps both availability labels in the Stock section and submits independent choices', () => {
  const request = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  render(
    <ProductForm
      {...props}
      product={{
        ...product,
        in_stock_status_id: null,
        out_of_stock_status_id: '2',
      }}
      availabilityStatuses={[
        {
          id: '1',
          name: 'Immediately available',
          default_in_stock: true,
          default_out_of_stock: false,
        },
        {
          id: '2',
          name: 'Unavailable',
          default_in_stock: false,
          default_out_of_stock: true,
        },
        {
          id: '3',
          name: 'Available in 4–6 days',
          default_in_stock: false,
          default_out_of_stock: false,
        },
      ]}
    />,
  );
  expect(
    screen.queryByRole('combobox', { name: 'In-stock status' }),
  ).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('tab', { name: 'pricing' }));
  expect(screen.getByRole('heading', { name: 'Inventory' })).toBeVisible();
  choose(
    screen.getByRole('combobox', { name: 'In-stock status' }),
    'Available in 4–6 days',
  );
  choose(
    screen.getByRole('combobox', { name: 'Out-of-stock status' }),
    'Use default (Unavailable)',
  );
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
  expect(request.mock.calls[0][1]?.data).toMatchObject({
    in_stock_status_id: '3',
    out_of_stock_status_id: '',
  });
});

it('keeps percentage sale rows and optional stock across tabs and allows removing them', () => {
  render(
    <ProductForm
      product={product}
      {...props}
      saleCustomerGroups={[
        { id: '1', name: 'Default', active: true, default: true },
      ]}
    />,
  );
  fireEvent.click(screen.getByRole('button', { name: 'More tabs' }));
  fireEvent.click(screen.getByRole('tab', { name: 'sales' }));
  fireEvent.click(screen.getByRole('button', { name: 'Add sale' }));
  expect(
    screen.getByRole('combobox', { name: 'Customer group · sale 1' }),
  ).toHaveTextContent('All customers');
  choose(
    screen.getByRole('combobox', { name: 'Discount type · sale 1' }),
    'Percentage off',
  );
  fireEvent.change(screen.getByLabelText('Value · sale 1'), {
    target: { value: '30' },
  });
  expect(screen.getByLabelText('Sale stock · sale 1')).toHaveValue('');
  fireEvent.click(screen.getByRole('tab', { name: 'general' }));
  fireEvent.click(screen.getByRole('button', { name: 'More tabs' }));
  fireEvent.click(screen.getByRole('tab', { name: 'sales' }));
  expect(screen.getByLabelText('Value · sale 1')).toHaveValue('30');
  expect(
    screen.getByRole('combobox', { name: 'Discount type · sale 1' }),
  ).toHaveTextContent('Percentage off');
  fireEvent.click(screen.getByRole('button', { name: 'Remove sale 1' }));
  expect(screen.queryByLabelText('Value · sale 1')).not.toBeInTheDocument();
});

it('switches similar products off and keeps at most three companions with their details', () => {
  const request = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  const row = (id: string, status = 'active') => ({
    id,
    name: 'Accessory ' + id,
    sku: 'ACC-' + id,
    image: null,
    price: '€10.00',
    status,
    deleted: false,
  });
  render(
    <ProductForm
      {...props}
      product={{
        ...product,
        similar_products: true,
        companion_ids: ['11', '12'],
      }}
      companions={[row('11'), row('12', 'disabled')]}
      companionOptions={[row('13'), row('14'), row(product.id)]}
    />,
  );
  fireEvent.click(screen.getByRole('tab', { name: 'similar_products' }));
  fireEvent.click(screen.getByRole('checkbox'));
  fireEvent.click(screen.getByRole('tab', { name: 'bought_together' }));
  expect(screen.getByText('Accessory 11')).toBeVisible();
  expect(screen.getByText(/ACC-12/)).toBeVisible();
  // The main product itself is never offered.
  expect(screen.queryByText('Accessory ' + product.id)).not.toBeInTheDocument();
  const add = screen.getAllByRole('button', { name: 'companion_add' });
  fireEvent.click(add[0]);
  // Three chosen: the remaining candidates cannot be added.
  for (const button of screen.getAllByRole('button', {
    name: 'companion_add',
  }))
    expect(button).toBeDisabled();
  fireEvent.click(
    screen.getAllByRole('button', { name: /companion_remove/ })[0],
  );
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
  const data = request.mock.calls[0][1]?.data as Record<string, unknown>;
  expect(data).toMatchObject({
    similar_products: false,
    companions_submitted: true,
    companion_ids: ['12', '13'],
  });
  request.mockRestore();
});

it('uses the product identity header and keeps Features and Variations ahead of secondary tabs', () => {
  render(
    <ProductForm
      {...props}
      product={{
        ...product,
        primary_image: 'image-1',
        storefront_url: '/chair',
        translations: {
          en: { name: 'Chair' } as ProductEditor['translations'][string],
        },
      }}
    />,
  );
  expect(
    screen.getByRole('heading', { name: 'Edit product', level: 1 }),
  ).toBeVisible();
  expect(
    screen.getByRole('heading', { name: 'Chair', level: 2 }),
  ).toBeVisible();
  expect(screen.getByRole('link', { name: 'Preview' })).toHaveAttribute(
    'href',
    '/chair',
  );
  const tabs = screen.getByRole('tablist', { name: 'Edit product' });
  expect(
    within(tabs)
      .getAllByRole('tab')
      .slice(0, 4)
      .map((tab) => tab.textContent),
  ).toEqual(['general', 'pricing', 'features', 'variations']);
  expect(screen.queryByRole('tab', { name: 'sales' })).toBeNull();
  expect(screen.queryByRole('tab', { name: 'price_history' })).toBeNull();
  fireEvent.click(screen.getByRole('button', { name: 'More tabs' }));
  expect(screen.getByRole('tab', { name: 'sales' })).toBeVisible();
  expect(screen.getByRole('tab', { name: 'price_history' })).toBeVisible();
});

it('duplicates the saved product with its version and protects unsaved changes', () => {
  const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
  shared.props.capabilities['products.create'] = true;
  render(<ProductForm {...props} product={product} />);
  fireEvent.click(screen.getByRole('button', { name: 'Duplicate' }));
  expect(post).toHaveBeenCalledWith(
    '/admin/products/1/duplicate',
    { version: product.version, return_to: props.returnTo },
    expect.anything(),
  );
  post.mockRestore();
});

it('requires saving a changed draft before duplicating and hides duplicate without permission', () => {
  const view = render(<ProductForm {...props} product={product} />);
  expect(screen.queryByRole('button', { name: 'Duplicate' })).toBeNull();
  shared.props.capabilities['products.create'] = true;
  view.rerender(<ProductForm {...props} product={product} />);
  fireEvent.change(screen.getByLabelText('Name · en'), {
    target: { value: 'Changed chair' },
  });
  expect(screen.getByRole('button', { name: 'Duplicate' })).toBeDisabled();
});

it('adds and removes extra categories and submits them with the product', () => {
  const request = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  render(
    <ProductForm
      product={{ ...product, extra_category_ids: ['7'] }}
      {...props}
      extraCategoryOptions={[
        { id: '7', name: 'Gifts' },
        { id: '8', name: 'Tech → Apple' },
      ]}
    />,
  );
  fireEvent.click(screen.getByRole('tab', { name: 'categories' }));
  fireEvent.click(screen.getByRole('combobox', { name: /extra_category_add/ }));
  fireEvent.click(screen.getByRole('option', { name: 'Tech → Apple' }));
  // An extra already chosen is not offered again.
  expect(
    screen.queryByRole('option', { name: 'Gifts' }),
  ).not.toBeInTheDocument();
  // Gifts, the first chip, is removed.
  fireEvent.click(
    screen.getAllByRole('button', { name: /extra_category_remove/ })[0]!,
  );
  expect(screen.getByText('Tech → Apple')).toBeVisible();
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
  expect(request.mock.calls[0][1]?.data).toMatchObject({
    extra_categories_submitted: true,
    extra_category_ids: ['8'],
  });
  request.mockRestore();
});
