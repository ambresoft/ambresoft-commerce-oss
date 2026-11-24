import { fireEvent, render, screen } from '@testing-library/react';
import { router } from '@inertiajs/react';
import type { AnchorHTMLAttributes } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import CustomerRegister from '../pages/Customer/Register';
import Login from '../pages/Auth/Login';
import AccessPending from '../pages/Auth/AccessPending';
import ErrorPage from '../pages/Error';
import type { SharedProps } from '../types/page';
import { english } from './translations';

const shared = vi.hoisted(() => ({ props: {} as SharedProps }));
vi.mock('@inertiajs/react', async (original) => ({
  ...(await original<typeof import('@inertiajs/react')>()),
  usePage: () => ({ props: shared.props }),
  Head: () => null,
  Link: ({ children, ...props }: AnchorHTMLAttributes<HTMLAnchorElement>) => (
    <a {...props}>{children}</a>
  ),
}));

const customerLinks = {
  login: '/login',
  'login.store': '/login',
  register: '/register',
  'register.store': '/register',
  logout: '/logout',
  'password.request': '/forgot-password',
  'password.email': '/forgot-password',
  'password.update': '/reset-password',
  'verification.send': '/email/verification-notification',
  'admin.dashboard': '/admin',
  'access.pending': '/admin/access-pending',
  'admin.login': '/admin/login',
  account: '/account',
};
const labels = {
  'auth.first_name': 'First name',
  'auth.last_name': 'Last name',
  'auth.email': 'Email address',
  'auth.password': 'Password',
  'auth.confirm': 'Confirm password',
  'auth.create': 'Create account',
  'auth.login_description': 'Sign in to your administration account.',
  'auth.customer_login_description': 'Sign in to your customer account.',
  'auth.customer_password_hint': 'Use at least 8 characters (up to 128).',
  'auth.account_title': 'Hello, :name',
  'auth.pending_password': 'Administration requires 15 characters.',
  'auth.pending_body': 'Ask for access for :email.',
  'auth.forgot': 'Forgot password?',
  'navigation.sign_out': 'Sign out',
  'admin.error.account': 'Open your account',
  'admin.error.login': 'Sign in',
};

beforeEach(() => {
  vi.restoreAllMocks();
  shared.props = {
    app: { name: 'Store' },
    auth: { user: null, canAccessAdmin: false },
    flash: { status: null },
    capabilities: {},
    i18n: {
      locale: 'en',
      direction: 'ltr',
      strings: { ...english, ...labels },
      locales: [{ code: 'en', name: 'English' }],
    },
    links: customerLinks,
    authArea: 'customer',
  } as SharedProps;
});

it('registers customers with separate names and the customer password hint', () => {
  const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
  render(<CustomerRegister />);
  expect(
    screen.getByText('Use at least 8 characters (up to 128).'),
  ).toBeInTheDocument();
  fireEvent.change(screen.getByLabelText(/First name/), {
    target: { value: 'Maria' },
  });
  fireEvent.change(screen.getByLabelText(/Last name/), {
    target: { value: 'Papadopoulou' },
  });
  fireEvent.change(screen.getByLabelText(/Email address/), {
    target: { value: 'maria@example.test' },
  });
  fireEvent.change(screen.getByLabelText(/^Password/), {
    target: { value: 'shop pass' },
  });
  fireEvent.change(screen.getByLabelText(/Confirm password/), {
    target: { value: 'shop pass' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Create account' }));
  expect(visit.mock.calls[0]![0]).toBe('/register');
  expect(visit.mock.calls[0]![1]).toMatchObject({
    method: 'post',
    data: {
      first_name: 'Maria',
      last_name: 'Papadopoulou',
      email: 'maria@example.test',
    },
  });
});

it('describes the sign-in page by area', () => {
  const view = render(<Login />);
  expect(
    screen.getByText('Sign in to your customer account.'),
  ).toBeInTheDocument();
  view.unmount();
  shared.props.authArea = 'admin';
  render(<Login />);
  expect(
    screen.getByText('Sign in to your administration account.'),
  ).toBeInTheDocument();
});

it('links error pages to the current area', () => {
  const view = render(<ErrorPage status={403} retryAfter={null} />);
  expect(
    screen.getByRole('link', { name: 'Open your account' }),
  ).toHaveAttribute('href', '/account');
  view.unmount();
  shared.props.authArea = 'admin';
  render(<ErrorPage status={403} retryAfter={null} />);
  expect(
    screen.getByRole('link', { name: 'Open your account' }),
  ).toHaveAttribute('href', '/admin/access-pending');
});

it('explains when a granted identity needs a staff-length password', () => {
  shared.props.authArea = 'admin';
  render(<AccessPending passwordTooShort />);
  expect(
    screen.getByText('Administration requires 15 characters.'),
  ).toBeInTheDocument();
  expect(
    screen.getByRole('link', { name: 'Forgot password?' }),
  ).toHaveAttribute('href', '/admin/forgot-password');
});
