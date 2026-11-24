import {
  cleanup,
  fireEvent,
  render,
  screen,
  within,
} from '@testing-library/react';
import { router } from '@inertiajs/react';
import type { AnchorHTMLAttributes } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import Company from '../pages/Admin/Settings/Company';
import type { CompanyLink } from '../pages/Admin/Settings/CompanyLinks';
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
  'company.title': 'Company data',
  'company.description': 'All fields optional',
  'company.company': 'Company',
  'company.address_tab': 'Address',
  'company.contacts': 'Contact emails',
  'company.name': 'Company name',
  'company.address': 'Street address',
  'company.city': 'City',
  'company.country_code': 'Country',
  'company.region': 'Region / State',
  'company.postal_code': 'Postal code',
  'company.phone': 'Company phone',
  'company.mobile': 'Company mobile',
  'company.website': 'Company website',
  'company.opening_year': 'Online store opening year',
  'company.email_customers': 'Customer enquiries email',
  'company.email_admin': 'Store administrator email',
  'company.email_orders': 'Order enquiries email',
  'company.email_support': 'Help and support email',
  'company.email_newsletter': 'Newsletter reply email',
  'company.none': 'Not set',
  'media.delete_named': 'Delete :name',
  'media.current': 'Current image',
  'media.delete': 'Delete',
  'media.confirm_delete': 'Delete this image?',
  'company.logo': 'Logo',
  'company.social': 'Social & apps',
  'company.social_icons_title': 'Social media',
  'company.social_apps_title': 'Mobile apps',
  'company.social_icon_restore': 'Use default icon',
  'company.social_icon_image': 'Icon',
  'company.link_add': 'Add link',
  'company.link_new': 'New link',
  'company.link_preset': 'Network',
  'company.link_custom': 'Other (custom)',
  'company.link_name': 'Name',
  'company.link_url': 'Address',
  'company.link_save': 'Save link',
  'company.link_remove': 'Remove this link?',
  'common.edit': 'Edit',
  'company.payments': 'Payment icons',
  'company.payment_images_title': 'Payment method images',
  'company.payment_images_help': 'Drag to reorder the footer images.',
  'company.payment_image_add': 'Add image',
  'company.payment_image_new': 'New image',
  'company.payment_image_name': 'Name',
  'company.payment_image_name_hint': 'Alt text',
  'company.payment_icon_file': 'Picture',
  'company.payment_image_add_list': 'Add to list',
  'company.payment_image_move': 'Move :name (arrow keys)',
  'company.payment_image_remove': 'Remove :name',
  'company.payment_icon_remove': 'Remove this payment icon?',
  'company.payment_footer_preview': 'Footer preview',
  'company.logo_description': 'Logo help',
  'company.logo_desktop': 'Store logo',
  'company.logo_desktop_help': 'Used on all devices',
  'company.logo_email': 'Email logo (optional)',
  'company.logo_email_help': 'Used in all emails, falls back to the store logo',
  'company.logo_mobile': 'Mobile logo (optional)',
  'company.logo_mobile_help': 'Used on phones',
  'company.favicon': 'Favicon (browser tab icon)',
  'company.favicon_help': 'Browser tab icon',
};
const shell = { navigation: [], breadcrumbs: [] };
const settings = {
  version: 4,
  name: null,
  phone: null,
  mobile: null,
  website: null,
  opening_year: null,
  address: null,
  city: null,
  country_code: null,
  region: null,
  postal_code: null,
  legal_name: null,
  business_activity: null,
  tax_id: null,
  tax_office: null,
  gemi_number: null,
  email_customers: null,
  email_admin: null,
  email_orders: null,
  email_support: null,
  email_newsletter: null,
  logo: null,
  logo_mobile: null,
  logo_email: null,
  favicon: null,
  payment_icons: [],
  links: [] as CompanyLink[],
};
const countries = [
  { code: 'GR', name: 'Greece' },
  { code: 'US', name: 'United States' },
];
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
    capabilities: { 'settings.manage': true },
    i18n: {
      locale: 'en',
      direction: 'ltr',
      strings: { ...english, ...strings },
      locales: [{ code: 'en', name: 'English' }],
    },
    links: { logout: '/logout' } as SharedProps['links'],
  };
});

it('preserves all company tab drafts and submits the loaded version with optional values', () => {
  const request = vi.spyOn(router, 'put').mockImplementation(() => undefined);
  render(<Company {...shell} settings={settings} countries={countries} />);
  fireEvent.change(screen.getByLabelText('Company name'), {
    target: { value: 'Demo Company' },
  });
  expect(screen.getByLabelText('Company name')).not.toBeRequired();
  fireEvent.click(screen.getByRole('tab', { name: 'Address' }));
  fireEvent.click(screen.getByRole('combobox', { name: 'Country' }));
  const search = screen.getByRole('combobox', { name: 'Search options' });
  fireEvent.change(search, { target: { value: 'gr' } });
  expect(
    screen.queryByRole('option', { name: 'United States' }),
  ).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('option', { name: 'Greece' }));
  fireEvent.change(screen.getByLabelText('Postal code'), {
    target: { value: '00123' },
  });
  fireEvent.click(screen.getByRole('tab', { name: 'Contact emails' }));
  fireEvent.change(screen.getByLabelText('Order enquiries email'), {
    target: { value: 'orders@example.com' },
  });
  fireEvent.click(screen.getByRole('tab', { name: 'Company' }));
  expect(screen.getByLabelText('Company name')).toHaveValue('Demo Company');
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
  expect(request.mock.calls[0][0]).toBe('/admin/settings/company');
  expect(request.mock.calls[0][1]).toMatchObject({
    version: 4,
    name: 'Demo Company',
    country_code: 'GR',
    postal_code: '00123',
    email_orders: 'orders@example.com',
    email_newsletter: '',
  });
});

it('supports keyboard tab navigation and clearing a saved optional contact', () => {
  const request = vi.spyOn(router, 'put').mockImplementation(() => undefined);
  render(
    <Company
      {...shell}
      settings={{
        ...settings,
        name: 'Saved company',
        email_orders: 'old@example.com',
      }}
      countries={countries}
    />,
  );
  fireEvent.keyDown(screen.getByRole('tab', { name: 'Company' }), {
    key: 'End',
  });
  expect(screen.getByRole('tab', { name: 'Payment icons' })).toHaveFocus();
  fireEvent.click(screen.getByRole('tab', { name: 'Contact emails' }));
  expect(screen.getByLabelText('Order enquiries email')).toHaveValue(
    'old@example.com',
  );
  fireEvent.change(screen.getByLabelText('Order enquiries email'), {
    target: { value: '' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
  expect(request.mock.calls[0][1]).toMatchObject({
    name: 'Saved company',
    email_orders: '',
  });
});

it('uploads the store logo and an optional mobile logo through the logo tab', () => {
  const post = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  render(<Company {...shell} settings={settings} countries={countries} />);
  fireEvent.click(screen.getByRole('tab', { name: 'Logo' }));
  expect(screen.getByText('Store logo')).toBeVisible();
  expect(screen.getByText('Mobile logo (optional)')).toBeVisible();
  const files =
    document.querySelectorAll<HTMLInputElement>('input[type="file"]');
  expect(screen.getByText('Favicon (browser tab icon)')).toBeVisible();
  expect(screen.getByText('Email logo (optional)')).toBeVisible();
  expect(files).toHaveLength(4);
  const png = new File(['x'], 'logo.png', { type: 'image/png' });
  const small = new File(['y'], 'mobile.png', { type: 'image/png' });
  const icon = new File(['z'], 'icon.png', { type: 'image/png' });
  const email = new File(['mail'], 'email.png', { type: 'image/png' });
  fireEvent.change(files[0], { target: { files: [png] } });
  fireEvent.change(files[1], { target: { files: [small] } });
  fireEvent.change(files[2], { target: { files: [icon] } });
  fireEvent.change(files[3], { target: { files: [email] } });
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
  expect(post.mock.calls[0][0]).toBe('/admin/settings/company/logos');
  expect(post.mock.calls[0][1]).toMatchObject({
    method: 'post',
    forceFormData: true,
  });
  const data = post.mock.calls[0][1]?.data as Record<string, unknown>;
  expect(data.version).toBe(4);
  expect((data.logo as File).name).toBe('logo.png');
  expect((data.logo_mobile as File).name).toBe('mobile.png');
  expect((data.favicon as File).name).toBe('icon.png');
  expect((data.logo_email as File).name).toBe('email.png');
});

it('shows stored logos and can remove only the mobile one', () => {
  const post = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  const logo = {
    url: '/admin/settings/company/logo/desktop?v=1',
    meta: { size: 2048, width: 300, height: 80 },
  };
  render(
    <Company
      {...shell}
      settings={{
        ...settings,
        logo,
        logo_mobile: {
          ...logo,
          url: '/admin/settings/company/logo/mobile?v=2',
        },
      }}
      countries={countries}
    />,
  );
  fireEvent.click(screen.getByRole('tab', { name: 'Logo' }));
  const images = within(screen.getByRole('main')).getAllByRole('img');
  expect(images.map((i) => i.getAttribute('src'))).toEqual([
    logo.url,
    '/admin/settings/company/logo/mobile?v=2',
  ]);
  // Delete asks for confirmation; only the mobile logo is removed here.
  const deletes = screen.getAllByRole('button', {
    name: /^Delete Current image/,
  });
  expect(deletes).toHaveLength(2);
  fireEvent.click(deletes[1]);
  fireEvent.click(screen.getByRole('button', { name: 'Delete' }));
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
  const data = post.mock.calls[0][1]?.data as Record<string, unknown>;
  expect(post.mock.calls[0][0]).toBe('/admin/settings/company/logos');
  expect(data).toMatchObject({ remove_logo: false, remove_logo_mobile: true });
});

it('can remove the email logo without changing the store logo', () => {
  const post = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  const logo = {
    url: '/admin/settings/company/logo/desktop?v=1',
    meta: { size: 2048, width: 300, height: 80 },
  };
  render(
    <Company
      {...shell}
      settings={{
        ...settings,
        logo,
        logo_email: { ...logo, url: '/admin/settings/company/logo/email?v=2' },
      }}
      countries={countries}
    />,
  );
  fireEvent.click(screen.getByRole('tab', { name: 'Logo' }));
  const deletes = screen.getAllByRole('button', {
    name: /^Delete Current image/,
  });
  fireEvent.click(deletes[1]);
  fireEvent.click(screen.getByRole('button', { name: 'Delete' }));
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
  const data = post.mock.calls[0][1]?.data as Record<string, unknown>;
  expect(data).toMatchObject({ remove_logo: false, remove_logo_email: true });
});

it('previews a new payment image and uploads from the unchanged top Save button', () => {
  const post = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  render(<Company {...shell} settings={settings} countries={countries} />);
  fireEvent.click(screen.getByRole('tab', { name: 'Payment icons' }));
  fireEvent.click(screen.getByRole('button', { name: 'Add image' }));
  const image = new File(['png'], 'bank-transfer.png', { type: 'image/png' });
  fireEvent.change(screen.getByLabelText('Picture', { selector: 'input' }), {
    target: { files: [image] },
  });
  fireEvent.change(screen.getByLabelText('Name'), {
    target: { value: 'Bank transfer' },
  });
  expect(screen.getByText('Footer preview')).toBeVisible();
  expect(
    within(screen.getByRole('main')).getAllByAltText('Bank transfer'),
  ).toHaveLength(2);
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
  expect(post.mock.calls[0][0]).toBe('/admin/settings/company/payment-icons');
  expect(post.mock.calls[0][1]).toMatchObject({
    forceFormData: true,
    data: { icon: image, name: 'Bank transfer' },
  });
});

it('reorders payment images with arrow keys and confirms removal in the shared dialog', () => {
  const post = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  const icons = ['Visa', 'Mastercard'].map((name, index) => ({
    id: String(index + 1),
    name,
    url: '/payment-' + index + '.png',
    meta: { size: 1024, width: 100, height: 60 },
  }));
  const view = render(
    <Company
      {...shell}
      settings={{ ...settings, payment_icons: icons }}
      countries={countries}
    />,
  );
  fireEvent.click(screen.getByRole('tab', { name: 'Payment icons' }));
  fireEvent.keyDown(
    screen.getByRole('button', { name: 'Move Visa (arrow keys)' }),
    { key: 'ArrowRight' },
  );
  expect(post.mock.calls[0][0]).toBe(
    '/admin/settings/company/payment-icons/order',
  );
  expect(post.mock.calls[0][1]).toMatchObject({ data: { ids: ['2', '1'] } });
  view.unmount();
  post.mockClear();
  render(
    <Company
      {...shell}
      settings={{ ...settings, payment_icons: icons }}
      countries={countries}
    />,
  );
  fireEvent.click(screen.getByRole('tab', { name: 'Payment icons' }));
  fireEvent.click(screen.getByRole('button', { name: 'Add image' }));
  fireEvent.change(screen.getByLabelText('Picture', { selector: 'input' }), {
    target: { files: [new File(['png'], 'new.png', { type: 'image/png' })] },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Remove Visa' }));
  expect(post).not.toHaveBeenCalled();
  fireEvent.click(
    within(screen.getByRole('alertdialog')).getByRole('button', {
      name: 'Delete',
    }),
  );
  expect(post.mock.calls[0][0]).toBe('/admin/settings/company/payment-icons/1');
  expect(post.mock.calls[0][1]).toMatchObject({ method: 'delete' });
  expect(post).toHaveBeenCalledTimes(1);
});

it('lists social and app links without a footer preview and adds one in its own request', () => {
  const post = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  const links: CompanyLink[] = [
    {
      id: '1',
      kind: 'social',
      preset: 'facebook',
      name: 'Facebook',
      url: 'https://facebook.com/acme',
      image: null,
    },
    {
      id: '2',
      kind: 'app',
      preset: 'app_store',
      name: 'App Store',
      url: 'https://apps.apple.com/acme',
      image: '/badge.png',
    },
  ];
  render(
    <Company
      {...shell}
      settings={{ ...settings, links }}
      countries={countries}
    />,
  );
  fireEvent.click(screen.getByRole('tab', { name: 'Social & apps' }));
  expect(screen.queryByText('Footer preview')).not.toBeInTheDocument();
  const social = screen.getByRole('list', { name: 'Social media' });
  expect(within(social).getByText('facebook.com')).toBeVisible();
  expect(
    within(screen.getByRole('list', { name: 'Mobile apps' })).getByText(
      'App Store',
    ),
  ).toBeVisible();
  // Footer links save themselves; the page Save button does nothing here.
  expect(screen.getByRole('button', { name: 'Save changes' })).toBeDisabled();
  fireEvent.click(within(social).getByRole('button', { name: 'Add link' }));
  fireEvent.change(screen.getByLabelText('Address'), {
    target: { value: 'https://instagram.com/acme' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Save link' }));
  expect(post.mock.calls[0][0]).toBe('/admin/settings/company/links');
  expect(post.mock.calls[0][1]).toMatchObject({
    method: 'post',
    forceFormData: true,
    data: {
      kind: 'social',
      preset: 'facebook',
      url: 'https://instagram.com/acme',
      remove_image: 0,
    },
  });
});

it('edits a link, restores its built-in icon and reorders with the arrow keys', () => {
  const post = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  const links: CompanyLink[] = [
    {
      id: '1',
      kind: 'social',
      preset: 'facebook',
      name: 'Facebook',
      url: 'https://facebook.com/acme',
      image: '/fb.png',
    },
    {
      id: '3',
      kind: 'social',
      preset: null,
      name: 'Threads',
      url: 'https://threads.net/acme',
      image: '/threads.png',
    },
  ];
  render(
    <Company
      {...shell}
      settings={{ ...settings, links }}
      countries={countries}
    />,
  );
  fireEvent.click(screen.getByRole('tab', { name: 'Social & apps' }));
  fireEvent.click(screen.getByRole('button', { name: 'Edit Facebook' }));
  expect(screen.getByLabelText('Address')).toHaveValue(
    'https://facebook.com/acme',
  );
  fireEvent.click(screen.getByRole('button', { name: 'Use default icon' }));
  fireEvent.click(screen.getByRole('button', { name: 'Save link' }));
  expect(post.mock.calls[0][0]).toBe('/admin/settings/company/links/1');
  expect(post.mock.calls[0][1]).toMatchObject({
    data: { preset: 'facebook', remove_image: 1 },
  });
  expect(post.mock.calls[0][1]?.data).not.toHaveProperty('kind');
  cleanup();
  post.mockClear();
  render(
    <Company
      {...shell}
      settings={{ ...settings, links }}
      countries={countries}
    />,
  );
  fireEvent.click(screen.getByRole('tab', { name: 'Social & apps' }));
  fireEvent.keyDown(
    screen.getByRole('button', { name: 'Move Facebook (arrow keys)' }),
    { key: 'ArrowRight' },
  );
  expect(post.mock.calls[0][0]).toBe('/admin/settings/company/links/order');
  expect(post.mock.calls[0][1]).toMatchObject({
    data: { kind: 'social', ids: ['3', '1'] },
  });
});
