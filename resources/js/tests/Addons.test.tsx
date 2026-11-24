import { fireEvent, render, screen, within } from '@testing-library/react';
import { router } from '@inertiajs/react';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { expect, it, vi } from 'vitest';
import AddonsIndex from '../pages/Admin/Addons/Index';
import type { AddonRow } from '../types/payments';

const labels: Record<string, string> = {
  'addons.title': 'Add-ons',
  'addons.upload_title': 'Upload an add-on',
  'addons.upload_file': 'ZIP package',
  'addons.settings': 'Settings',
  'addons.disable': 'Disable',
  'addons.enable': 'Enable',
  'addons.status_enabled': 'Enabled',
  'common.close': 'Close',
  'addons.upload_button': 'Upload and install',
  'addons.upload_not_zip': 'Choose a .zip file.',
  'addons.upload_ready': 'Ready to install',
  'addons.upload_remove': 'Remove file',
};
vi.mock('@inertiajs/react', async (original) => ({
  ...(await original<typeof import('@inertiajs/react')>()),
  usePage: () => ({
    props: { i18n: { locale: 'en', strings: labels }, errors: {} },
  }),
  Link: ({ children, ...props }: AnchorHTMLAttributes<HTMLAnchorElement>) => (
    <a {...props}>{children}</a>
  ),
}));

vi.mock('../layouts/admin/AdminLayout', () => ({
  default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));

const paypal: AddonRow = {
  name: 'ambresoft/paypal',
  title: 'PayPal',
  description: '',
  author: 'CMS',
  version: '1.0.0',
  installed_version: '1.0.0',
  source: 'bundled',
  status: 'enabled',
  compatible: true,
  cms: '^1.0',
  requires: [],
  contributes: { 'payment-methods': ['paypal'] },
  error: null,
  upgrade_available: false,
  settings: [{ name: 'PayPal', href: '/admin/payment-methods/paypal/edit' }],
  actions: {
    install: false,
    enable: false,
    disable: true,
    upgrade: false,
    uninstall: false,
  },
};

it('opens the upload from the + button and links an add-on to its settings', () => {
  vi.spyOn(HTMLDialogElement.prototype, 'showModal').mockImplementation(
    function (this: HTMLDialogElement) {
      this.setAttribute('open', '');
    },
  );
  const post = vi.spyOn(router, 'post').mockImplementation(() => {});
  render(
    <AddonsIndex
      navigation={[]}
      breadcrumbs={[]}
      addons={[paypal]}
      cmsVersion="1.0.0"
      uploads={{ enabled: true, allowed: true, maxMb: 20 }}
    />,
  );
  const row = screen.getByText('PayPal').closest('tr')!;
  expect(within(row).getByRole('link', { name: 'Settings' })).toHaveAttribute(
    'href',
    '/admin/payment-methods/paypal/edit',
  );
  expect(screen.queryByLabelText('ZIP package')).not.toBeVisible();
  fireEvent.click(screen.getByRole('button', { name: 'Upload an add-on' }));
  expect(screen.getByLabelText('ZIP package')).toBeVisible();
  fireEvent.click(within(row).getByRole('switch', { name: 'Disable: PayPal' }));
  expect(post).toHaveBeenCalledWith(
    '/admin/addons/ambresoft/paypal/disable',
    {},
    expect.anything(),
  );
});

it('hides the upload button for staff without the upload capability', () => {
  render(
    <AddonsIndex
      navigation={[]}
      breadcrumbs={[]}
      addons={[{ ...paypal, settings: [] }]}
      cmsVersion="1.0.0"
      uploads={{ enabled: true, allowed: false, maxMb: 20 }}
    />,
  );
  expect(
    screen.queryByRole('button', { name: 'Upload an add-on' }),
  ).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: 'Settings' })).toBeNull();
});

it('shows the add-on icon, or the default puzzle icon when it declares none', () => {
  const { container } = render(
    <AddonsIndex
      navigation={[]}
      breadcrumbs={[]}
      cmsVersion="1.0.0"
      uploads={{ enabled: false, allowed: false, maxMb: 20 }}
      addons={[
        {
          ...paypal,
          icon_url: '/admin/addons/ambresoft/paypal/icon?v=1.0.0',
        },
        { ...paypal, name: 'acme/plain', title: 'Plain', icon_url: null },
      ]}
    />,
  );
  const images = container.querySelectorAll('img');
  expect(images).toHaveLength(1);
  expect(images[0].getAttribute('src')).toBe(
    '/admin/addons/ambresoft/paypal/icon?v=1.0.0',
  );
  // The icon-less add-on falls back to the inline puzzle icon.
  expect(container.querySelectorAll('svg path').length).toBeGreaterThan(0);
});

it('picks a ZIP in the upload dialog, refuses other files and enables the install button', () => {
  vi.spyOn(HTMLDialogElement.prototype, 'showModal').mockImplementation(
    function (this: HTMLDialogElement) {
      this.setAttribute('open', '');
    },
  );
  render(
    <AddonsIndex
      navigation={[]}
      breadcrumbs={[]}
      addons={[paypal]}
      cmsVersion="1.0.0"
      uploads={{ enabled: true, allowed: true, maxMb: 20 }}
    />,
  );
  fireEvent.click(screen.getByRole('button', { name: 'Upload an add-on' }));
  const input = screen.getByLabelText('ZIP package');
  const install = screen.getByRole('button', { name: 'Upload and install' });
  expect(install).toBeDisabled();

  fireEvent.change(input, {
    target: { files: [new File(['x'], 'notes.txt', { type: 'text/plain' })] },
  });
  expect(screen.getByRole('alert')).toHaveTextContent('Choose a .zip file.');
  expect(install).toBeDisabled();

  fireEvent.change(input, {
    target: {
      files: [new File(['x'], 'acme-1.0.0.zip', { type: 'application/zip' })],
    },
  });
  expect(screen.getByText('acme-1.0.0.zip')).toBeInTheDocument();
  expect(screen.getByText(/Ready to install/)).toBeInTheDocument();
  expect(install).toBeEnabled();
  fireEvent.click(screen.getByRole('button', { name: 'Remove file' }));
  expect(screen.queryByText('acme-1.0.0.zip')).not.toBeInTheDocument();
  expect(install).toBeDisabled();
});
