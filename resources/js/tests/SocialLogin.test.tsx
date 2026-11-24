import { fireEvent, render, screen } from '@testing-library/react';
import { router } from '@inertiajs/react';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { expect, it, vi } from 'vitest';
import SocialLoginForm from '../pages/Admin/SocialLogin/Form';
import SocialLoginIndex from '../pages/Admin/SocialLogin/Index';
import type {
  SocialLoginCatalogEntry,
  SocialLoginEditor,
} from '../types/socialLogin';

const labels: Record<string, string> = {
  'social_login.title': 'Social login',
  'social_login.create': 'New provider',
  'social_login.edit': 'Edit provider',
  'social_login.tab_general': 'General',
  'social_login.tab_callback': 'Callback URL',
  'social_login.callback_url': 'Callback URL',
  'social_login.provider': 'Provider',
  'social_login.name': 'Name',
  'social_login.active': 'Active',
  'social_login.disabled': 'Disabled',
  'social_login.incomplete': 'Credentials missing',
  'social_login.status_label': 'Status of :name',
  'common.status': 'Status',
  'common.name': 'Name',
  'common.actions': 'Actions',
  'common.save': 'Save changes',
  'common.edit': 'Edit',
  'shipping.secret_set': 'A value is stored. Leave empty to keep it.',
  'shipping.secret_clear': 'Remove the stored value',
};
vi.mock('@inertiajs/react', async (original) => ({
  ...(await original<typeof import('@inertiajs/react')>()),
  usePage: () => ({
    props: {
      i18n: { locale: 'en', strings: labels, locales: [] },
      capabilities: { 'social-login.manage': true },
      errors: {},
    },
  }),
  Link: ({ children, ...props }: AnchorHTMLAttributes<HTMLAnchorElement>) => (
    <a {...props}>{children}</a>
  ),
}));
vi.mock('../layouts/admin/AdminLayout', () => ({
  default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));

const fields = (set: boolean): SocialLoginCatalogEntry['fields'] => [
  {
    key: 'client_id',
    type: 'text',
    label: 'Client ID',
    hint: '',
    required: true,
    options: [],
    max_length: 255,
    min: null,
    max: null,
    value: set ? 'abc.apps.googleusercontent.com' : '',
    set: false,
  },
  {
    key: 'client_secret',
    type: 'secret',
    label: 'Client secret',
    hint: '',
    required: true,
    options: [],
    max_length: 200,
    min: null,
    max: null,
    value: '',
    set,
  },
];
const catalog: SocialLoginCatalogEntry[] = [
  {
    id: 'google',
    name: 'Google',
    fields: fields(false),
    callback_url: 'https://shop.test/auth/social/google/callback',
    console_url: 'https://console.cloud.google.com/apis/credentials',
  },
];
const shell = { navigation: [], breadcrumbs: [] };

it('keeps a stored client secret write-only and shows the callback address on its own tab', () => {
  const provider: SocialLoginEditor = {
    id: '3',
    provider: 'google',
    name: 'Google',
    active: true,
    version: 2,
    fields: fields(true),
  };
  render(<SocialLoginForm {...shell} provider={provider} catalog={catalog} />);

  expect(screen.getByLabelText(/Client ID/)).toHaveValue(
    'abc.apps.googleusercontent.com',
  );
  expect(screen.getByLabelText(/Client secret/)).toHaveValue('');
  expect(
    screen.getByText('A value is stored. Leave empty to keep it.'),
  ).toBeInTheDocument();
  expect(screen.getByLabelText('Provider')).toBeDisabled();

  fireEvent.click(screen.getByRole('tab', { name: 'Callback URL' }));
  expect(
    screen.getByLabelText('Callback URL', { selector: 'input' }),
  ).toHaveValue('https://shop.test/auth/social/google/callback');
});

it('lists providers and switches one on or off with its current version', () => {
  const patch = vi.spyOn(router, 'patch').mockImplementation(() => undefined);
  render(
    <SocialLoginIndex
      {...shell}
      providers={[
        {
          id: '3',
          provider: 'google',
          provider_name: 'Google',
          name: 'Google login',
          active: true,
          complete: false,
          version: 4,
        },
      ]}
    />,
  );

  expect(screen.getByText('Credentials missing')).toBeInTheDocument();
  fireEvent.click(
    screen.getByRole('switch', { name: 'Status of Google login' }),
  );
  expect(patch).toHaveBeenCalledWith(
    '/admin/social-login/3/active',
    { version: 4, is_active: false },
    { preserveScroll: true },
  );
});
