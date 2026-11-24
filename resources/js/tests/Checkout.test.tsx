import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { router } from '@inertiajs/react';
import { choose } from './select';
import CartProvider from '../storefront/themes/Default/CartProvider';
import Checkout from '../storefront/themes/Default/Checkout';
import type {
  CheckoutPageData,
  CheckoutQuote,
  PublicBlock,
} from '../storefront/types';

vi.mock('../lib/useTranslation', () => ({
  useTranslation: () => (key: string, params?: Record<string, unknown>) =>
    params ? `${key} ${JSON.stringify(params)}` : key,
}));

const format = {
  code: 'EUR',
  symbol: '€',
  position: 'before' as const,
  thousands: ',',
  decimal: '.',
  decimals: 2,
};

const quote = (changes: Partial<CheckoutQuote> = {}): CheckoutQuote => ({
  needs_shipping: true,
  shipping_required: true,
  blocked: false,
  shipping_methods: [],
  shipping_method_id: null,
  payment_methods: [
    {
      id: '1',
      name: 'Cash on delivery',
      description: 'Pay the courier',
      fee: '2.00',
      image: null,
    },
    {
      id: '3',
      name: 'Bank deposit',
      description: 'IBAN GR00',
      fee: null,
      image: null,
    },
  ],
  payment_method_id: null,
  totals: {
    subtotal: '20.00',
    discount: '0.00',
    shipping: null,
    payment_fee: '0.00',
    tax_added: '0.00',
    tax_included: '0.00',
    total: '20.00',
    gift_card: '0.00',
    due: '20.00',
  },
  discounts: [],
  coupon: null,
  gift_card: null,
  ...changes,
});

const page = (changes: Partial<CheckoutPageData> = {}): CheckoutPageData => ({
  cart: {
    id: 'c0ffee00-0000-4000-8000-000000000000',
    lines: [
      {
        id: '7',
        name: 'Speaker',
        url: '/audio/speaker',
        image: null,
        price: '10.00',
        available: true,
        quantity: 2,
        total: '20.00',
        problem: null,
        rules: { min: 1, max: 10, step: 1 },
      },
    ],
    count: 2,
    subtotal: '20.00',
    coupon: null,
    promotions: [],
    gift_card: null,
    total: '20.00',
    codes: true,
    currency_format: format,
  },
  account_required: false,
  contact: null,
  addresses: [],
  defaults: { shipping: null, billing: null },
  countries: [{ code: 'GR', name: 'Greece' }],
  regions: {},
  quote: quote(),
  ...changes,
});

const block = (data: CheckoutPageData): PublicBlock =>
  ({
    id: 'b',
    type: 'main_content',
    template: 'main_content',
    content: { checkout: data },
  }) as unknown as PublicBlock;

beforeEach(() => {
  vi.useFakeTimers();
  vi.stubGlobal('crypto', {
    randomUUID: () => 'f00dcafe-0000-4000-8000-000000000000',
  });
});
afterEach(() => {
  vi.useRealTimers();
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

it('asks the server for the totals when the shopper chooses, and shows only what it answers', async () => {
  const fetchMock = vi.fn().mockResolvedValue({
    ok: true,
    json: async () => ({
      quote: quote({
        payment_method_id: '1',
        totals: {
          ...quote().totals!,
          payment_fee: '2.00',
          total: '22.00',
          due: '22.00',
        },
      }),
    }),
  });
  vi.stubGlobal('fetch', fetchMock);
  render(<Checkout block={block(page())} />);
  // Guests type an e-mail, or sign in from a window without leaving the checkout.
  expect(screen.getByLabelText(/customers.email/)).toBeTruthy();
  fireEvent.click(
    screen.getByRole('button', { name: 'storefront.checkout_sign_in' }),
  );
  const signIn = screen.getByRole('dialog', {
    name: 'storefront.checkout_sign_in_title',
  });
  expect(within(signIn).getByLabelText(/auth.email/)).toBeTruthy();
  expect(within(signIn).getByLabelText(/auth.password/)).toBeTruthy();
  expect(
    within(signIn).getByRole('link', { name: 'auth.forgot' }),
  ).toHaveAttribute('href', '/forgot-password');
  fireEvent.click(
    within(signIn).getByRole('button', { name: 'storefront.close' }),
  );
  expect(screen.queryByRole('dialog')).toBeNull();
  expect(
    screen.getByText('storefront.checkout_delivery_needs_address'),
  ).toBeTruthy();

  fireEvent.click(screen.getByRole('radio', { name: /Cash on delivery/ }));
  // The chosen method's instructions show at once; the totals wait for the server.
  expect(screen.getByText('Pay the courier')).toBeTruthy();
  expect(fetchMock).not.toHaveBeenCalled();
  await act(async () => {
    await vi.advanceTimersByTimeAsync(500);
  });
  expect(fetchMock).toHaveBeenCalledTimes(1);
  const [url, init] = fetchMock.mock.calls[0];
  expect(url).toBe('/checkout/quote');
  const sent = JSON.parse(init.body);
  expect(sent.payment_method_id).toBe('1');
  // Nothing priced is ever sent.
  expect(Object.keys(sent)).not.toContain('total');
  expect(Object.keys(sent)).not.toContain('items');
  expect(screen.getByText('storefront.checkout_payment_fee')).toBeTruthy();
  expect(document.querySelector('.is-total')?.textContent).toContain('22');
});

it('offers only signing in or registering when the store requires an account', () => {
  render(<Checkout block={block(page({ account_required: true }))} />);
  expect(
    screen
      .getByRole('link', { name: 'storefront.checkout_sign_in' })
      .getAttribute('href'),
  ).toBe('/login?return=checkout');
  expect(
    screen
      .getByRole('link', { name: 'storefront.checkout_create_account' })
      .getAttribute('href'),
  ).toBe('/register?return=checkout');
  expect(
    screen.queryByRole('button', { name: 'storefront.checkout_place' }),
  ).toBeNull();
});

it('lets a customer pick a saved address and refuses to place a cart that must be reviewed', () => {
  render(
    <Checkout
      block={block(
        page({
          contact: {
            first_name: 'Ria',
            last_name: 'Stone',
            email: 'ria@example.test',
            phone: '',
          },
          addresses: [
            {
              id: '5',
              label: 'Home',
              lines: ['Ria Stone', 'Ermou 7', '10563 Athens'],
            },
          ],
          defaults: { shipping: '5', billing: '5' },
          quote: quote({ blocked: true }),
        }),
      )}
    />,
  );
  expect(screen.queryByLabelText(/customers.email/)).toBeNull();
  expect(
    (screen.getByRole('radio', { name: /Home/ }) as HTMLInputElement).checked,
  ).toBe(true);
  // No address form until a new address is chosen.
  expect(screen.queryByLabelText(/customers.line1/)).toBeNull();
  fireEvent.click(
    screen.getByRole('radio', { name: 'storefront.checkout_new_address' }),
  );
  expect(screen.getByLabelText(/customers.line1/)).toBeTruthy();
  expect(screen.getByText('storefront.checkout_save_address')).toBeTruthy();
  expect(
    (
      screen.getByRole('button', {
        name: 'storefront.checkout_place',
      }) as HTMLButtonElement
    ).disabled,
  ).toBe(true);
});

it('confirms the order and offers to pay what an online method still needs', () => {
  render(
    <Checkout
      block={block({
        placed: {
          number: '1042',
          email: 'maria@example.test',
          lines: [
            {
              name: 'Speaker',
              variation: 'Colour: Black · Size: L',
              quantity: 2,
              total: '20.00',
              image: null,
              url: '/audio/speaker',
            },
          ],
          totals: {
            subtotal: '20.00',
            discount: '0.00',
            shipping: '3.50',
            payment_fee: '0.00',
            tax_added: '0.00',
            total: '23.50',
            due: '23.50',
          },
          shipping_method: 'Courier',
          invoice_requested: false,
          note: 'Ring twice\nThanks',
          address: {
            name: 'Maria Guest',
            lines: ['Ermou 12', '10563 Athens'],
            phone: null,
            extras: [{ label: 'Floor', value: '3' }],
          },
          payment: {
            name: 'PayPal',
            state: 'unpaid',
            instructions: '',
            pay_url: '/pay/9?signature=abc',
          },
          account_url: null,
          currency_format: format,
        },
      })}
    />,
  );
  expect(
    screen.getByText('storefront.checkout_order_number {"number":"1042"}'),
  ).toBeTruthy();
  expect(
    screen
      .getByRole('link', { name: 'storefront.checkout_pay_now' })
      .getAttribute('href'),
  ).toBe('/pay/9?signature=abc');
  expect(screen.getByText('Ermou 12')).toBeTruthy();
  // The courier's details are labelled apart; options of a line stack under it.
  expect(screen.getByText('Floor').nextSibling?.textContent).toBe('3');
  expect(screen.getByText('Size: L')).toBeTruthy();
  // The buyer's comments get their own section, line breaks kept.
  expect(screen.getByText('storefront.checkout_note')).toBeTruthy();
  expect(screen.getByText(/Ring twice/).textContent).toBe('Ring twice\nThanks');
  // An online method that is not paid yet offers to pay; no bank details to copy.
  expect(screen.queryByRole('button', { name: /checkout_copy/ })).toBeNull();
});

it('lets the buyer copy the payment reference and the amount for an offline method', async () => {
  const writeText = vi.fn().mockResolvedValue(undefined);
  vi.stubGlobal('navigator', { clipboard: { writeText } });
  render(
    <Checkout
      block={block({
        placed: {
          number: '1042',
          email: 'maria@example.test',
          lines: [],
          totals: {
            subtotal: '20.00',
            discount: '0.00',
            shipping: null,
            payment_fee: '0.00',
            tax_added: '0.00',
            total: '20.00',
            due: '20.00',
          },
          shipping_method: null,
          invoice_requested: true,
          note: '  ',
          address: null,
          payment: {
            name: 'Bank deposit',
            state: 'unpaid',
            instructions: 'IBAN GR00 0000',
            pay_url: null,
          },
          account_url: null,
          currency_format: format,
        },
      })}
    />,
  );
  expect(screen.getByText('IBAN GR00 0000')).toBeTruthy();
  fireEvent.click(screen.getByRole('button', { name: /checkout_reference/ }));
  expect(writeText).toHaveBeenCalledWith('1042');
  expect(screen.getByText('storefront.checkout_copied')).toBeTruthy();
  fireEvent.click(screen.getByRole('button', { name: /checkout_due/ }));
  expect(writeText).toHaveBeenLastCalledWith('20.00');
  expect(screen.getByText('storefront.invoice_requested_note')).toBeTruthy();
  // Blank comments show no section.
  expect(screen.queryByText('storefront.checkout_note')).toBeNull();
});

it('keeps the place-order button disabled until every required step is filled in', () => {
  vi.stubGlobal(
    'fetch',
    vi.fn(() => new Promise(() => {})),
  );
  render(
    <Checkout
      block={block(
        page({
          contact: {
            first_name: 'Ria',
            last_name: 'Stone',
            email: 'ria@example.test',
            phone: '+30 210 000 0000',
          },
          addresses: [
            {
              id: '5',
              label: 'Home',
              lines: ['Ria Stone', 'Ermou 7', '10563 Athens'],
            },
          ],
          defaults: { shipping: '5', billing: '5' },
          quote: quote({ shipping_required: false }),
        }),
      )}
    />,
  );
  const place = () =>
    screen.getByRole('button', {
      name: 'storefront.checkout_place',
    }) as HTMLButtonElement;
  expect(place().disabled).toBe(true);
  expect(screen.getByText(/storefront.checkout_missing/).textContent).toContain(
    'storefront.checkout_payment',
  );
  fireEvent.click(screen.getByRole('radio', { name: /Bank deposit/ }));
  expect(place().disabled).toBe(false);
  expect(screen.queryByText(/storefront.checkout_missing/)).toBeNull();
  // A new address must be filled in before it counts.
  fireEvent.click(
    screen.getByRole('radio', { name: 'storefront.checkout_new_address' }),
  );
  expect(place().disabled).toBe(true);
});

it('applies a coupon or gift card through the cart and asks for new totals', async () => {
  const fetchMock = vi.fn(async (url: string) => ({
    ok: true,
    json: async () =>
      url === '/cart/codes'
        ? { count: 2, cart: page().cart }
        : { quote: quote({ coupon: { code: 'SUMMER', problem: null } }) },
  }));
  vi.stubGlobal('fetch', fetchMock);
  render(
    <CartProvider count={2}>
      <Checkout block={block(page())} />
    </CartProvider>,
  );
  fireEvent.change(screen.getByLabelText('storefront.cart_code_label'), {
    target: { value: 'SUMMER' },
  });
  await act(async () => {
    fireEvent.click(
      screen.getByRole('button', { name: 'storefront.cart_code_apply' }),
    );
  });
  expect(fetchMock.mock.calls[0][0]).toBe('/cart/codes');
  await act(async () => {
    await vi.advanceTimersByTimeAsync(500);
  });
  expect(fetchMock.mock.calls[1][0]).toBe('/checkout/quote');
  expect(screen.getByText(/storefront.cart_coupon/)).toBeTruthy();
});

it('asks for the block’s checkboxes above the button and opens pages in a dialog', () => {
  vi.stubGlobal(
    'fetch',
    vi.fn(() => new Promise(() => {})),
  );
  const data = page({
    contact: {
      first_name: 'Ria',
      last_name: 'Stone',
      email: 'ria@example.test',
      phone: '+30 210 000 0000',
    },
    addresses: [{ id: '5', label: 'Home', lines: ['Ria Stone'] }],
    defaults: { shipping: '5', billing: '5' },
    quote: quote({ shipping_required: false, payment_method_id: '3' }),
  });
  render(
    <Checkout
      block={
        {
          ...block(data),
          content: {
            checkout: data,
            checkout_options: {
              pages: [
                {
                  id: '9',
                  name: 'Terms of use',
                  content: '<p>The terms</p>',
                  required: true,
                },
              ],
              newsletter: { list: '2', label: 'Send me offers' },
              invoice: true,
            },
          },
        } as unknown as PublicBlock
      }
    />,
  );
  const place = () =>
    screen.getByRole('button', {
      name: 'storefront.checkout_place',
    }) as HTMLButtonElement;
  expect(place().disabled).toBe(true);
  expect(screen.getByText(/storefront.checkout_missing/).textContent).toContain(
    'storefront.checkout_acceptance',
  );
  // Reading the page opens it in place and does not tick the box.
  fireEvent.click(screen.getByRole('button', { name: 'Terms of use' }));
  expect(screen.getByText('The terms')).toBeTruthy();
  const terms = screen.getByRole('checkbox', {
    name: /storefront.checkout_accept Terms of use/,
  }) as HTMLInputElement;
  expect(terms.checked).toBe(false);
  fireEvent.click(terms);
  expect(place().disabled).toBe(false);
  // Optional boxes start unticked and never block the order.
  for (const name of ['storefront.checkout_invoice', 'Send me offers']) {
    expect(
      (screen.getByRole('checkbox', { name }) as HTMLInputElement).checked,
    ).toBe(false);
  }
});

it('opens VAT verification before selecting invoice and clears approval when edited', async () => {
  const place = vi.spyOn(router, 'post').mockImplementation(() => undefined);
  const verify = vi
    .fn()
    .mockResolvedValueOnce({
      ok: false,
      json: async () => ({ errors: { vat_number: ['Registry unavailable'] } }),
    })
    .mockResolvedValueOnce({
      ok: true,
      json: async () => ({
        verification: {
          country_code: 'GB',
          vat_number: '123456789',
          business_name: 'Verified Company',
        },
      }),
    });
  vi.stubGlobal('fetch', verify);
  const data = page({
    invoice_countries: [
      { code: 'GR', name: 'Greece' },
      { code: 'GB', name: 'United Kingdom' },
    ],
  });
  render(
    <Checkout
      block={
        {
          ...block(data),
          content: {
            checkout: data,
            checkout_options: { pages: [], newsletter: null, invoice: true },
          },
        } as unknown as PublicBlock
      }
    />,
  );
  const invoice = screen.getByRole('checkbox', {
    name: 'storefront.checkout_invoice',
  });
  fireEvent.click(invoice);
  expect(invoice).not.toBeChecked();
  let dialog = screen.getByRole('dialog', { name: 'checkout.modal_title' });
  expect(within(dialog).getByText('checkout.sign_in')).toBeTruthy();
  expect(
    within(dialog).queryByRole('button', { name: 'checkout.verify' }),
  ).toBeNull();
  fireEvent.click(
    within(dialog).getByRole('button', { name: 'storefront.close' }),
  );
  expect(invoice).not.toBeChecked();
  fireEvent.click(invoice);
  dialog = screen.getByRole('dialog', { name: 'checkout.modal_title' });
  choose(
    within(dialog).getByRole('combobox', { name: 'checkout.country' }),
    'United Kingdom',
  );
  fireEvent.change(within(dialog).getByLabelText(/checkout.vat_number/), {
    target: { value: 'GB 123456789' },
  });
  await act(async () =>
    fireEvent.click(
      within(dialog).getByRole('button', { name: 'checkout.verify' }),
    ),
  );
  expect(within(dialog).getByText('Registry unavailable')).toBeTruthy();
  expect(invoice).not.toBeChecked();
  await act(async () =>
    fireEvent.click(
      within(dialog).getByRole('button', { name: 'checkout.verify' }),
    ),
  );
  expect(invoice).toBeChecked();
  expect(place).not.toHaveBeenCalled();
  expect(screen.queryByRole('dialog')).toBeNull();
  expect(screen.getByText(/Verified Company/)).toBeTruthy();
  expect(JSON.parse(verify.mock.calls[1][1].body)).toEqual({
    country_code: 'GB',
    vat_number: 'GB 123456789',
  });
  fireEvent.click(screen.getByRole('button', { name: 'common.edit' }));
  expect(invoice).not.toBeChecked();
  expect(
    screen.getByRole('dialog', { name: 'checkout.modal_title' }),
  ).toBeTruthy();
});
