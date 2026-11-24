import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import Banner, {
  type ConsentConfig,
} from '../../../modules/ambresoft/gdpr-cookies/resources/js/Banner';
import GoogleAnalytics from '../storefront/components/GoogleAnalytics';
import { writeConsent } from '../storefront/consent';

let props: Record<string, unknown> = {};
vi.mock('@inertiajs/react', async (original) => ({
  ...(await original<typeof import('@inertiajs/react')>()),
  usePage: () => ({ props, url: '/' }),
}));

const texts = Object.fromEntries(
  [
    'title',
    'body',
    'about',
    'accept_all',
    'reject_all',
    'settings',
    'save',
    'consent_tab',
    'details_tab',
    'about_tab',
    'always_active',
    'cookie_name',
    'cookie_provider',
    'cookie_purpose',
    'cookie_expiry',
    'privacy_link',
    'reopen',
    'consent_id',
    'consent_date',
    'close',
    'no_cookies',
    'dialog',
  ].map((k) => [k, k]),
);
const config: ConsentConfig = {
  version: 3,
  months: 6,
  privacyUrl: '/privacy',
  reopen: true,
  recordUrl: '/storefront/gdpr-cookies/consents',
  texts,
  categories: [
    {
      key: 'necessary',
      name: 'Necessary',
      description: 'n',
      cookies: [
        { name: 'cart', provider: 'Store', purpose: 'Cart', expiry: '30 days' },
      ],
    },
    { key: 'preferences', name: 'Preferences', description: 'p', cookies: [] },
    {
      key: 'statistics',
      name: 'Statistics',
      description: 's',
      cookies: [
        {
          name: '_ga',
          provider: 'Google',
          purpose: 'Visitors',
          expiry: '2 years',
        },
      ],
    },
    { key: 'marketing', name: 'Marketing', description: 'm', cookies: [] },
  ],
  scripts: [
    {
      id: 1,
      category: 'statistics',
      code: '<script>window.__stats = 1</script>',
    },
    { id: 2, category: 'marketing', code: '<script>window.__ads = 1</script>' },
  ],
};
const fetchMock = vi.fn<(url: string, init?: RequestInit) => Promise<Response>>(
  async () => new Response(null, { status: 204 }),
);
const ran = () =>
  Array.from(document.head.querySelectorAll('script[data-consent-script]')).map(
    (s) => (s as HTMLElement).dataset.consentScript,
  );
const clearCookies = () =>
  document.cookie.split('; ').forEach((c) => {
    const name = c.split('=')[0];
    if (name) document.cookie = `${name}=; path=/; max-age=0`;
  });

beforeEach(() => {
  props = { storefrontConsent: config };
  HTMLDialogElement.prototype.showModal ??= function (this: HTMLDialogElement) {
    this.setAttribute('open', '');
  };
  HTMLDialogElement.prototype.close ??= function (this: HTMLDialogElement) {
    this.removeAttribute('open');
  };
  vi.stubGlobal('fetch', fetchMock);
});
afterEach(() => {
  document.head.querySelectorAll('script').forEach((s) => s.remove());
  delete (window as { __cmsConsentScripts?: unknown }).__cmsConsentScripts;
  delete (window as { dataLayer?: unknown }).dataLayer;
  delete (window as { gtag?: unknown }).gtag;
  clearCookies();
  fetchMock.mockClear();
  vi.unstubAllGlobals();
});

const banner = () => screen.getByRole('region', { name: 'title' });

it('asks before anything optional runs and offers accept and reject alike on the first layer', () => {
  render(<Banner />);
  const accept = within(banner()).getByRole('button', { name: 'accept_all' });
  const reject = within(banner()).getByRole('button', { name: 'reject_all' });
  expect(accept.className).toBe(reject.className);
  expect(ran()).toEqual([]);
  expect(
    within(banner()).getByRole('link', { name: 'privacy_link' }),
  ).toHaveAttribute('href', '/privacy');
});

it('rejecting stores and records the choice, runs nothing and leaves a way back', () => {
  render(<Banner />);
  fireEvent.click(within(banner()).getByRole('button', { name: 'reject_all' }));
  expect(document.cookie).toMatch(/cms_consent=[0-9a-f-]{36}\.3\.000\.\d{10}/);
  expect(JSON.parse(String(fetchMock.mock.calls[0][1]?.body))).toMatchObject({
    version: 3,
    preferences: false,
    statistics: false,
    marketing: false,
    action: 'reject_all',
  });
  expect(ran()).toEqual([]);
  expect(screen.queryByRole('region', { name: 'title' })).toBeNull();
  expect(screen.getByRole('button', { name: 'reopen' })).toBeInTheDocument();
});

it('accepting runs the consented scripts once', () => {
  const { rerender } = render(<Banner />);
  fireEvent.click(within(banner()).getByRole('button', { name: 'accept_all' }));
  rerender(<Banner />);
  expect(ran()).toEqual(['1', '2']);
});

it('chooses by category with necessary cookies always on', () => {
  render(<Banner />);
  fireEvent.click(within(banner()).getByRole('button', { name: /settings/ }));
  const necessary = screen.getByRole('switch', {
    name: 'Necessary: always_active',
  });
  expect(necessary).toBeDisabled();
  expect(necessary).toHaveAttribute('aria-checked', 'true');
  expect(screen.getByRole('switch', { name: 'Statistics' })).toHaveAttribute(
    'aria-checked',
    'false',
  );
  fireEvent.click(screen.getByRole('switch', { name: 'Statistics' }));
  fireEvent.click(
    screen.getByRole('button', { name: /Statistics/, expanded: false }),
  );
  expect(screen.getByRole('cell', { name: '_ga' })).toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'save' }));
  expect(document.cookie).toMatch(/cms_consent=[0-9a-f-]{36}\.3\.010\./);
  expect(JSON.parse(String(fetchMock.mock.calls[0][1]?.body)).action).toBe(
    'custom',
  );
  expect(ran()).toEqual(['1']);
});

it('withdrawing deletes that category’s cookies and reloads so running scripts stop', () => {
  writeConsent(config, {
    preferences: true,
    statistics: true,
    marketing: false,
  });
  document.cookie = '_ga=GA1.1.1.2; path=/';
  const reload = vi.fn();
  vi.stubGlobal('location', {
    ...window.location,
    reload,
    hostname: 'localhost',
    protocol: 'http:',
  });
  render(<Banner />);
  fireEvent.click(screen.getByRole('button', { name: 'reopen' }));
  fireEvent.click(screen.getByRole('switch', { name: 'Statistics' }));
  fireEvent.click(screen.getByRole('button', { name: 'save' }));
  expect(document.cookie).not.toContain('_ga=');
  expect(reload).toHaveBeenCalledOnce();
});

it('Google Analytics waits for statistics consent and sends Consent Mode first', () => {
  props = {
    storefrontConsent: config,
    storefrontAnalytics: { measurementId: 'G-ABC123XYZ9', ecommerce: false },
  };
  render(<GoogleAnalytics />);
  expect(document.head.querySelector('script[data-ga]')).toBeNull();
  act(() => {
    writeConsent(config, {
      preferences: false,
      statistics: true,
      marketing: false,
    });
  });
  expect(document.head.querySelector('script[data-ga]')).not.toBeNull();
  const calls = (
    window as unknown as { dataLayer: IArguments[] }
  ).dataLayer.map((a) => Array.from(a));
  expect(calls[0]).toEqual([
    'consent',
    'default',
    expect.objectContaining({
      analytics_storage: 'denied',
      ad_storage: 'denied',
    }),
  ]);
  expect(calls[2]).toEqual([
    'consent',
    'update',
    expect.objectContaining({
      analytics_storage: 'granted',
      ad_storage: 'denied',
    }),
  ]);
});

it('creates a valid consent ID where crypto.randomUUID is unavailable (plain HTTP)', () => {
  vi.stubGlobal('crypto', { getRandomValues: (a: Uint8Array) => a.fill(7) });
  const choice = writeConsent(config, {
    preferences: false,
    statistics: false,
    marketing: false,
  });
  expect(choice.id).toMatch(
    /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/,
  );
  expect(document.cookie).toContain('cms_consent=' + choice.id + '.3.000.');
});

it('uses configured initial switches without running scripts and lets visitors turn them off', () => {
  props = {
    storefrontConsent: {
      ...config,
      initialCategories: {
        preferences: false,
        statistics: false,
        marketing: true,
      },
    },
  };
  render(<Banner />);
  expect(ran()).toEqual([]);
  expect(document.cookie).not.toContain('cms_consent=');
  fireEvent.click(within(banner()).getByRole('button', { name: /settings/ }));
  const marketing = screen.getByRole('switch', { name: 'Marketing' });
  expect(marketing).toHaveAttribute('aria-checked', 'true');
  expect(ran()).toEqual([]);
  fireEvent.click(marketing);
  fireEvent.click(screen.getByRole('button', { name: 'save' }));
  expect(document.cookie).toMatch(/\.3\.000\./);
  expect(ran()).toEqual([]);
});

it('preserves a saved rejection instead of applying new initial switches', () => {
  writeConsent(config, {
    preferences: false,
    statistics: false,
    marketing: false,
  });
  props = {
    storefrontConsent: {
      ...config,
      initialCategories: {
        preferences: true,
        statistics: true,
        marketing: true,
      },
    },
  };
  render(<Banner />);
  fireEvent.click(screen.getByRole('button', { name: 'reopen' }));
  expect(screen.getByRole('switch', { name: 'Marketing' })).toHaveAttribute(
    'aria-checked',
    'false',
  );
  expect(ran()).toEqual([]);
});

it('reject all overrides configured initial switches', () => {
  props = {
    storefrontConsent: {
      ...config,
      initialCategories: {
        preferences: true,
        statistics: true,
        marketing: true,
      },
    },
  };
  render(<Banner />);
  fireEvent.click(within(banner()).getByRole('button', { name: 'reject_all' }));
  expect(document.cookie).toMatch(/\.3\.000\./);
  expect(ran()).toEqual([]);
});
