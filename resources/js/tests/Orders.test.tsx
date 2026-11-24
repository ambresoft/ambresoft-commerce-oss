import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { router } from '@inertiajs/react';
import { cloneElement, type AnchorHTMLAttributes } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import OrderStatusesIndex from '../pages/Admin/OrderStatuses/Index';
import OrdersIndex from '../pages/Admin/Orders/Index';
import OrderShow from '../pages/Admin/Orders/Show';
import OrderForm from '../pages/Admin/Orders/Form';
import OrderEmailEdit from '../pages/Admin/OrderEmails/Edit';
import type { OrderDetail, OrderRow, OrderStatusOption } from '../types/orders';
import type { SharedProps } from '../types/page';
import { english } from './translations';
import { choose, options } from './select';

const shared = vi.hoisted(() => ({ props: {} as SharedProps }));
vi.mock('@inertiajs/react', async (original) => ({
  ...(await original<typeof import('@inertiajs/react')>()),
  usePage: () => ({ url: '/admin', props: shared.props }),
  Head: () => null,
  Link: ({ children, ...props }: AnchorHTMLAttributes<HTMLAnchorElement>) => (
    <a {...props}>{children}</a>
  ),
}));
// Readable labels: each key renders as its last segment unless overridden.
const keys = [
  'title',
  'count',
  'create',
  'order',
  'created',
  'customer',
  'items',
  'total',
  'updated',
  'empty',
  'no_matches',
  'search_placeholder',
  'more_filters',
  'retry',
  'change_status',
  'new_status',
  'status_note',
  'email_sent',
  'email_failed',
  'mail_error_mail_rejected',
  'ip_address',
  'ip_unknown',
  'history',
  'comments',
  'add_comment',
  'calculate',
  'find_product',
  'add',
  'added',
  'remove',
  'first_name',
  'last_name',
  'email',
  'phone',
  'initial_status',
  'template_subject',
  'live_preview',
  'test_recipient',
  'test_send',
  'template_body',
  'invoice_item_template',
  'invoice_total_template',
  'preview',
  'insert_subject',
  'insert_body',
  'variables',
  'status_inactive',
  'customer_account',
  'guest_order',
  'billing_address',
  'shipping_same',
  'notes',
  'customer_note',
  'internal_note',
  'grand_total',
  'subtotal',
  'tax_total',
  'tax_added',
  'discount_total',
  'shipping_total',
  'line_discount',
  'includes_tax',
  'product',
  'quantity',
  'shipping_method',
  'shipping_amount',
  'payment_not_for_shipping',
];
const labels: Record<string, string> = {
  'checkout.modal_title': 'Invoice details',
  'checkout.business_name': 'Business name',
  'checkout.vat_number': 'VAT number',
  'checkout.country': 'VAT registration country',
  'checkout.provider': 'Validation method',
  'checkout.checked_at': 'Checked at',
  'checkout.method_vies': 'VIES',
  'checkout.method_local': 'AFM checksum only',
  'orders.invoice_requested': 'Invoice requested',
  'common.yes': 'Yes',
  'common.no': 'No',
  ...Object.fromEntries(keys.map((k) => ['orders.' + k, k])),
  ...Object.fromEntries(
    [
      'print_label',
      'track',
      'create_additional',
      'additional_confirm',
      'create_submit',
    ].map((k) => ['shipping.' + k, k]),
  ),
  'orders.drag_handle': 'Drag to reorder: :name',
  'orders.number': 'Order #:number',
  'orders.bulk_status': 'Update selected status',
  'orders.select_page': 'Select this page',
  'orders.select_order': 'Select order #:number',
  'orders.bulk_status_confirm': 'Change :count order(s) to :status?',
  'orders.confirm_status': 'Change #:number to :status?',
  'orders.change_status_of': 'Status of #:number',
  'orders.quantity_of': 'Quantity of :name',
  'orders.unit_price_of': 'Unit price of :name',
  'orders.catalog_price': 'Catalog :price',
  'common.save': 'Save changes',
  'common.status': 'Status',
  'common.all': 'All',
  'common.name': 'Name',
  'common.search': 'Search',
  'payments.status_partially_paid': 'status_partially_paid',
  'payments.record': 'record',
};
const shell = { navigation: [], breadcrumbs: [] };
const status = (
  id: string,
  name: string,
  extra: Partial<OrderStatusOption> = {},
): OrderStatusOption => ({
  id,
  code: name.toLowerCase(),
  name,
  color: '#2563eb',
  active: true,
  initial: false,
  final: false,
  stock_action: 'none',
  position: Number(id) * 10,
  system: true,
  invoice: false,
  customer_can_cancel: false,
  ...extra,
});
const statuses = [
  status('1', 'Open', { initial: true }),
  status('2', 'Processing', { stock_action: 'decrease' }),
  status('3', 'Archived', { active: false }),
];
const row: OrderRow = {
  id: '9',
  number: '100009',
  version: 4,
  customer: 'Maria Example',
  email: 'maria@example.test',
  phone: null,
  status: statuses[0],
  items: 1,
  units: 2,
  total: '€24.80',
  currency: 'EUR',
  created_at: '2026-10-12T10:00:00+00:00',
  updated_at: '2026-10-12T10:00:00+00:00',
  ip_address: null,
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
      'orders.view': true,
      'orders.create': true,
      'orders.update': true,
      'orders.status': true,
      'orders.notify': true,
      'orders.customer-ip': false,
      'order-emails.manage': true,
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

it('changes an order status from the list only after confirmation and offers only active statuses', () => {
  const patch = vi.spyOn(router, 'patch').mockImplementation(() => {});
  render(
    <OrdersIndex
      {...shell}
      orders={{
        data: [row],
        current_page: 1,
        last_page: 1,
        prev_page_url: null,
        next_page_url: null,
        total: 1,
      }}
      filters={{}}
      statuses={statuses}
      canSeeIp={false}
    />,
  );
  const select = screen.getByRole('combobox', { name: 'Status of #100009' });
  expect(options(select).map((o) => o.textContent)).toEqual([
    'Open',
    'Processing',
  ]);
  choose(select, 'Processing');
  expect(screen.getByRole('alertdialog')).toHaveTextContent('Processing');
  expect(patch).not.toHaveBeenCalled();
  fireEvent.click(
    within(screen.getByRole('alertdialog')).getByRole('button', {
      name: 'Cancel',
    }),
  );
  choose(
    screen.getByRole('combobox', { name: 'Status of #100009' }),
    'Processing',
  );
  fireEvent.click(
    within(screen.getByRole('alertdialog')).getByRole('button', {
      name: 'change_status',
    }),
  );
  expect(patch).toHaveBeenCalledWith(
    '/admin/orders/9/status',
    expect.objectContaining({ status_id: '2', version: 4 }),
    expect.anything(),
  );
});

it('updates only selected orders after reviewing a bulk status and clears selection when the page changes', () => {
  const patch = vi.spyOn(router, 'patch').mockImplementation(() => {});
  const props = {
    ...shell,
    orders: {
      data: [row, { ...row, id: '10', number: '100010', version: 2 }],
      current_page: 1,
      last_page: 2,
      prev_page_url: null,
      next_page_url: '/admin/orders?page=2',
      total: 3,
    },
    filters: {},
    statuses,
    canSeeIp: false,
  };
  const { rerender } = render(<OrdersIndex {...props} />);
  fireEvent.click(screen.getByRole('checkbox', { name: 'Select this page' }));
  fireEvent.click(
    screen.getByRole('checkbox', { name: 'Select order #100010' }),
  );
  const select = screen.getByRole('combobox', { name: 'new_status' });
  expect(options(select).map((o) => o.textContent)).not.toContain('Archived');
  choose(select, 'Processing');
  fireEvent.click(
    screen.getByRole('button', { name: 'Update selected status' }),
  );
  expect(patch).not.toHaveBeenCalled();
  expect(screen.getByRole('alertdialog')).toHaveTextContent('#100009');
  expect(screen.getByRole('alertdialog')).not.toHaveTextContent('#100010');
  fireEvent.click(
    within(screen.getByRole('alertdialog')).getByRole('button', {
      name: 'change_status',
    }),
  );
  expect(patch).toHaveBeenCalledWith(
    '/admin/orders/bulk-status',
    expect.objectContaining({
      status_id: '2',
      orders: [{ id: '9', version: 4 }],
    }),
    expect.anything(),
  );
  expect(
    screen.getByRole('button', { name: 'Update selected status' }),
  ).toBeDisabled();
  rerender(
    <OrdersIndex {...props} orders={{ ...props.orders, current_page: 2 }} />,
  );
  expect(
    screen.queryByRole('button', { name: 'Update selected status' }),
  ).not.toBeInTheDocument();
});

it('hides order selection without the status permission', () => {
  shared.props.capabilities['orders.status'] = false;
  render(
    <OrdersIndex
      {...shell}
      orders={{
        data: [row],
        current_page: 1,
        last_page: 1,
        prev_page_url: null,
        next_page_url: null,
        total: 1,
      }}
      filters={{}}
      statuses={statuses}
      canSeeIp={false}
    />,
  );
  expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
});

it('shows server errors when an updated row resets the selection', () => {
  shared.props.errors = { version: 'An order changed. Refresh and try again.' };
  render(
    <OrdersIndex
      {...shell}
      orders={{
        data: [{ ...row, version: 5 }],
        current_page: 1,
        last_page: 1,
        prev_page_url: null,
        next_page_url: null,
        total: 1,
      }}
      filters={{}}
      statuses={statuses}
      canSeeIp={false}
    />,
  );
  expect(screen.getByRole('alert')).toHaveTextContent(
    'An order changed. Refresh and try again.',
  );
});

it('shows email results with a retry and hides the customer IP without the capability', () => {
  const post = vi.spyOn(router, 'post').mockImplementation(() => {});
  const order: OrderDetail = {
    id: '9',
    number: '100009',
    version: 4,
    status: statuses[2],
    locale: 'en',
    customer_id: null,
    first_name: 'Maria',
    last_name: 'Example',
    email: 'maria@example.test',
    phone: null,
    ip_address: null,
    currency: 'EUR',
    tax_method: 'unit_price',
    tax_exempt: false,
    stock_held: false,
    totals: {
      subtotal: '€20.00',
      discount: '€0.00',
      has_discount: false,
      tax_added: '€4.80',
      has_added_tax: true,
      tax: '€4.80',
      tax_included: '€0.00',
      tax_included_raw: '0',
      shipping: '€0.00',
      total: '€24.80',
    },
    billing: null,
    shipping: null,
    customer_note: null,
    internal_note: 'secret',
    invoice_requested: false,
    created_at: row.created_at,
    updated_at: row.updated_at,
    items: [],
    taxes: [],
    comments: [],
    history: [
      {
        id: '5',
        from: null,
        to: statuses[0],
        at: row.created_at,
        actor: 'Admin',
        source: 'admin',
        note: null,
        emails: [
          {
            id: '7',
            kind: 'status',
            state: 'failed',
            attempts: 1,
            recipient: 'maria@example.test',
            subject: null,
            error: 'mail_rejected',
            sent_at: null,
            last_attempt_at: row.created_at,
          },
        ],
      },
    ],
  };
  const page = (
    <OrderShow
      {...shell}
      order={order}
      statuses={statuses}
      payment={{
        method: { name: 'Cash on delivery', kind: 'offline', online: false },
        status: 'partially_paid',
        paid: '€10.00',
        outstanding: '€14.80',
        outstanding_raw: '14.80',
        decimals: 2,
        records: [
          {
            id: '5',
            kind: 'manual',
            state: 'captured',
            amount: '€10.00',
            method: 'Cash on delivery',
            provider_reference: null,
            capture_reference: null,
            detail: null,
            note: 'Courier',
            recorded_by: 'Staff',
            created_at: row.created_at,
            completed_at: row.created_at,
            can_refresh: false,
          },
        ],
        link: null,
        can_record: true,
        can_gift_card: false,
      }}
      shipping={{
        method: { id: '4', name: 'ACS standard' },
        charge: '€4.50',
        details: null,
        provider: { id: 'acs', name: 'ACS Courier' },
        can_create: true,
        create_blocker: null,
        shipments: [
          {
            id: '12',
            provider: 'acs',
            provider_name: 'ACS Courier',
            account_name: 'Athens',
            state: 'created',
            tracking_number: '7200000001',
            extra_numbers: [],
            pickup_date: '2026-10-15',
            weight_kg: '1.5',
            packages: 1,
            cod_amount: null,
            manifest_number: null,
            label_printed_at: null,
            tracking_state: 'in_transit',
            tracking_code: null,
            tracking_summary: 'Arrived at hub',
            tracking_events: [],
            tracking_checked_at: row.created_at,
            delivered_at: null,
            returned_at: null,
            error: null,
            created_by: 'Staff',
            created_at: row.created_at,
            stale: false,
            actions: { label: true, track: true, cancel: true, resolve: false },
            label_formats: [
              { value: 'laser', label: 'Laser' },
              { value: 'thermal', label: 'Thermal' },
            ],
          },
        ],
      }}
      shipmentForm={{
        fields: [
          {
            key: 'weight',
            type: 'decimal',
            label: 'Weight',
            hint: '',
            required: true,
            options: [],
            max_length: 10,
            min: '0.5',
            max: '999',
            value: '1.5',
            set: false,
          },
        ],
        request_key: '0b6f1f8e-2c51-4c1e-9d3e-5f0f3b0c9a11',
      }}
    />
  );
  const { rerender } = render(page);
  expect(screen.queryByRole('region', { name: 'Invoice details' })).toBeNull();
  // Payment state is shown apart from the order status, with the collected record.
  expect(screen.getByText('status_partially_paid')).toBeVisible();
  expect(screen.getByText('Courier')).toBeVisible();
  fireEvent.click(screen.getByRole('button', { name: 'record' }));
  expect(post).toHaveBeenCalledWith(
    '/admin/orders/9/payments',
    { amount: '14.80', note: '' },
    expect.anything(),
  );
  expect(screen.getByText('mail_error_mail_rejected')).toBeVisible();
  expect(screen.queryByText('ip_address')).not.toBeInTheDocument();
  expect(screen.getByText('status_inactive', { exact: false })).toBeVisible();
  fireEvent.click(screen.getByRole('button', { name: 'retry' }));
  expect(post).toHaveBeenCalledWith(
    '/admin/orders/9/notifications/7/retry',
    {},
    expect.anything(),
  );
  // The current (inactive) status cannot be re-submitted.
  expect(screen.getByRole('button', { name: 'change_status' })).toBeDisabled();
  // Carrier shipment: label in the chosen format, tracking refresh, and a second shipment needs confirmation.
  expect(screen.getByText('Arrived at hub', { exact: false })).toBeVisible();
  expect(screen.getByRole('link', { name: 'print_label' })).toHaveAttribute(
    'href',
    '/admin/orders/9/shipments/12/label?format=laser',
  );
  fireEvent.click(screen.getByRole('button', { name: 'track' }));
  expect(post).toHaveBeenCalledWith(
    '/admin/orders/9/shipments/12/track',
    {},
    expect.anything(),
  );
  fireEvent.click(screen.getByRole('button', { name: 'create_additional' }));
  expect(screen.getByLabelText('Weight')).toHaveValue('1.5');
  fireEvent.click(screen.getByRole('checkbox', { name: 'additional_confirm' }));
  fireEvent.click(screen.getByRole('button', { name: 'create_submit' }));
  expect(post).toHaveBeenCalledWith(
    '/admin/orders/9/shipments',
    {
      fields: { weight: '1.5' },
      additional: true,
      request_key: '0b6f1f8e-2c51-4c1e-9d3e-5f0f3b0c9a11',
    },
    expect.anything(),
  );

  expect(screen.getAllByText('ACS standard')[0]).toBeVisible();
  expect(screen.getByText('tax_added').closest('div')).toHaveTextContent(
    '€4.80',
  );
  expect(screen.queryByText('discount_total')).toBeNull();
  rerender(
    cloneElement(page, {
      order: {
        ...order,
        invoice_requested: true,
        invoice_details: {
          country_code: 'GR',
          country: 'Greece',
          vat_number: '000000012',
          business_name: 'Demo <script>Company</script>',
          provider: 'vies',
          verified_at: row.created_at,
        },
        billing: {
          first_name: 'Maria',
          last_name: 'Example',
          company: null,
          line1: 'Ermou 12',
          line2: null,
          city: 'Athens',
          postal_code: '10563',
          country_code: 'GR',
          country: 'Greece',
          region_code: null,
          phone: null,
          floor: '0',
          bell_name: 'Example bell',
          notes: 'Use side door',
        },
        totals: {
          ...order.totals,
          subtotal: '€124.00',
          discount: '€12.40',
          has_discount: true,
          tax: '€21.60',
          tax_included: '€21.60',
          tax_included_raw: '21.6',
          tax_added: '€0.00',
          has_added_tax: false,
          total: '€111.60',
        },
        items: [
          {
            id: '1',
            product_id: '11',
            name: 'Discounted lamp',
            sku: 'LP',
            variation: null,
            quantity: 1,
            deducted: 0,
            unit_price: '€124.00',
            catalog_price: '€124.00',
            price_overridden: false,
            discount: '€12.40',
            subtotal: '€124.00',
            tax: '€21.60',
            tax_included: true,
            unit_net: '€90.00',
            vat_rate: '24%',
            unit_gross: '€111.60',
            total: '€111.60',
          },
        ],
        taxes: [
          {
            name: 'Included VAT',
            rate: '24%',
            included: true,
            amount: '€21.60',
          },
        ],
      },
    }),
  );
  const invoice = screen.getByRole('region', { name: 'Invoice details' });
  expect(
    within(invoice).getByText('Business name').nextElementSibling,
  ).toHaveTextContent('Demo <script>Company</script>');
  expect(
    within(invoice).getByText('VAT number').nextElementSibling,
  ).toHaveTextContent('000000012');
  expect(
    within(invoice).getByText('VAT registration country').nextElementSibling,
  ).toHaveTextContent('Greece (GR)');
  expect(
    within(invoice).getByText('Validation method').nextElementSibling,
  ).toHaveTextContent('VIES');
  expect(
    within(invoice)
      .getByText('Checked at')
      .nextElementSibling?.querySelector('time'),
  ).toHaveAttribute('datetime', row.created_at);
  expect(invoice.querySelector('script')).toBeNull();
  const invoiceSummary = screen.getByText(/^Invoice requested/).closest('div');
  expect(invoiceSummary).toHaveTextContent('Yes');
  expect(invoiceSummary).not.toHaveTextContent('Demo');
  expect(screen.getByText('discount_total').closest('div')).toHaveTextContent(
    '−€12.40',
  );
  expect(screen.queryByText('tax_added')).toBeNull();
  expect(screen.queryByText('tax_total')).toBeNull();
  expect(screen.getByText('Included VAT (24%): €21.60')).toBeVisible();
  expect(screen.getByText('line_discount: −€12.40')).toBeVisible();
  expect(screen.queryByRole('link', { name: 'Discounted lamp' })).toBeNull();
  expect(screen.getByText('Example bell')).toBeVisible();
  expect(screen.getByText('Use side door')).toBeVisible();
  expect(screen.getByText('0')).toBeVisible();
});

it('builds order lines with price overrides and asks the server for totals', () => {
  const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  render(
    <OrderForm
      {...shell}
      order={null}
      statuses={statuses.filter((s) => s.active)}
      products={[
        {
          id: '3',
          name: 'Desk lamp',
          sku: 'LAMP',
          status: 'active',
          quantity: 4,
          price: '10.00',
          price_formatted: '€10.00',
        },
      ]}
      customers={[]}
      countries={[{ code: 'GR', name: 'Greece' }]}
      defaultLocale="en"
      quote={null}
      currency={{ code: 'EUR', decimals: 2 }}
      paymentMethods={[{ id: '1', name: 'Cash on delivery' }]}
      shippingMethods={[
        {
          id: '4',
          name: 'Courier',
          calculation: 'manual',
          payment_method_ids: ['2'],
        },
      ]}
    />,
  );
  fireEvent.click(screen.getByRole('button', { name: 'add' }));
  expect(screen.getByRole('button', { name: 'added' })).toBeDisabled();
  fireEvent.change(screen.getByLabelText('Quantity of Desk lamp'), {
    target: { value: '2' },
  });
  fireEvent.change(screen.getByLabelText('Unit price of Desk lamp'), {
    target: { value: '8.50' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'calculate' }));
  expect(visit.mock.calls[0][0]).toBe('/admin/orders/quote');
  const data = visit.mock.calls[0][1]?.data as Record<string, unknown>;
  expect(data.items).toEqual([
    { item_id: null, product_id: '3', quantity: '2', unit_price: '8.50' },
  ]);
  expect(data.status_id).toBe('1');
  // The first offered payment method is preselected for new orders.
  expect(data.payment_method_id).toBe('1');
  expect(data).not.toHaveProperty('ip_address');
  // No shipping method is preselected; choosing one sends it (a payment it excludes is flagged).
  expect(data.shipping_method_id).toBeNull();
  choose(screen.getByRole('combobox', { name: 'shipping_method' }), 'Courier');
  expect(screen.getByText('payment_not_for_shipping')).toBeVisible();
  fireEvent.change(screen.getByLabelText('shipping_amount'), {
    target: { value: '3.20' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'calculate' }));
  const next = visit.mock.calls[1][1]?.data as Record<string, unknown>;
  expect(next.shipping_method_id).toBe('4');
  expect(next.shipping_amount).toBe('3.20');
});

it('inserts variables at the cursor, previews live without saving and sends tests', async () => {
  vi.useFakeTimers();
  const fetchMock = vi.fn().mockResolvedValue({
    ok: true,
    json: async () => ({ subject: 'Order 100123', html: '<p>Rendered</p>' }),
  });
  vi.stubGlobal('fetch', fetchMock);
  const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  render(
    <OrderEmailEdit
      {...shell}
      kind="status"
      status={statuses[1]}
      templates={{
        en: { id: '11', version: 2, subject: 'Order ', body: '<p>Hello</p>' },
      }}
      variables={[
        { name: 'order_number', label: 'Order number', block: false },
        { name: 'order_items', label: 'Items', block: true },
      ]}
      defaultLocale="en"
    />,
  );
  const subject = screen.getByLabelText('template_subject') as HTMLInputElement;
  fireEvent.focus(subject);
  subject.setSelectionRange(6, 6);
  // Block variables are not offered while the subject is focused.
  expect(screen.queryByRole('button', { name: '{{order_items}}' })).toBeNull();
  fireEvent.click(screen.getByRole('button', { name: '{{order_number}}' }));
  expect(subject).toHaveValue('Order {{order_number}}');
  await act(async () => {
    await vi.advanceTimersByTimeAsync(600);
  });
  const [url, init] = fetchMock.mock.calls.at(-1)!;
  expect(url).toBe('/admin/order-emails/preview');
  expect(JSON.parse(init.body)).toMatchObject({
    kind: 'status',
    status_id: '2',
    locale: 'en',
    subject: 'Order {{order_number}}',
  });
  expect(screen.getByTitle('live_preview')).toHaveAttribute(
    'srcdoc',
    '<p>Rendered</p>',
  );
  expect(visit).not.toHaveBeenCalled();
  fireEvent.change(screen.getByLabelText('test_recipient'), {
    target: { value: 'support@example.com' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'test_send' }));
  expect(visit.mock.calls[0][0]).toBe('/admin/order-emails/test');
  expect(visit.mock.calls[0][1]?.data).toMatchObject({
    recipient: 'support@example.com',
    subject: 'Order {{order_number}}',
  });
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
  expect(visit.mock.calls[1][0]).toBe('/admin/order-emails/2/templates/11');
  vi.useRealTimers();
  vi.unstubAllGlobals();
});

it('reorders statuses by dragging with the same grip as payment methods', () => {
  const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
  render(
    <OrderStatusesIndex
      {...shell}
      statuses={statuses.map((s) => ({
        ...s,
        version: 1,
        orders: 0,
        templates: [],
      }))}
    />,
  );
  expect(
    screen.queryByRole('button', { name: /Move .* (up|down)/ }),
  ).not.toBeInTheDocument();
  const from = screen
    .getByRole('button', { name: 'Drag to reorder: Archived' })
    .closest('tr')!;
  const target = screen
    .getByRole('button', { name: 'Drag to reorder: Open' })
    .closest('tr')!;
  const dataTransfer = { effectAllowed: '', dropEffect: '', setData: vi.fn() };
  fireEvent.dragStart(from, { dataTransfer });
  fireEvent.dragOver(target, { dataTransfer });
  fireEvent.drop(target, { dataTransfer });
  expect(post).toHaveBeenCalledWith(
    '/admin/order-statuses/order',
    { ids: ['3', '1', '2'] },
    expect.objectContaining({ preserveScroll: true }),
  );
  expect(
    screen.getAllByRole('button', { name: /Drag to reorder:/ })[0],
  ).toHaveAccessibleName('Drag to reorder: Archived');
});

it('supports keyboard reordering and ignores the list boundaries', () => {
  const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
  render(
    <OrderStatusesIndex
      {...shell}
      statuses={statuses.map((s) => ({
        ...s,
        version: 1,
        orders: 0,
        templates: [],
      }))}
    />,
  );
  fireEvent.keyDown(
    screen.getByRole('button', { name: 'Drag to reorder: Open' }),
    { key: 'ArrowUp' },
  );
  expect(post).not.toHaveBeenCalled();
  fireEvent.keyDown(
    screen.getByRole('button', { name: 'Drag to reorder: Processing' }),
    { key: 'ArrowDown' },
  );
  expect(post).toHaveBeenCalledWith(
    '/admin/order-statuses/order',
    { ids: ['1', '3', '2'] },
    expect.objectContaining({ preserveScroll: true }),
  );
});

it('previews and saves the full invoice document and repeating row templates', async () => {
  vi.useFakeTimers();
  const fetchMock = vi.fn().mockResolvedValue({
    ok: true,
    json: async () => ({
      subject: 'Invoice',
      html: '<p>Invoice preview</p>',
    }),
  });
  vi.stubGlobal('fetch', fetchMock);
  const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  render(
    <OrderEmailEdit
      {...shell}
      kind="invoice"
      status={null}
      templates={{
        en: {
          id: '5',
          version: 2,
          subject: 'Invoice',
          body: '<table><tbody>{{invoice_items}}</tbody></table>{{invoice_totals}}',
          item: '<tr><td>{{item_name}}</td></tr>',
          total: '<tr><td>{{invoice_total_value}}</td></tr>',
        },
      }}
      variables={[]}
      defaultLocale="en"
    />,
  );
  fireEvent.change(screen.getByLabelText('invoice_item_template'), {
    target: { value: '<tr><td>Edited {{item_name}}</td></tr>' },
  });
  await act(async () => {
    await vi.advanceTimersByTimeAsync(600);
  });
  expect(JSON.parse(fetchMock.mock.calls.at(-1)![1].body)).toMatchObject({
    kind: 'invoice',
    item: '<tr><td>Edited {{item_name}}</td></tr>',
    total: '<tr><td>{{invoice_total_value}}</td></tr>',
  });
  expect(visit).not.toHaveBeenCalled();
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
  expect(visit.mock.calls[0][0]).toBe('/admin/order-emails/order-summary/5');
  expect(visit.mock.calls[0][1]?.data).toMatchObject({
    version: 2,
    item: '<tr><td>Edited {{item_name}}</td></tr>',
    total: '<tr><td>{{invoice_total_value}}</td></tr>',
  });
  vi.useRealTimers();
  vi.unstubAllGlobals();
});
