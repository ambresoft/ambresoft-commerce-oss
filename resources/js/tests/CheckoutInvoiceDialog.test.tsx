import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import CheckoutInvoiceDialog from '../storefront/themes/Default/CheckoutInvoiceDialog';

vi.mock('../lib/useTranslation', () => ({
  useTranslation: () => (key: string) => key,
}));
afterEach(() => vi.unstubAllGlobals());

it('aborts a pending lookup when closed and ignores a late result', async () => {
  let complete!: (value: unknown) => void;
  const fetchMock = vi.fn<typeof fetch>(
    () =>
      new Promise<Response>((resolve) => {
        complete = (value) => resolve(value as Response);
      }),
  );
  vi.stubGlobal('fetch', fetchMock);
  const approved = vi.fn();
  const { unmount } = render(
    <CheckoutInvoiceDialog
      countries={[{ code: 'GB', name: 'United Kingdom' }]}
      initial={{ country_code: 'GB', vat_number: '123456789' }}
      signedIn={false}
      onClose={() => {}}
      onSignIn={() => {}}
      onVerified={approved}
    />,
  );
  fireEvent.click(screen.getByRole('button', { name: 'checkout.verify' }));
  expect(
    screen.getByRole('button', { name: 'checkout.checking' }),
  ).toBeDisabled();
  expect(
    screen.getByRole('button', { name: 'checkout.checking' }),
  ).toHaveAttribute('aria-busy', 'true');
  fireEvent.click(screen.getByRole('button', { name: 'checkout.checking' }));
  expect(fetchMock).toHaveBeenCalledTimes(1);
  const signal = fetchMock.mock.calls[0][1]!.signal!;
  unmount();
  expect(signal.aborted).toBe(true);
  await act(async () =>
    complete({
      ok: true,
      json: async () => ({
        verification: {
          country_code: 'GB',
          vat_number: '123456789',
          business_name: null,
        },
      }),
    }),
  );
  expect(approved).not.toHaveBeenCalled();
});

it('allows Greek guests to verify when the merchant selected VIES or the basic check', async () => {
  const approved = vi.fn();
  vi.stubGlobal(
    'fetch',
    vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({
        verification: {
          country_code: 'GR',
          vat_number: '094259216',
          business_name: null,
          provider: 'local',
        },
      }),
    }),
  );
  render(
    <CheckoutInvoiceDialog
      countries={[{ code: 'GR', name: 'Greece' }]}
      initial={{ country_code: 'GR', vat_number: '094259216' }}
      signedIn={false}
      greekSignIn={false}
      onClose={() => {}}
      onSignIn={() => {}}
      onVerified={approved}
    />,
  );
  expect(screen.queryByText('checkout.sign_in')).toBeNull();
  await act(async () =>
    fireEvent.click(screen.getByRole('button', { name: 'checkout.verify' })),
  );
  expect(approved).toHaveBeenCalledWith({
    country_code: 'GR',
    vat_number: '094259216',
    business_name: null,
    provider: 'local',
  });
});

it('searches country names and codes and closes only the menu on Escape', () => {
  const close = vi.fn();
  render(
    <CheckoutInvoiceDialog
      countries={[
        { code: 'GR', name: 'Greece' },
        { code: 'GB', name: 'United Kingdom' },
        { code: 'DE', name: 'Germany' },
      ]}
      initial={{ country_code: 'GR', vat_number: '' }}
      signedIn
      greekSignIn={false}
      onClose={close}
      onSignIn={() => {}}
      onVerified={() => {}}
    />,
  );
  const country = screen.getByRole('combobox', { name: 'checkout.country' });
  fireEvent.click(country);
  let search = screen.getByRole('combobox', { name: 'common.search_options' });
  fireEvent.change(search, { target: { value: 'united' } });
  expect(screen.queryByRole('option', { name: 'Greece' })).toBeNull();
  expect(screen.getByRole('option', { name: 'United Kingdom' })).toBeTruthy();
  fireEvent.keyDown(search, { key: 'Enter' });
  expect(country).toHaveTextContent('United Kingdom');
  expect(country).toHaveFocus();
  fireEvent.click(country);
  search = screen.getByRole('combobox', { name: 'common.search_options' });
  fireEvent.change(search, { target: { value: 'DE' } });
  expect(screen.getByRole('option', { name: 'Germany' })).toBeTruthy();
  fireEvent.keyDown(search, { key: 'Escape' });
  expect(screen.queryByRole('listbox')).toBeNull();
  expect(close).not.toHaveBeenCalled();
  expect(screen.getByRole('dialog')).toBeTruthy();
});
