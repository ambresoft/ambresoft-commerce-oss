import {
  fireEvent,
  render,
  screen,
  waitFor,
  within,
} from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import Passkeys from '../pages/Auth/Passkeys';
import { performPasskey } from '../lib/passkeys';
import { faqRequest } from '../lib/faqRequest';

vi.mock('../layouts/admin/AdminLayout', () => ({
  default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));
vi.mock('../lib/useDateDisplay', () => ({
  useDateDisplay: () => ({ date: { format: () => '23/10/2026' } }),
}));
vi.mock('../lib/useTranslation', () => ({
  useTranslation:
    () =>
    (key: string, values: Record<string, string | number> = {}) => {
      const labels: Record<string, string> = {
        'auth.passkey_add': 'Add passkey',
        'auth.password': 'Current password',
        'auth.passkey_remove_title': 'Remove passkey',
        'auth.passkey_actions': 'Actions for passkey #:id',
        'auth.passkey_item': 'Passkey #:id',
        'auth.passkey_failed': 'Unable to use this passkey.',
        'common.delete': 'Delete',
        'common.cancel': 'Cancel',
        'common.wait': 'Please wait…',
      };
      return Object.entries(values).reduce(
        (text, [name, value]) => text.replace(`:${name}`, String(value)),
        labels[key] ?? key,
      );
    },
}));
vi.mock('../lib/passkeys', () => ({ performPasskey: vi.fn() }));
vi.mock('../lib/faqRequest', () => ({ faqRequest: vi.fn() }));
const props = {
  navigation: [],
  breadcrumbs: [],
  passkeys: [{ id: 7, created_at: '2026-10-23T10:00:00Z' }],
};
beforeEach(() => vi.clearAllMocks());

it('asks for the password in a dialog and retains registration errors without creating duplicate requests', async () => {
  let reject!: (error: Error) => void;
  vi.mocked(performPasskey).mockReturnValue(
    new Promise((_, fail) => {
      reject = fail;
    }),
  );
  render(<Passkeys {...props} />);
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Add passkey' }));
  const dialog = screen.getByRole('dialog', { name: 'Add passkey' });
  const add = within(dialog).getByRole('button', { name: 'Add passkey' });
  expect(add).toBeDisabled();
  fireEvent.change(within(dialog).getByLabelText('Current password'), {
    target: { value: 'private password' },
  });
  fireEvent.click(add);
  expect(performPasskey).toHaveBeenCalledWith(
    '/admin/passkeys/options',
    '/admin/passkeys',
    true,
    { operation: 'passkey_options', password: 'private password' },
  );
  expect(
    within(dialog).getByRole('button', { name: 'Please wait…' }),
  ).toBeDisabled();
  fireEvent.submit(dialog.querySelector('form')!);
  expect(performPasskey).toHaveBeenCalledTimes(1);
  reject(new Error('cancelled'));
  expect(await within(dialog).findByRole('alert')).toHaveTextContent(
    'Unable to use this passkey.',
  );
  expect(faqRequest).not.toHaveBeenCalled();
});

it('requires password confirmation for the selected key and clears it when reopening another action', async () => {
  vi.mocked(faqRequest).mockRejectedValue(new Error('incorrect password'));
  render(<Passkeys {...props} />);
  fireEvent.click(
    screen.getByRole('button', { name: 'Actions for passkey #7' }),
  );
  fireEvent.click(screen.getByRole('button', { name: 'Delete' }));
  expect(faqRequest).not.toHaveBeenCalled();
  const dialog = screen.getByRole('dialog', { name: 'Remove passkey' });
  expect(within(dialog).getByText('Passkey #7')).toBeVisible();
  const remove = within(dialog).getByRole('button', { name: 'Delete' });
  expect(remove).toBeDisabled();
  fireEvent.change(within(dialog).getByLabelText('Current password'), {
    target: { value: 'private password' },
  });
  fireEvent.click(remove);
  await waitFor(() =>
    expect(faqRequest).toHaveBeenCalledWith('/admin/passkeys/7/delete', {
      password: 'private password',
    }),
  );
  expect(await within(dialog).findByRole('alert')).toBeVisible();
  fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel' }));
  expect(
    screen.getByRole('heading', { name: 'auth.passkey_yours' }),
  ).toHaveFocus();
  fireEvent.click(screen.getByRole('button', { name: 'Add passkey' }));
  const reopened = screen.getByRole('dialog', { name: 'Add passkey' });
  expect(within(reopened).getByLabelText('Current password')).toHaveValue('');
  expect(within(reopened).queryByRole('alert')).not.toBeInTheDocument();
  expect(performPasskey).not.toHaveBeenCalled();
});
