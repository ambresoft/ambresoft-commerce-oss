import { fireEvent, render, screen } from '@testing-library/react';
import { router } from '@inertiajs/react';
import type { AnchorHTMLAttributes } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import General from '../pages/Admin/Settings/General';
import type { SharedProps } from '../types/page';
import { english } from './translations';
import { choose } from './select';

const shared = vi.hoisted(() => ({ props: {} as SharedProps }));
vi.mock('@inertiajs/react', async (original) => ({
  ...(await original<typeof import('@inertiajs/react')>()),
  usePage: () => ({ props: shared.props, url: '/admin/settings/general' }),
  Head: () => null,
  Link: ({ children, ...props }: AnchorHTMLAttributes<HTMLAnchorElement>) => (
    <a {...props}>{children}</a>
  ),
}));
const strings = {
  'checkout.settings_title': 'checkout.settings_title',
  'checkout.tab_general': 'General options',
  'checkout.tab_greek': 'Greek companies',
  'checkout.tab_international': 'Other countries',
  'checkout.tab_countries': 'Supported countries',
  'checkout.country_toggle': 'Allow invoice requests for :country',
  'checkout.countries_select_covered': 'Select covered countries',
  'checkout.provider': 'Validation method',
  'checkout.provider_vies': 'Free VIES',
  'checkout.provider_aade': 'AADE',
  'checkout.provider_local': 'AFM checksum only',
  'checkout.provider_vatsense': 'VAT Sense',
  'checkout.vies_help': 'Free EU check, no key',
  'checkout.local_help': 'Does not confirm an active company',
  'checkout.enabled': 'checkout.enabled',
  'checkout.aade_username': 'checkout.aade_username',
  'checkout.aade_password': 'checkout.aade_password',
  'checkout.vatsense_key': 'checkout.vatsense_key',
  'settings.brand_landing_pages': 'Use brands for landing pages',
  'settings.landing_source_title': 'Change the landing-page source?',
  'settings.landing_source_confirm': 'I understand — change source',
  'settings.title': 'General settings',
  'settings.show_back_to_top': 'Show Back to top button',
  'settings.site_max_width': 'Maximum site width (px)',
  'settings.bestsellers_days': 'Sales period (days)',
  'settings.bestsellers_min_units': 'Minimum units sold',
  'settings.bestsellers_min_orders': 'Minimum paid orders',
  'settings.new_products_days': 'New products period (days)',
  'settings.description': 'Installation-wide defaults',
  'settings.section_inventory': 'Inventory',
  'settings.out_of_stock_action': 'When out of stock',
  'settings.out_of_stock_N': 'Do not allow purchase',
  'settings.out_of_stock_B': 'Allow backorders',
  'settings.out_of_stock_help': 'Default for every product',
  'settings.out_of_stock_override_note': 'A product can override this',
  'settings.overrides_zero': 'No product overrides this setting.',
  'settings.overrides_one': ':count product overrides this setting.',
  'settings.overrides_other': ':count products override this setting.',
  'settings.view_overrides': 'View these products',
  'settings.storefront_note': 'No storefront yet',
  'settings.save': 'Save settings',
  'settings.section_taxes': 'Taxes',
  'settings.tab_basic': 'Basic settings',
  'settings.tab_appearance': 'Appearance & calendar',
  'settings.tab_catalog': 'Catalog',
  'settings.tab_promotions': 'Coupons',
  'settings.storefront_open': 'Open storefront',
  'settings.storefront_noindex':
    'Discourage search engine indexing (development)',
  'settings.closed_message': 'Closed-store message',
  'settings.phone_validation': 'Accepted phone numbers',
  'settings.phone_digits': 'Digits only',
  'settings.notification_duration': 'Notification display time (seconds)',
  'settings.tab_inventory': 'Inventory tab',
  'settings.tab_taxes': 'Tax calculation tab',
  'settings.tab_emails': 'Emails tab',
  'ai.settings': 'AI settings',
  'settings.taxes_help': 'Tax behavior',
  'settings.tax_calculation_method': 'Tax calculation method',
  'settings.tax_method_unit_price': 'Unit price',
  'settings.tax_method_subtotal': 'Subtotal',
  'settings.tax_method_help': 'Method help',
  'settings.display_catalog_with_tax': 'Show catalog prices with tax',
  'settings.display_catalog_with_tax_help': 'catalog help',
  'settings.display_cart_with_tax': 'Show cart prices with tax',
  'settings.display_cart_with_tax_help': 'cart help',
  'settings.tax_estimate_without_address': 'Estimate taxes without an address',
  'settings.tax_estimate_without_address_help': 'estimate help',
  'settings.tax_default_country': 'Default country',
  'settings.tax_default_region': 'Default region code',
  'settings.tax_default_none': 'None',
};
const shell = { navigation: [], breadcrumbs: [] };
const baseSettings = {
  tax_calculation_method: 'unit_price',
  display_catalog_prices_with_tax: false,
  display_cart_prices_with_tax: false,
  tax_estimate_without_address: false,
  tax_default_country: null,
  tax_default_region: null,
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
    capabilities: {
      'settings.manage': true,
      'settings.brand-landing-pages': true,
    },
    i18n: {
      locale: 'en',
      direction: 'ltr',
      strings: { ...english, ...strings },
      locales: [{ code: 'en', name: 'English' }],
    },
    links: { logout: '/logout' } as SharedProps['links'],
  };
});

it('submits the chosen default with the version it was loaded at', () => {
  const request = vi.spyOn(router, 'put').mockImplementation(() => undefined);
  render(
    <General
      {...shell}
      settings={{ ...baseSettings, out_of_stock_action: 'N', version: 3 }}
      overrides={0}
    />,
  );
  fireEvent.click(screen.getByRole('tab', { name: 'Inventory tab' }));
  expect(
    screen.getByRole('combobox', { name: 'When out of stock' }),
  ).toHaveTextContent('Do not allow purchase');
  expect(screen.getByText('No product overrides this setting.')).toBeVisible();
  expect(
    screen.queryByRole('link', { name: 'View these products' }),
  ).not.toBeInTheDocument();
  choose(
    screen.getByRole('combobox', { name: 'When out of stock' }),
    'Allow backorders',
  );
  fireEvent.click(screen.getByRole('button', { name: 'Save settings' }));
  expect(request.mock.calls[0][0]).toBe('/admin/settings/general');
  expect(request.mock.calls[0][1]).toMatchObject({
    version: 3,
    out_of_stock_action: 'B',
    tax_calculation_method: 'unit_price',
  });
});

it('reports how many products override the setting and links to them', () => {
  render(
    <General
      {...shell}
      settings={{ ...baseSettings, out_of_stock_action: 'B', version: 1 }}
      overrides={2}
    />,
  );
  fireEvent.click(screen.getByRole('tab', { name: 'Inventory tab' }));
  expect(screen.getByText(/2 products override this setting\./)).toBeVisible();
  expect(
    screen.getByRole('link', { name: 'View these products' }),
  ).toHaveAttribute('href', '/admin/products?stock_action=custom');
});

it('saves tax calculation and display settings with a default estimation address', () => {
  const request = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  render(
    <General
      {...shell}
      settings={{ ...baseSettings, out_of_stock_action: 'N', version: 2 }}
      overrides={0}
      countries={[
        { code: 'GR', name: 'Greece' },
        { code: 'FR', name: 'France' },
      ]}
    />,
  );
  // Only the open tab's fields are shown; drafts survive switching tabs.
  expect(
    screen.queryByRole('combobox', { name: 'Tax calculation method' }),
  ).toBeNull();
  fireEvent.click(screen.getByRole('tab', { name: 'Tax calculation tab' }));
  choose(
    screen.getByRole('combobox', { name: 'Tax calculation method' }),
    'Subtotal',
  );
  expect(screen.queryByLabelText(/Show catalog prices with tax/)).toBeNull();
  fireEvent.click(screen.getByLabelText(/Show cart prices with tax/));
  fireEvent.click(screen.getByLabelText(/Estimate taxes without an address/));
  fireEvent.click(screen.getByRole('combobox', { name: 'Default country' }));
  fireEvent.change(screen.getByRole('combobox', { name: 'Search options' }), {
    target: { value: 'gr' },
  });
  expect(
    screen.queryByRole('option', { name: 'France (FR)' }),
  ).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('option', { name: 'Greece (GR)' }));
  fireEvent.change(screen.getByLabelText('Default region code'), {
    target: { value: 'att' },
  });
  // Calculation, display and the default address share one tab.
  expect(screen.queryByRole('tab', { name: /address/i })).toBeNull();
  fireEvent.click(screen.getByRole('tab', { name: 'Inventory tab' }));
  fireEvent.click(screen.getByRole('tab', { name: 'Tax calculation tab' }));
  expect(
    screen.getByRole('combobox', { name: 'Tax calculation method' }),
  ).toHaveTextContent('Subtotal');
  fireEvent.click(screen.getByRole('button', { name: 'Save settings' }));
  expect(request.mock.calls[0][1]?.data).toMatchObject({
    version: 2,
    tax_calculation_method: 'subtotal',
    display_catalog_prices_with_tax: false,
    display_cart_prices_with_tax: true,
    tax_estimate_without_address: true,
    tax_default_country: 'GR',
    tax_default_region: 'att',
  });
});

it('opens Basic settings first and preserves drafts across the new tabs', () => {
  const request = vi.spyOn(router, 'put').mockImplementation(() => undefined);
  render(
    <General
      {...shell}
      settings={{ ...baseSettings, out_of_stock_action: 'N', version: 1 }}
      overrides={0}
      messageLocales={[
        {
          code: 'en',
          name: 'English',
          defaultMessage: 'Closed for development',
        },
      ]}
    />,
  );
  expect(screen.getAllByRole('tab').map((tab) => tab.textContent)).toEqual([
    'Basic settings',
    'Appearance & calendar',
    'Catalog',
    'Inventory tab',
    'Coupons',
    'Tax calculation tab',
    'Emails tab',
    'AI settings',
  ]);
  expect(screen.getByRole('tab', { name: 'Basic settings' })).toHaveAttribute(
    'aria-selected',
    'true',
  );
  fireEvent.click(screen.getByLabelText('Open storefront'));
  fireEvent.change(screen.getByLabelText(/Closed-store message/), {
    target: { value: 'We are upgrading the store.' },
  });
  fireEvent.click(screen.getByRole('tab', { name: 'Appearance & calendar' }));
  fireEvent.change(
    screen.getByLabelText('Notification display time (seconds)'),
    { target: { value: '0' } },
  );
  fireEvent.click(screen.getByRole('tab', { name: 'Basic settings' }));
  expect(screen.getByLabelText('Open storefront')).not.toBeChecked();
  expect(screen.getByLabelText(/Closed-store message/)).toHaveValue(
    'We are upgrading the store.',
  );
  fireEvent.click(screen.getByRole('button', { name: 'Save settings' }));
  expect(request.mock.calls[0][1]).toMatchObject({
    storefront_open: false,
    closed_messages: { en: 'We are upgrading the store.' },
    notification_duration: 0,
  });
});

it('loads the new version after a save so the next edit can be saved again', () => {
  const request = vi.spyOn(router, 'put').mockImplementation(() => undefined);
  const props = {
    ...shell,
    settings: { ...baseSettings, out_of_stock_action: 'N', version: 1 },
    overrides: 0,
  };
  const view = render(<General {...props} />);
  fireEvent.click(screen.getByLabelText('Open storefront'));
  view.rerender(
    <General
      {...props}
      settings={{ ...props.settings, version: 2, storefront_open: false }}
    />,
  );
  fireEvent.click(screen.getByLabelText('Open storefront'));
  fireEvent.click(screen.getByRole('button', { name: 'Save settings' }));
  expect(request.mock.calls[0][1]).toMatchObject({
    version: 2,
    storefront_open: true,
  });
});

it('edits the storefront maximum width in Appearance and includes it in the settings save', () => {
  const request = vi.spyOn(router, 'put').mockImplementation(() => undefined);
  render(
    <General
      {...shell}
      settings={{ ...baseSettings, out_of_stock_action: 'N', version: 3 }}
      overrides={0}
    />,
  );
  fireEvent.click(screen.getByRole('tab', { name: 'Appearance & calendar' }));
  const width = screen.getByRole('spinbutton', {
    name: 'Maximum site width (px)',
  });
  expect(width).toHaveValue(1320);
  const backToTop = screen.getByRole('checkbox', {
    name: /Show Back to top button/,
  });
  expect(backToTop).toBeChecked();
  fireEvent.click(backToTop);
  fireEvent.change(width, { target: { value: '1600' } });
  fireEvent.click(screen.getByRole('button', { name: 'Save settings' }));
  expect(request.mock.calls[0][1]).toMatchObject({
    site_max_width: 1600,
    show_back_to_top: false,
    version: 3,
  });
});

it('edits the new products period in Catalog and includes it in the settings save', () => {
  const request = vi.spyOn(router, 'put').mockImplementation(() => undefined);
  render(
    <General
      {...shell}
      settings={{ ...baseSettings, out_of_stock_action: 'N', version: 3 }}
      overrides={0}
    />,
  );
  fireEvent.click(screen.getByRole('tab', { name: 'Catalog' }));
  const days = screen.getByRole('spinbutton', {
    name: 'New products period (days)',
  });
  expect(days).toHaveValue(90);
  fireEvent.change(days, { target: { value: '30' } });
  fireEvent.click(screen.getByRole('button', { name: 'Save settings' }));
  expect(request.mock.calls[0][1]).toMatchObject({
    new_products_days: 30,
    version: 3,
  });
});

it('places Disable cart beneath Hide prices and saves the inverse enabled value without changing price visibility', () => {
  const request = vi.spyOn(router, 'put').mockImplementation(() => undefined);
  render(
    <General
      {...shell}
      settings={{
        ...baseSettings,
        out_of_stock_action: 'N',
        version: 3,
        cart_enabled: true,
        hide_product_prices: true,
      }}
      overrides={0}
    />,
  );
  fireEvent.click(screen.getByRole('tab', { name: 'Catalog' }));
  const prices = document.getElementById('hide_product_prices')!;
  const cart = document.getElementById('cart_enabled')!;
  expect(prices).toBeChecked();
  expect(cart).not.toBeChecked();
  expect(prices.closest('div')!.nextElementSibling).toBe(cart.closest('div'));
  fireEvent.click(cart);
  fireEvent.click(screen.getByRole('button', { name: 'Save settings' }));
  expect(request.mock.calls[0][1]).toMatchObject({
    cart_enabled: false,
    hide_product_prices: true,
  });
  fireEvent.click(cart);
  fireEvent.click(screen.getByRole('button', { name: 'Save settings' }));
  expect(request.mock.calls[1][1]).toMatchObject({
    cart_enabled: true,
    hide_product_prices: true,
  });
});

it('requires the SEO acknowledgement before changing landing-page source', () => {
  const request = vi.spyOn(router, 'put').mockImplementation(() => undefined);
  render(
    <General
      {...shell}
      settings={{ ...baseSettings, out_of_stock_action: 'N', version: 1 }}
      overrides={0}
    />,
  );
  fireEvent.click(screen.getByRole('tab', { name: 'Catalog' }));
  const source = screen.getByRole('checkbox', {
    name: /Use brands for landing pages/,
  });
  expect(source).toBeChecked();
  fireEvent.click(source);
  expect(source).toBeChecked();
  expect(
    screen.getByRole('dialog', { name: 'Change the landing-page source?' }),
  ).toBeVisible();
  fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
  expect(source).toBeChecked();
  fireEvent.click(source);
  fireEvent.click(
    screen.getByRole('button', { name: 'I understand — change source' }),
  );
  expect(source).not.toBeChecked();
  fireEvent.click(screen.getByRole('button', { name: 'Save settings' }));
  expect(request.mock.calls[0][1]).toMatchObject({
    brand_landing_pages: false,
    brand_landing_pages_confirmed: true,
  });
});

it('keeps the landing-page source read-only without its separate permission', () => {
  shared.props.capabilities['settings.brand-landing-pages'] = false;
  render(
    <General
      {...shell}
      settings={{ ...baseSettings, out_of_stock_action: 'N', version: 1 }}
      overrides={0}
    />,
  );
  fireEvent.click(screen.getByRole('tab', { name: 'Catalog' }));
  const source = screen.getByRole('checkbox', {
    name: /Use brands for landing pages/,
  });
  expect(source).toBeChecked();
  expect(source).toBeDisabled();
  fireEvent.click(source);
  expect(
    screen.queryByRole('dialog', { name: 'Change the landing-page source?' }),
  ).not.toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Save settings' })).toBeEnabled();
});

it('offers Signature as the third card style and submits it through the existing catalog settings', () => {
  Object.assign(shared.props.i18n.strings, {
    'settings.product_card_style': 'Product card style',
    'settings.product_card_style_classic': 'Classic',
    'settings.product_card_style_showcase': 'Showcase',
    'settings.product_card_style_signature': 'Signature',
  });
  const request = vi.spyOn(router, 'put').mockImplementation(() => undefined);
  render(
    <General
      {...shell}
      settings={{
        ...baseSettings,
        out_of_stock_action: 'N',
        product_card_style: 'classic',
        version: 1,
      }}
      overrides={0}
    />,
  );
  fireEvent.click(screen.getByRole('tab', { name: 'Catalog' }));
  choose(
    screen.getByRole('combobox', { name: 'Product card style' }),
    'Signature',
  );
  fireEvent.click(screen.getByRole('button', { name: 'Save settings' }));
  expect(request.mock.calls[0][1]).toMatchObject({
    product_card_style: 'signature',
  });
});

it('saves bestseller rules with current defaults and preserves them across tabs', () => {
  const request = vi.spyOn(router, 'put').mockImplementation(() => undefined);
  render(
    <General
      {...shell}
      settings={{
        ...baseSettings,
        out_of_stock_action: 'N',
        version: 3,
        bestsellers_excluded_categories: ['12'],
      }}
      options={[{ id: '12', name: 'Shoes' }]}
      overrides={0}
    />,
  );
  fireEvent.click(screen.getByRole('tab', { name: 'Catalog' }));
  const days = screen.getByRole('spinbutton', { name: 'Sales period (days)' });
  expect(days).toHaveValue(0);
  expect(
    screen.getByRole('spinbutton', { name: 'Minimum units sold' }),
  ).toHaveValue(1);
  expect(
    screen.getByRole('spinbutton', { name: 'Minimum paid orders' }),
  ).toHaveValue(1);
  expect(screen.getByText('Shoes')).toBeVisible();
  fireEvent.change(days, { target: { value: '120' } });
  fireEvent.change(
    screen.getByRole('spinbutton', { name: 'Minimum paid orders' }),
    { target: { value: '2' } },
  );
  fireEvent.click(screen.getByRole('tab', { name: 'Appearance & calendar' }));
  fireEvent.click(screen.getByRole('button', { name: 'Save settings' }));
  expect(request.mock.calls[0][1]).toMatchObject({
    bestsellers_days: 120,
    bestsellers_min_units: 1,
    bestsellers_min_orders: 2,
    bestsellers_excluded_categories: ['12'],
  });
});

it('saves the development noindex switch with the existing settings form', () => {
  const request = vi.spyOn(router, 'put').mockImplementation(() => undefined);
  render(
    <General
      {...shell}
      settings={{ ...baseSettings, out_of_stock_action: 'N', version: 1 }}
      overrides={0}
    />,
  );
  const option = screen.getByLabelText(
    'Discourage search engine indexing (development)',
  );
  expect(option).not.toBeChecked();
  fireEvent.click(option);
  fireEvent.click(screen.getByRole('button', { name: 'Save settings' }));
  expect(request.mock.calls[0][1]).toMatchObject({
    storefront_noindex: true,
    version: 1,
  });
});

it('provides a checkout tab with empty write-only credentials even when configured', () => {
  const request = vi.spyOn(router, 'put').mockImplementation(() => undefined);
  render(
    <General
      {...shell}
      settings={{ ...baseSettings, out_of_stock_action: 'N', version: 3 }}
      overrides={0}
      checkoutSettings={{
        version: 2,
        enabled: true,
        aade_configured: true,
        vatsense_configured: true,
        greek_provider: 'aade',
        international_provider: 'vatsense',
        country_codes: ['GR'],
      }}
    />,
  );
  fireEvent.click(screen.getByRole('tab', { name: 'checkout.settings_title' }));
  expect(screen.queryByLabelText('checkout.aade_username')).toBeNull();
  expect(screen.queryByLabelText('checkout.vatsense_key')).toBeNull();
  fireEvent.click(screen.getByRole('tab', { name: 'Greek companies' }));
  for (const field of ['aade_username', 'aade_password']) {
    const input = screen.getByLabelText('checkout.' + field);
    expect(input).toHaveAttribute('type', 'password');
    expect(input).toHaveValue('');
  }
  expect(screen.queryByLabelText('checkout.vatsense_key')).toBeNull();
  choose(
    screen.getByRole('combobox', { name: 'Validation method' }),
    'AFM checksum only',
  );
  expect(screen.queryByLabelText('checkout.aade_username')).toBeNull();
  expect(screen.getByText('Does not confirm an active company')).toBeTruthy();
  fireEvent.click(screen.getByRole('tab', { name: 'Other countries' }));
  expect(screen.getByLabelText('checkout.vatsense_key')).toHaveValue('');
  fireEvent.change(screen.getByLabelText('checkout.vatsense_key'), {
    target: { value: 'new-test-key' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Save settings' }));
  expect(request.mock.calls[0][1]).toMatchObject({
    checkout_settings: {
      version: 2,
      enabled: true,
      aade_username: '',
      aade_password: '',
      vatsense_key: 'new-test-key',
    },
  });
});

it('preselects saved invoice countries and preserves edits through search and tab changes', () => {
  const request = vi.spyOn(router, 'put').mockImplementation(() => undefined);
  render(
    <General
      {...shell}
      settings={{ ...baseSettings, out_of_stock_action: 'N', version: 3 }}
      overrides={0}
      checkoutSettings={{
        version: 2,
        enabled: true,
        aade_configured: false,
        vatsense_configured: false,
        greek_provider: 'vies',
        international_provider: 'vies',
        country_codes: ['GR', 'US'],
      }}
      invoiceCountryCoverage={{ vies: ['GR', 'DE'] }}
      countries={[
        { code: 'GR', name: 'Greece' },
        { code: 'DE', name: 'Germany' },
        { code: 'US', name: 'United States' },
      ]}
    />,
  );
  fireEvent.click(screen.getByRole('tab', { name: 'checkout.settings_title' }));
  fireEvent.click(screen.getByRole('tab', { name: 'Supported countries' }));
  expect(
    screen.getByRole('switch', { name: 'Allow invoice requests for Greece' }),
  ).toBeChecked();
  expect(
    screen.getByRole('switch', {
      name: 'Allow invoice requests for United States',
    }),
  ).toBeChecked();
  fireEvent.change(screen.getByRole('searchbox', { name: 'Search' }), {
    target: { value: 'DE' },
  });
  expect(
    screen.queryByRole('switch', { name: 'Allow invoice requests for Greece' }),
  ).toBeNull();
  fireEvent.click(
    screen.getByRole('switch', { name: 'Allow invoice requests for Germany' }),
  );
  fireEvent.click(screen.getByRole('tab', { name: 'Greek companies' }));
  fireEvent.click(screen.getByRole('tab', { name: 'Supported countries' }));
  expect(
    screen.getByRole('switch', { name: 'Allow invoice requests for Germany' }),
  ).toBeChecked();
  expect(
    screen.getByRole('switch', { name: 'Allow invoice requests for Greece' }),
  ).toBeChecked();
  fireEvent.click(screen.getByRole('button', { name: 'Clear selection' }));
  expect(
    screen.getByRole('switch', { name: 'Allow invoice requests for Greece' }),
  ).not.toBeChecked();
  fireEvent.click(
    screen.getByRole('button', { name: 'Select covered countries' }),
  );
  expect(
    screen.getByRole('switch', {
      name: 'Allow invoice requests for United States',
    }),
  ).not.toBeChecked();
  fireEvent.click(
    screen.getByRole('switch', { name: 'Allow invoice requests for Greece' }),
  );
  fireEvent.click(screen.getByRole('button', { name: 'Save settings' }));
  expect(request.mock.calls[0][1]).toMatchObject({
    checkout_settings: { version: 2, country_codes: ['DE'] },
  });
});
