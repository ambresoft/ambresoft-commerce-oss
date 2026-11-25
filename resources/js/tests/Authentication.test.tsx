import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { router } from '@inertiajs/react';
import type { AnchorHTMLAttributes } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import Login from '../pages/Auth/Login';
import Register from '../pages/Auth/Register';
import ResetPassword from '../pages/Auth/ResetPassword';
import VerifyEmail from '../pages/Auth/VerifyEmail';
import Dashboard from '../pages/Admin/Dashboard';
import type { SharedProps } from '../types/page';
import { english, greek } from './translations';
import UserForm from '../pages/Admin/Users/Form';
import UsersIndex from '../pages/Admin/Users/Index';
import RoleForm from '../pages/Admin/Roles/Form';
import Sidebar from '../components/admin/Sidebar';

const overview = {
  currency: { code: 'EUR', decimals: 2 },
  orders: {
    ranges: Object.fromEntries(
      (
        [
          ['day', 24],
          ['week', 7],
          ['month', 30],
        ] as const
      ).map(([range, count]) => [
        range,
        {
          starts: Array.from({ length: count }, (_, i) =>
            new Date(Date.UTC(2026, 9, 1, i)).toISOString(),
          ),
          current: Array.from({ length: count }, (_, i) => i * 10),
          previous: Array.from({ length: count }, () => 5),
          count: 12,
          previous_count: 10,
        },
      ]),
    ),
    open: 3,
    recent: [
      {
        id: '2',
        number: '100002',
        customer: 'Maria Papadopoulou',
        status: 'Processing',
        color: '#0ea5e9',
        total: '129.50',
        currency: 'EUR',
        decimals: 2,
        created_at: '2026-10-11T10:00:00+00:00',
      },
    ],
  },
  catalog: { active: 20, out_of_stock: 2 },
  customers: { total: 8, new: { day: 1, week: 2, month: 4 } },
  activity: [
    { kind: 'order', subject: '100002', at: '2026-10-11T10:00:00+00:00' },
  ],
} as React.ComponentProps<typeof Dashboard>['overview'];

const shared = vi.hoisted(() => ({ props: {} as SharedProps }));

vi.mock('@inertiajs/react', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@inertiajs/react')>();
  return {
    ...actual,
    usePage: () => ({ url: '/admin', props: shared.props }),
    Head: () => null,
    Link: ({ children, ...props }: AnchorHTMLAttributes<HTMLAnchorElement>) => (
      <a {...props}>{children}</a>
    ),
  };
});

beforeEach(() => {
  vi.restoreAllMocks();
  window.history.replaceState({}, '', '/admin');
  shared.props = {
    app: { name: 'Commerce Workspace' },
    auth: {
      user: {
        id: '1',
        name: 'Alex Staff',
        email: 'alex@example.test',
        emailVerified: true,
      },
      canAccessAdmin: true,
    },
    flash: { status: null },
    capabilities: {},
    i18n: {
      locale: 'en',
      direction: 'ltr',
      strings: english,
      locales: [
        { code: 'en', name: 'English' },
        { code: 'el', name: 'Ελληνικά' },
      ],
    },
    links: {
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
    },
  };
});

it('remembers the sidebar collapse choice across admin pages', () => {
  localStorage.removeItem('cms-sidebar-collapsed');
  const page = () => (
    <Dashboard navigation={[]} breadcrumbs={[]} overview={overview} />
  );
  const view = render(page());
  fireEvent.click(screen.getByRole('button', { name: 'Collapse sidebar' }));
  expect(
    screen.getByRole('button', { name: 'Expand sidebar' }),
  ).toHaveAttribute('aria-expanded', 'false');
  expect(localStorage.getItem('cms-sidebar-collapsed')).toBe('true');
  view.unmount();
  render(page());
  fireEvent.click(screen.getByRole('button', { name: 'Expand sidebar' }));
  expect(
    screen.getByRole('button', { name: 'Collapse sidebar' }),
  ).toHaveAttribute('aria-expanded', 'true');
  expect(localStorage.getItem('cms-sidebar-collapsed')).toBe('false');
  localStorage.removeItem('cms-sidebar-collapsed');
});

it('groups staff and roles in one collapsible menu while retaining standalone links', () => {
  const onNavigate = vi.fn();
  render(
    <Sidebar
      onNavigate={onNavigate}
      navigation={[
        {
          id: 'core.dashboard',
          label: 'Dashboard',
          href: '/admin',
          active: true,
        },
        {
          id: 'core.users',
          label: 'Staff users',
          href: '/admin/users',
          active: false,
          group: { id: 'core.access', label: 'Access management' },
        },
        {
          id: 'core.roles',
          label: 'Roles',
          href: '/admin/roles',
          active: false,
          group: { id: 'core.access', label: 'Access management' },
        },
      ]}
    />,
  );
  const summary = screen.getByText('Access management');
  const dropdown = summary.closest('details')!;
  expect(screen.getAllByText('Access management')).toHaveLength(1);
  expect(dropdown).not.toHaveAttribute('open');
  expect(screen.getByText('Staff users')).not.toBeVisible();
  fireEvent.click(summary);
  expect(dropdown).toHaveAttribute('open');
  expect(screen.getByRole('link', { name: 'Staff users' })).toBeVisible();
  const rolesLink = screen.getByRole('link', { name: 'Roles' });
  // Inertia intercepts navigation in production; this test's Link is a plain anchor.
  rolesLink.addEventListener('click', (event) => event.preventDefault());
  fireEvent.click(rolesLink);
  expect(onNavigate).toHaveBeenCalledOnce();
  fireEvent.click(summary);
  expect(dropdown).not.toHaveAttribute('open');
  expect(screen.getByRole('link', { name: 'Dashboard' })).toBeVisible();
});

it('opens the active navigation group and renders only the supplied authorized child', () => {
  render(
    <Sidebar
      navigation={[
        {
          id: 'core.roles',
          label: 'Roles',
          href: '/admin/roles',
          active: true,
          group: { id: 'core.access', label: 'Access management' },
        },
      ]}
    />,
  );
  expect(
    screen.getByText('Access management').closest('details'),
  ).toHaveAttribute('open');
  expect(screen.getByRole('link', { name: 'Roles' })).toHaveAttribute(
    'aria-current',
    'page',
  );
  expect(screen.queryByText('Staff users')).not.toBeInTheDocument();
});

it('groups audit and AI history under Logs and opens the active menu', () => {
  render(
    <Sidebar
      navigation={[
        {
          id: 'core.audit-log',
          label: 'Audit Logs',
          href: '/admin/audit-logs',
          active: false,
          group: { id: 'core.logs', label: 'Logs' },
        },
        {
          id: 'core.ai-log',
          label: 'AI Log',
          href: '/admin/settings/ai-log',
          active: true,
          group: { id: 'core.logs', label: 'Logs' },
        },
      ]}
    />,
  );
  const menu = screen.getByText('Logs').closest('details')!;
  expect(menu).toHaveAttribute('open');
  expect(menu).toContainElement(screen.getByRole('link', { name: 'AI Log' }));
  expect(menu).toContainElement(
    screen.getByRole('link', { name: 'Audit Logs' }),
  );
  expect(screen.getByRole('link', { name: 'AI Log' })).toHaveAttribute(
    'aria-current',
    'page',
  );
});

it('renders Logs with just the history entries supplied by the server', () => {
  render(
    <Sidebar
      navigation={[
        {
          id: 'core.ai-log',
          label: 'AI Log',
          href: '/admin/settings/ai-log',
          active: true,
          group: { id: 'core.logs', label: 'Logs' },
        },
      ]}
    />,
  );
  expect(screen.getByRole('link', { name: 'AI Log' })).toBeVisible();
  expect(
    screen.queryByRole('link', { name: 'Audit Logs' }),
  ).not.toBeInTheDocument();
});

it('language selector submits a persisted server preference and the current admin URL', () => {
  const patch = vi.spyOn(router, 'patch').mockImplementation(() => {});
  window.history.replaceState({}, '', '/admin/users?status=active');
  render(<Dashboard navigation={[]} breadcrumbs={[]} overview={overview} />);
  fireEvent.click(screen.getByRole('button', { name: /Interface language/ }));
  fireEvent.click(screen.getByRole('button', { name: 'Ελληνικά' }));
  expect(patch).toHaveBeenCalledWith(
    '/admin/locale',
    { locale: 'el', return_to: '/admin/users?status=active' },
    expect.any(Object),
  );
});

it('switches the dashboard period between day, week and month and no longer shows the environment', () => {
  render(<Dashboard navigation={[]} breadcrumbs={[]} overview={overview} />);
  expect(screen.queryByText(/environment/i)).not.toBeInTheDocument();
  const range = screen.getByRole('combobox', { name: 'Period' });
  expect(range).toHaveTextContent('Month');
  expect(screen.getByText('vs previous 30 days')).toBeVisible();
  expect(screen.getByText('Previous 30 days')).toBeVisible();
  fireEvent.click(range);
  expect(
    screen.getAllByRole('option').map((option) => option.textContent),
  ).toEqual(['Day', 'Week', 'Month']);
  fireEvent.click(screen.getByRole('option', { name: 'Week' }));
  expect(screen.getByText('vs previous 7 days')).toBeVisible();
  expect(screen.getByText('Placed in the last 7 days')).toBeVisible();
  expect(
    screen.getByRole('img', {
      name: /last 7 days compared with the previous 7 days/,
    }),
  ).toBeInTheDocument();
  fireEvent.click(screen.getByRole('combobox', { name: 'Period' }));
  fireEvent.click(screen.getByRole('option', { name: 'Day' }));
  expect(screen.getByText('vs previous day')).toBeVisible();
  expect(screen.getByText('Placed in the last 24 hours')).toBeVisible();
});

it('renders the Greek dashboard, shell, account menu and persisted language selection', () => {
  shared.props.i18n = { ...shared.props.i18n, locale: 'el', strings: greek };
  render(<Dashboard navigation={[]} breadcrumbs={[]} overview={overview} />);
  expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(
    'Καλώς ήρθατε ξανά, Alex Staff',
  );
  expect(
    screen.getByRole('button', { name: /Γλώσσα περιβάλλοντος/ }),
  ).toHaveTextContent('Ελληνικά');
  expect(document.documentElement.lang).toBe('el');
  const account = screen.getByLabelText('Μενού λογαριασμού για Alex Staff');
  expect(account).not.toHaveTextContent('Alex Staff');
  fireEvent.click(account);
  expect(screen.getByText('Alex Staff', { exact: true })).toBeVisible();
  expect(screen.getByRole('button', { name: 'Αποσύνδεση' })).toBeVisible();
  expect(screen.queryByText('Workspace overview')).not.toBeInTheDocument();
});

it('staff form submits selected roles and confirmation through Inertia', () => {
  shared.props.capabilities = {
    'users.set-password': true,
    'users.assign-roles': true,
    'users.deactivate': true,
  };
  const post = vi.spyOn(router, 'post').mockImplementation(() => {});
  render(
    <UserForm
      staff={null}
      roles={[{ id: '7', name: 'Auditor' }]}
      navigation={[]}
      breadcrumbs={[]}
    />,
  );
  fireEvent.change(screen.getByLabelText('Name'), {
    target: { value: 'New staff' },
  });
  fireEvent.change(screen.getByLabelText('Email'), {
    target: { value: 'new@example.test' },
  });
  fireEvent.click(screen.getByRole('tab', { name: 'Security' }));
  fireEvent.click(
    screen.getByRole('checkbox', {
      name: 'Email a private registration invitation',
    }),
  );
  fireEvent.change(screen.getByLabelText('Password'), {
    target: { value: 'a long staff passphrase' },
  });
  fireEvent.change(screen.getByLabelText('Confirm password'), {
    target: { value: 'a long staff passphrase' },
  });
  fireEvent.click(screen.getByRole('tab', { name: 'Roles' }));
  fireEvent.click(screen.getByLabelText('Auditor'));
  fireEvent.submit(
    (screen.getByRole('button', { name: 'Save changes' }) as HTMLButtonElement)
      .form!,
  );
  expect(post.mock.calls[0][1]).toMatchObject({
    name: 'New staff',
    role_ids: ['7'],
    password_confirmation: 'a long staff passphrase',
    is_active: true,
  });
});

it('role form submits capabilities and renders server errors without losing selections', () => {
  const post = vi.spyOn(router, 'post').mockImplementation(() => {});
  render(
    <RoleForm
      role={null}
      permissions={[{ name: 'users.view', label: 'View staff users' }]}
      navigation={[]}
      breadcrumbs={[]}
    />,
  );
  fireEvent.change(screen.getByLabelText('Name'), {
    target: { value: 'Reader' },
  });
  fireEvent.change(screen.getByLabelText('Slug'), {
    target: { value: 'reader' },
  });
  fireEvent.click(screen.getByRole('tab', { name: 'Permissions' }));
  expect(window.location.search).toBe('?tab=permissions');
  fireEvent.click(screen.getByRole('checkbox', { name: /View staff users/ }));
  fireEvent.submit(
    (screen.getByRole('button', { name: 'Save changes' }) as HTMLButtonElement)
      .form!,
  );
  expect(post.mock.calls[0][1]).toMatchObject({
    name: 'Reader',
    slug: 'reader',
    permissions: ['users.view'],
  });
  act(() =>
    post.mock.calls[0][2]?.onError?.({
      access: 'You cannot grant this capability.',
    }),
  );
  expect(screen.getByRole('alert')).toHaveTextContent(
    'You cannot grant this capability.',
  );
  expect(screen.getByRole('checkbox')).toBeChecked();
});

it('deactivation requires confirmation and does not optimistically remove the account', () => {
  shared.props.capabilities = { 'users.deactivate': true };
  const remove = vi.spyOn(router, 'delete').mockImplementation(() => {});
  const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
  render(
    <UserForm
      staff={{
        id: '2',
        name: 'Other Staff',
        email: 'other@example.test',
        active: true,
        roleIds: [],
      }}
      roles={[]}
      navigation={[]}
      breadcrumbs={[]}
    />,
  );
  const form = (
    screen.getByRole('button', { name: 'Deactivate' }) as HTMLButtonElement
  ).form!;
  fireEvent.submit(form);
  expect(remove).not.toHaveBeenCalled();
  confirm.mockReturnValue(true);
  fireEvent.submit(form);
  expect(remove).toHaveBeenCalledWith('/admin/users/2', expect.any(Object));
});

it('submits login through Inertia, disables duplicate submission, presents server errors and clears only the password', () => {
  const post = vi.spyOn(router, 'post').mockImplementation(() => {});
  render(<Login />);
  fireEvent.change(screen.getByLabelText('Email address'), {
    target: { value: 'alex@example.test' },
  });
  fireEvent.change(screen.getByLabelText('Password'), {
    target: { value: 'private passphrase' },
  });
  fireEvent.click(screen.getByLabelText('Remember me'));
  fireEvent.submit(
    (screen.getByRole('button', { name: 'Sign in' }) as HTMLButtonElement)
      .form!,
  );
  expect(post).toHaveBeenCalledWith(
    '/login',
    {
      email: 'alex@example.test',
      password: 'private passphrase',
      remember: true,
    },
    expect.any(Object),
  );
  const options = post.mock.calls[0][2]!;
  const visit = {} as Parameters<NonNullable<typeof options.onStart>>[0];
  act(() => {
    options.onStart?.(visit);
  });
  expect(screen.getByRole('button', { name: 'Please wait…' })).toBeDisabled();
  act(() => {
    options.onError?.({ email: 'These credentials do not match our records.' });
    options.onFinish?.(
      {} as Parameters<NonNullable<typeof options.onFinish>>[0],
    );
  });
  expect(screen.getByRole('alert')).toHaveTextContent(
    'These credentials do not match',
  );
  expect(screen.getByLabelText('Email address')).toHaveAttribute(
    'aria-invalid',
    'true',
  );
  expect(screen.getByLabelText('Email address')).toHaveAccessibleDescription(
    'These credentials do not match our records.',
  );
  expect(screen.getByLabelText('Email address')).toHaveValue(
    'alex@example.test',
  );
  expect(screen.getByLabelText('Password')).toHaveValue('');
  expect(screen.getByRole('button', { name: 'Sign in' })).toBeEnabled();
});

it('registration submits confirmation without adding frontend permission fields', () => {
  const post = vi.spyOn(router, 'post').mockImplementation(() => {});
  render(<Register />);
  fireEvent.change(screen.getByLabelText('Full name'), {
    target: { value: 'Alex' },
  });
  fireEvent.change(screen.getByLabelText('Email address'), {
    target: { value: 'alex@example.test' },
  });
  fireEvent.change(screen.getByLabelText('Password'), {
    target: { value: 'a private passphrase' },
  });
  fireEvent.change(screen.getByLabelText('Confirm password'), {
    target: { value: 'a private passphrase' },
  });
  expect(screen.getByLabelText('Password')).toHaveAttribute(
    'autocomplete',
    'new-password',
  );
  fireEvent.submit(
    (
      screen.getByRole('button', {
        name: 'Create account',
      }) as HTMLButtonElement
    ).form!,
  );
  expect(post).toHaveBeenCalledWith(
    '/register',
    {
      name: 'Alex',
      email: 'alex@example.test',
      password: 'a private passphrase',
      password_confirmation: 'a private passphrase',
    },
    expect.any(Object),
  );
});

it('password reset submits the server token and clears both secret fields after finishing', () => {
  const post = vi.spyOn(router, 'post').mockImplementation(() => {});
  render(<ResetPassword token="reset-token" email="alex@example.test" />);
  fireEvent.change(screen.getByLabelText('New password'), {
    target: { value: 'a new private passphrase' },
  });
  fireEvent.change(screen.getByLabelText('Confirm new password'), {
    target: { value: 'a new private passphrase' },
  });
  fireEvent.submit(
    (
      screen.getByRole('button', {
        name: 'Reset password',
      }) as HTMLButtonElement
    ).form!,
  );
  expect(post.mock.calls[0][1]).toEqual({
    email: 'alex@example.test',
    token: 'reset-token',
    password: 'a new private passphrase',
    password_confirmation: 'a new private passphrase',
  });
  const options = post.mock.calls[0][2]!;
  act(() => {
    options.onFinish?.(
      {} as Parameters<NonNullable<typeof options.onFinish>>[0],
    );
  });
  expect(screen.getByLabelText('New password')).toHaveValue('');
  expect(screen.getByLabelText('Confirm new password')).toHaveValue('');
});

it('verification offers real resend and logout actions with accessible flash feedback', () => {
  const post = vi.spyOn(router, 'post').mockImplementation(() => {});
  shared.props.flash.status = 'A verification link has been sent.';
  render(<VerifyEmail />);
  expect(screen.getByRole('status')).toHaveTextContent(
    'A verification link has been sent.',
  );
  expect(
    screen.getByText(/Verification email sent to alex@example.test/),
  ).toBeVisible();
  fireEvent.submit(
    (
      screen.getByRole('button', {
        name: 'Resend verification email',
      }) as HTMLButtonElement
    ).form!,
  );
  expect(post.mock.calls[0][0]).toBe('/email/verification-notification');
  fireEvent.submit(
    (screen.getByRole('button', { name: 'Sign out' }) as HTMLButtonElement)
      .form!,
  );
  expect(post.mock.calls[1][0]).toBe('/logout');
});

it('admin shell renders only supplied navigation, real account details and working mobile/account controls', () => {
  const post = vi.spyOn(router, 'post').mockImplementation(() => {});
  vi.spyOn(HTMLDialogElement.prototype, 'showModal').mockImplementation(
    function (this: HTMLDialogElement) {
      this.setAttribute('open', '');
    },
  );
  vi.spyOn(HTMLDialogElement.prototype, 'close').mockImplementation(function (
    this: HTMLDialogElement,
  ) {
    this.removeAttribute('open');
  });
  render(
    <Dashboard
      navigation={[
        {
          id: 'core.dashboard',
          label: 'Dashboard',
          href: '/admin',
          active: true,
        },
      ]}
      breadcrumbs={[{ label: 'Dashboard', href: null }]}
      overview={overview}
    />,
  );
  expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(
    'Welcome back, Alex Staff',
  );
  expect(screen.queryByText('local environment')).not.toBeInTheDocument();
  const navigation = screen.getAllByRole('navigation', {
    name: 'Administration',
  })[0];
  expect(within(navigation).getAllByRole('link')).toHaveLength(1);
  expect(
    within(navigation).getByRole('link', { name: 'Dashboard' }),
  ).toHaveAttribute('aria-current', 'page');
  expect(screen.queryByText(/sample data/)).not.toBeInTheDocument();
  expect(
    screen.getByText('Orders in statuses that reserve stock'),
  ).toBeVisible();
  expect(screen.getByRole('combobox', { name: 'Period' })).toBeVisible();
  expect(
    screen.getByRole('img', {
      name: 'Daily sales for the last 30 days compared with the previous 30 days',
    }),
  ).toBeVisible();
  const rows = within(
    screen.getByRole('region', { name: 'Recent orders' }),
  ).getAllByRole('row');
  expect(rows).toHaveLength(2);
  expect(rows[1]).toHaveTextContent('100002');
  expect(rows[1]).toHaveTextContent('Maria Papadopoulou');
  expect(rows[1]).toHaveTextContent('Processing');
  fireEvent.click(screen.getByRole('button', { name: 'Menu' }));
  expect(
    screen.getByRole('dialog', { name: 'Administration navigation' }),
  ).toHaveAttribute('open');
  fireEvent.click(screen.getByRole('button', { name: 'Close navigation' }));
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  fireEvent.click(screen.getByLabelText('Account menu for Alex Staff'));
  const logout = screen.getByRole('button', { name: 'Sign out' });
  expect(logout).toBeVisible();
  fireEvent.submit(logout.closest('form')!);
  expect(post).toHaveBeenCalledWith('/logout', {}, expect.any(Object));
});

it('shows the customer sign-in in the store layout with a revealable password and the forgot link beside it', () => {
  shared.props.authArea = 'customer';
  shared.props.storeBrand = {
    name: 'Northwind',
    logo: null,
    colors: {
      primary: '#05ab7d',
      primary_hover: '#079c72',
      on_primary: '#ffffff',
      text: '#333333',
      background: '#f8f8f8',
      sale: '#ff3d47',
    },
    auth_header: { background: '#123456', text: '#ffffff' },
  };
  const { container } = render(<Login />);
  expect(container.querySelector('.store-auth')).not.toBeNull();
  // A header across the top with the store's name (no logo here) in the middle, in the configured color.
  const header = container.querySelector<HTMLElement>('.store-auth-header')!;
  expect(header.style.getPropertyValue('--store-auth-header-bg')).toBe(
    '#123456',
  );
  expect(screen.getByRole('link', { name: 'Northwind' })).toHaveAttribute(
    'href',
    '/',
  );
  const password = screen.getByLabelText('Password');
  expect(password).toHaveAttribute('type', 'password');
  fireEvent.click(screen.getByRole('button', { name: 'Show' }));
  expect(password).toHaveAttribute('type', 'text');
  fireEvent.click(screen.getByRole('button', { name: 'Hide' }));
  expect(password).toHaveAttribute('type', 'password');
  expect(
    screen.getByRole('link', { name: 'Forgot password?' }),
  ).toHaveAttribute('href', '/forgot-password');
  expect(
    screen.getAllByRole('link', { name: 'Forgot password?' }),
  ).toHaveLength(1);
});

it('admin login offers passkeys and has no public registration link; customer login keeps signup', () => {
  shared.props.authArea = 'admin';
  const { unmount } = render(<Login />);
  expect(
    screen.getByRole('button', { name: 'Sign in with a passkey' }),
  ).toBeVisible();
  expect(
    screen.queryByRole('link', { name: 'Create an account' }),
  ).not.toBeInTheDocument();
  unmount();
  shared.props.authArea = 'customer';
  render(<Login />);
  expect(
    screen.queryByRole('button', { name: 'Sign in with a passkey' }),
  ).not.toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Create an account' })).toBeVisible();
});

it('invited admin registration locks identity fields and posts to the signed URL', () => {
  const post = vi.spyOn(router, 'post').mockImplementation(() => {});
  const signedUrl =
    '/admin/register?user=7&token=private&expires=123&signature=signed';
  render(
    <Register
      invitation={{
        name: 'Invited Staff',
        email: 'invitee@example.test',
        submitUrl: signedUrl,
      }}
    />,
  );
  expect(screen.getByLabelText('Full name')).toHaveAttribute('readonly');
  expect(screen.getByLabelText('Email address')).toHaveValue(
    'invitee@example.test',
  );
  expect(screen.getByLabelText('Email address')).toHaveAttribute('readonly');
  expect(
    screen.getByRole('button', { name: 'Register a passkey' }),
  ).toBeVisible();
  fireEvent.change(screen.getByLabelText('Password'), {
    target: { value: 'a long staff passphrase' },
  });
  fireEvent.change(screen.getByLabelText('Confirm password'), {
    target: { value: 'a long staff passphrase' },
  });
  fireEvent.submit(
    (
      screen.getByRole('button', {
        name: 'Create account',
      }) as HTMLButtonElement
    ).form!,
  );
  expect(post.mock.calls[0][0]).toBe(signedUrl);
});

it('omits every super-admin row action when the server denies management of that identity', () => {
  shared.props.capabilities = {
    'users.update': true,
    'users.deactivate': true,
  };
  render(
    <UsersIndex
      navigation={[]}
      breadcrumbs={[]}
      roles={[]}
      filters={{}}
      users={{
        data: [
          {
            id: '9',
            name: 'Protected Super',
            email: 'super@example.test',
            active: true,
            verified: true,
            roles: [{ id: '1', name: 'Super Admin' }],
            editUrl: null,
            canDeactivate: false,
          },
        ],
        current_page: 1,
        last_page: 1,
        prev_page_url: null,
        next_page_url: null,
        total: 1,
      }}
    />,
  );
  const row = screen.getByRole('row', { name: /Protected Super/ });
  expect(within(row).queryByRole('link')).not.toBeInTheDocument();
  expect(within(row).queryByRole('button')).not.toBeInTheDocument();
});

it('hides password inputs and protects recovery email from staff without password authority', () => {
  shared.props.capabilities = { 'users.update': true };
  render(
    <UserForm
      navigation={[]}
      breadcrumbs={[]}
      roles={[]}
      staff={{
        id: '8',
        name: 'Other staff',
        email: 'other@example.test',
        active: true,
        verified: true,
        roleIds: [],
      }}
    />,
  );
  expect(screen.getByLabelText('Email')).toHaveAttribute('readonly');
  fireEvent.click(screen.getByRole('tab', { name: 'Security' }));
  expect(screen.queryByLabelText('Password')).not.toBeInTheDocument();
  expect(screen.queryByLabelText('Confirm password')).not.toBeInTheDocument();
  expect(screen.queryByRole('tab', { name: 'Roles' })).not.toBeInTheDocument();
});
