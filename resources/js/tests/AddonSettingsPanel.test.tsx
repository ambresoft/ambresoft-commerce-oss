import { act, fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import AddonSettings from '../pages/Admin/Addons/Settings';
import type { AdminShell } from '../types/admin';

const reload = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', async (original) => ({
  ...(await original<typeof import('@inertiajs/react')>()),
  router: { reload },
}));
vi.mock('../layouts/admin/AdminLayout', () => ({
  default: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));
vi.mock('../lib/useTranslation', () => ({
  useTranslation: () => (key: string) => key,
}));

const panel = {
  rows: [{ label: 'Credential status', value: 'Active' }],
  actions: [
    { key: 'rotate', label: 'Rotate', confirm: 'Sure?', destructive: false },
    { key: 'revoke', label: 'Revoke', confirm: '', destructive: true },
  ],
};
const addon = {
  name: 'acme/panel',
  title: 'Panel',
  version: 1,
  panel,
  fields: [],
};
const shell = {} as AdminShell;
const respond = (body: unknown, ok = true) =>
  vi.fn().mockResolvedValue({ ok, json: async () => body });

beforeEach(() => {
  reload.mockClear();
  document.cookie = 'XSRF-TOKEN=abc%3D';
});

it('shows the status lines and actions, and no empty form', () => {
  render(<AddonSettings addon={addon} {...shell} />);

  expect(screen.getByText('Credential status')).toBeTruthy();
  expect(screen.getByText('Active')).toBeTruthy();
  expect(screen.getByRole('button', { name: 'Revoke' })).toBeTruthy();
  expect(screen.queryByRole('button', { name: 'common.save' })).toBeNull();
});

it('asks first, and does nothing when the administrator declines', async () => {
  const fetchMock = respond({ secret: null });
  vi.stubGlobal('fetch', fetchMock);
  vi.spyOn(window, 'confirm').mockReturnValue(false);
  render(<AddonSettings addon={addon} {...shell} />);

  await act(async () => {
    fireEvent.click(screen.getByRole('button', { name: 'Rotate' }));
  });

  expect(window.confirm).toHaveBeenCalledWith('Sure?');
  expect(fetchMock).not.toHaveBeenCalled();
});

it('posts with the CSRF token and shows a returned secret once', async () => {
  const fetchMock = respond({
    secret: { label: 'New credential', hint: 'Copy it now', value: 's3cret' },
  });
  vi.stubGlobal('fetch', fetchMock);
  vi.spyOn(window, 'confirm').mockReturnValue(true);
  const { unmount } = render(<AddonSettings addon={addon} {...shell} />);

  await act(async () => {
    fireEvent.click(screen.getByRole('button', { name: 'Rotate' }));
  });

  const [url, init] = fetchMock.mock.calls[0];
  expect(url).toBe('/admin/addons/acme/panel/settings/actions/rotate');
  expect(init.headers['X-XSRF-TOKEN']).toBe('abc=');
  expect(screen.getByDisplayValue('s3cret')).toBeTruthy();
  expect(screen.getByText('Copy it now')).toBeTruthy();
  expect(reload).toHaveBeenCalledWith({ only: ['addon'] });

  // It lives only in the component: a remount has nothing to show.
  unmount();
  render(<AddonSettings addon={addon} {...shell} />);
  expect(screen.queryByDisplayValue('s3cret')).toBeNull();
  expect(JSON.stringify({ ...localStorage, ...sessionStorage })).not.toContain(
    's3cret',
  );
});

it('says so when an action fails, and shows no secret', async () => {
  vi.stubGlobal('fetch', respond({}, false));
  render(<AddonSettings addon={addon} {...shell} />);

  await act(async () => {
    fireEvent.click(screen.getByRole('button', { name: 'Revoke' }));
  });

  expect(screen.getByRole('alert').textContent).toBe('addons.action_failed');
  expect(reload).not.toHaveBeenCalled();
});

const remote = {
  title: 'AI provider',
  hint: 'Choose one',
  selected: 'alpha',
  rows: [{ label: 'Status', value: 'Connected' }],
  choices: [
    {
      value: 'alpha',
      label: 'Alpha',
      description: 'First',
      guide: 'How to set it up',
      fields: [
        {
          key: 'model',
          type: 'text' as const,
          label: 'Model',
          hint: '',
          required: true,
          options: [],
          max_length: 128,
          min: null,
          max: null,
          value: 'one',
          set: false,
        },
        {
          key: 'api_key',
          type: 'secret' as const,
          label: 'API key',
          hint: '',
          required: true,
          options: [],
          max_length: 512,
          min: null,
          max: null,
          value: '',
          set: true,
        },
      ],
    },
    {
      value: 'beta',
      label: 'Beta',
      description: 'Second',
      guide: '',
      fields: [],
    },
  ],
};
const remoteAddon = {
  ...addon,
  panel: {
    rows: [],
    actions: [
      {
        key: 'test',
        label: 'Test connection',
        confirm: '',
        destructive: false,
        in_form: true,
      },
    ],
  },
  remote,
};

it('splits a page with a remote form into tabs, and marks the choice in use', () => {
  const { container } = render(
    <AddonSettings
      addon={{
        ...remoteAddon,
        fields: [{ ...remote.choices[0].fields[0], key: 'other' }],
        remote: {
          ...remote,
          choices: remote.choices.map((c, i) =>
            i === 0 ? { ...c, icon: 'data:image/png;base64,AA==' } : c,
          ),
        },
      }}
      {...shell}
    />,
  );

  expect(screen.getByText('addons.remote_in_use')).toBeTruthy();
  expect(container.querySelector('img')?.getAttribute('src')).toContain(
    'data:image/png',
  );
  expect(screen.getByRole('radiogroup')).toBeTruthy();
  fireEvent.click(screen.getByRole('tab', { name: 'addons.tab_settings' }));
  expect(screen.queryByRole('radiogroup')).toBeNull();
  expect(
    screen
      .getByRole('tab', { name: 'addons.tab_settings' })
      .getAttribute('aria-selected'),
  ).toBe('true');
});

it('shows the choices as cards, with the fields of the chosen one', () => {
  render(<AddonSettings addon={remoteAddon} {...shell} />);

  const radios = screen.getAllByRole('radio');
  expect(radios).toHaveLength(2);
  expect((radios[0] as HTMLInputElement).checked).toBe(true);
  expect(screen.getByLabelText(/Model/)).toBeTruthy();
  expect(screen.getByText('How to set it up')).toBeTruthy();
  expect(screen.getByText('Connected')).toBeTruthy();

  fireEvent.click(radios[1]);
  expect(screen.queryByLabelText(/Model/)).toBeNull();
});

it('keeps a saved secret out of the form, and sends only what was typed', async () => {
  const fetchMock = vi.fn().mockResolvedValue({
    ok: true,
    status: 200,
    json: async () => ({ errors: {} }),
  });
  vi.stubGlobal('fetch', fetchMock);
  render(<AddonSettings addon={remoteAddon} {...shell} />);

  const key = screen.getByLabelText(/API key/) as HTMLInputElement;
  expect(key.type).toBe('password');
  expect(key.value).toBe('');
  expect(screen.getByText('shipping.secret_set')).toBeTruthy();
  fireEvent.change(key, { target: { value: 'typed-key' } });
  await act(async () => {
    fireEvent.submit(key.closest('form') as HTMLFormElement);
  });

  const [url, init] = fetchMock.mock.calls[0];
  expect(url).toBe('/admin/addons/acme/panel/settings/remote');
  expect(JSON.parse(init.body)).toEqual({
    choice: 'alpha',
    values: { model: 'one', api_key: 'typed-key' },
  });
  expect(init.headers['X-XSRF-TOKEN']).toBe('abc=');
  expect(key.value).toBe('');
  expect(screen.getByRole('status').textContent).toBe('addons.remote_saved');
  expect(reload).toHaveBeenCalled();
});

it('shows what the add-on refused, keeps what was typed, and reloads nothing', async () => {
  vi.stubGlobal(
    'fetch',
    vi.fn().mockResolvedValue({
      ok: false,
      status: 422,
      json: async () => ({ errors: { api_key: 'That key was refused' } }),
    }),
  );
  render(<AddonSettings addon={remoteAddon} {...shell} />);
  const key = screen.getByLabelText(/API key/) as HTMLInputElement;
  fireEvent.change(key, { target: { value: 'wrong-key' } });
  await act(async () => {
    fireEvent.submit(key.closest('form') as HTMLFormElement);
  });

  expect(screen.getByText('That key was refused')).toBeTruthy();
  expect(key.value).toBe('wrong-key');
  expect(reload).not.toHaveBeenCalled();
});

it('runs a form action beside the form, not with the status lines', async () => {
  const fetchMock = respond({ secret: null });
  vi.stubGlobal('fetch', fetchMock);
  render(<AddonSettings addon={remoteAddon} {...shell} />);

  const buttons = screen.getAllByRole('button', { name: 'Test connection' });
  expect(buttons).toHaveLength(1);
  await act(async () => {
    fireEvent.click(buttons[0]);
  });
  expect(fetchMock.mock.calls[0][0]).toBe(
    '/admin/addons/acme/panel/settings/actions/test',
  );
});
