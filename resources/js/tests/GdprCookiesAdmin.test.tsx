import { fireEvent, render, screen } from '@testing-library/react';
import { router } from '@inertiajs/react';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import GdprCookies, {
  type GdprCookiesProps,
} from '../../../modules/ambresoft/gdpr-cookies/resources/js/Admin';

vi.mock('../lib/useTranslation', () => ({
  useTranslation: () => (key: string) => key,
}));
vi.mock('../layouts/admin/AdminLayout', () => ({
  default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));
vi.mock('@inertiajs/react', async (original) => ({
  ...(await original<typeof import('@inertiajs/react')>()),
  Link: ({ children, ...props }: AnchorHTMLAttributes<HTMLAnchorElement>) => (
    <a {...props}>{children}</a>
  ),
  usePage: () => ({
    props: { errors: {}, preferences: {} },
    url: '/admin/addons/ambresoft/gdpr-cookies',
  }),
}));

const ui = (key: string) => 'cms-gdpr::gdpr.' + key;
const category = { name: '', description: '' };
const props: GdprCookiesProps = {
  navigation: [],
  breadcrumbs: [],
  settings: {
    version: 1,
    consent_months: 6,
    privacy_url: '',
    reopen_button: true,
    initial_categories: {
      preferences: false,
      statistics: false,
      marketing: false,
    },
    lock_version: 4,
  },
  languages: [
    { code: 'en', name: 'English' },
    { code: 'el', name: 'Ελληνικά' },
  ],
  texts: {
    en: { title: '', body: '', about: '' },
    el: { title: '', body: '', about: '' },
  },
  categories: Object.fromEntries(
    ['necessary', 'preferences', 'statistics', 'marketing'].map((c) => [
      c,
      { en: category, el: category },
    ]),
  ),
  cookies: [
    {
      id: 3,
      category: 'statistics',
      name: '_ga',
      provider: 'Google',
      purposes: { en: 'Visitors', el: 'Επισκέπτες' },
      expiries: { en: '2 years', el: '2 έτη' },
    },
  ],
  scripts: [],
  defaults: {
    title: 'We value your privacy',
    body: 'b',
    about: 'a',
    categories: Object.fromEntries(
      ['necessary', 'preferences', 'statistics', 'marketing'].map((c) => [
        c,
        { name: c, description: c + ' text' },
      ]),
    ),
  },
  consents: { total: 9, recent: { reject_all: 2 } },
  lookup: null,
  canManage: true,
  canScripts: true,
};
beforeEach(() => vi.restoreAllMocks());

it('saves texts per language without touching the other language', () => {
  const put = vi.spyOn(router, 'put').mockImplementation(() => undefined);
  render(<GdprCookies {...props} />);
  expect(screen.getByText(/We value your privacy/)).toBeInTheDocument();
  fireEvent.change(screen.getByLabelText(ui('banner_title')), {
    target: { value: 'Cookies' },
  });
  fireEvent.click(screen.getByRole('button', { name: ui('save') }));
  expect(put).toHaveBeenCalledWith(
    '/admin/addons/ambresoft/gdpr-cookies',
    expect.objectContaining({
      lock_version: 4,
      consent_months: 6,
      texts: {
        en: { title: 'Cookies', body: '', about: '' },
        el: { title: '', body: '', about: '' },
      },
    }),
    expect.anything(),
  );
});

it('adds a consented script through its own save', () => {
  const put = vi.spyOn(router, 'put').mockImplementation(() => undefined);
  render(<GdprCookies {...props} />);
  fireEvent.click(screen.getByRole('tab', { name: ui('tab_scripts') }));
  fireEvent.click(screen.getByRole('button', { name: ui('add_script') }));
  fireEvent.change(screen.getByLabelText(ui('script_name')), {
    target: { value: 'Pixel' },
  });
  fireEvent.change(screen.getByLabelText(ui('script_code')), {
    target: { value: '<script>x()</script>' },
  });
  fireEvent.click(screen.getByRole('button', { name: ui('save_scripts') }));
  expect(put).toHaveBeenCalledWith(
    '/admin/addons/ambresoft/gdpr-cookies/scripts',
    {
      scripts: [
        {
          id: null,
          category: 'statistics',
          name: 'Pixel',
          code: '<script>x()</script>',
          enabled: true,
        },
      ],
    },
    expect.anything(),
  );
});

it('hides scripts from staff without the scripts capability and shows the consent log', () => {
  render(<GdprCookies {...props} scripts={null} canScripts={false} />);
  fireEvent.click(screen.getByRole('tab', { name: ui('tab_scripts') }));
  expect(screen.getByText(ui('scripts_locked'))).toBeInTheDocument();
  expect(screen.queryByLabelText(ui('script_code'))).toBeNull();
  fireEvent.click(screen.getByRole('tab', { name: ui('tab_consents') }));
  expect(screen.getByText('9')).toBeInTheDocument();
});

it('saves initial category switches independently of localized category texts', () => {
  const put = vi.spyOn(router, 'put').mockImplementation(() => undefined);
  render(<GdprCookies {...props} />);
  fireEvent.click(screen.getByRole('tab', { name: ui('tab_categories') }));
  fireEvent.click(screen.getByRole('checkbox', { name: 'marketing' }));
  fireEvent.click(screen.getByRole('button', { name: ui('save') }));
  expect(put).toHaveBeenCalledWith(
    '/admin/addons/ambresoft/gdpr-cookies',
    expect.objectContaining({
      initial_categories: {
        preferences: false,
        statistics: false,
        marketing: true,
      },
      categories: props.categories,
    }),
    expect.anything(),
  );
});
