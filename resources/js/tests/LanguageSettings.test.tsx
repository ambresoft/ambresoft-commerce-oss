import { fireEvent, render, screen, within } from '@testing-library/react';
import { router } from '@inertiajs/react';
import { beforeEach, expect, it, vi } from 'vitest';
import Languages from '../pages/Admin/Settings/Languages';
import Translations from '../pages/Admin/Settings/Translations';
import Header from '../components/admin/Header';
import type { SharedProps } from '../types/page';
import { english } from './translations';
import { choose, options } from './select';

const shared = vi.hoisted(() => ({ props: {} as SharedProps }));
vi.mock('@inertiajs/react', async (original) => ({
  ...(await original<typeof import('@inertiajs/react')>()),
  usePage: () => ({ url: '/admin', props: shared.props }),
  Head: () => null,
}));
const strings = {
  'languages.title': 'Languages',
  'languages.translations': 'Translations',
  'languages.help': 'Catalog languages only',
  'languages.active': 'Active catalog languages',
  'languages.default': 'System default',
  'languages.strict_hint': 'Complete every active language',
  'languages.dictionary_help': 'Keep named placeholders',
  'languages.string_count': ':count strings',
  'languages.pagination': 'Dictionary pages',
  'languages.original': 'Shipped wording',
  'languages.overridden': 'Customized',
  'languages.restore': 'Restore original',
};
const shell = { navigation: [], breadcrumbs: [] };
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
    capabilities: { 'languages.manage': true },
    i18n: {
      locale: 'en',
      direction: 'ltr',
      strings: { ...english, ...strings },
      locales: [
        { code: 'en', name: 'English' },
        { code: 'el', name: 'Ελληνικά' },
      ],
    },
    links: { logout: '/logout' } as SharedProps['links'],
  };
});

it('submits active catalog languages and an explicit active default', () => {
  const request = vi.spyOn(router, 'put').mockImplementation(() => undefined);
  render(
    <Languages
      {...shell}
      languages={[
        { code: 'en', name: 'English', active: true, default: true },
        { code: 'el', name: 'Ελληνικά', active: true, default: false },
      ]}
    />,
  );
  expect(
    screen.queryByRole('link', { name: 'Translations' }),
  ).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('checkbox', { name: /English/ }));
  choose(screen.getByLabelText('System default'), 'Ελληνικά');
  fireEvent.click(screen.getByRole('button', { name: english['common.save'] }));
  expect(request.mock.calls[0][0]).toBe('/admin/settings/languages');
  expect(request.mock.calls[0][1]).toEqual({ active: ['el'], default: 'el' });
});

it('shows only active catalog languages in the system-default selector', () => {
  render(
    <Languages
      {...shell}
      languages={[
        { code: 'en', name: 'English', active: true, default: true },
        { code: 'el', name: 'Ελληνικά', active: false, default: false },
        { code: 'fr', name: 'Français', active: true, default: false },
      ]}
    />,
  );
  const selector = screen.getByLabelText('System default');
  const names = () => {
    const names = options(selector).map((option) => option.textContent);
    fireEvent.keyDown(selector, { key: 'Escape' });
    return names;
  };
  expect(names()).toEqual(['English', 'Français']);
  fireEvent.click(screen.getByRole('checkbox', { name: /Ελληνικά/ }));
  expect(names()).toEqual(['English', 'Ελληνικά', 'Français']);
});

it('shows only active languages in the top-bar language menu and persists the choice', () => {
  const request = vi.spyOn(router, 'patch').mockImplementation(() => undefined);
  render(<Header onOpenNavigation={vi.fn()} />);
  const trigger = screen.getByRole('button', { name: /Interface language/ });
  expect(trigger).toHaveAttribute('aria-expanded', 'false');
  fireEvent.click(trigger);
  expect(trigger).toHaveAttribute('aria-expanded', 'true');
  const menu = document.getElementById(trigger.getAttribute('aria-controls')!)!;
  expect(
    within(menu)
      .getAllByRole('button')
      .map((option) => option.getAttribute('lang')),
  ).toEqual(['en', 'el']);
  fireEvent.click(within(menu).getByRole('button', { name: 'Ελληνικά' }));
  expect(request.mock.calls[0][0]).toBe('/admin/locale');
  expect(request.mock.calls[0][1]).toMatchObject({ locale: 'el' });
  expect(trigger).toHaveAttribute('aria-expanded', 'false');
});

it('puts a view-storefront link, opening in a new tab, before the theme switcher', () => {
  render(<Header onOpenNavigation={vi.fn()} />);
  const link = screen.getAllByRole('link', { name: /View storefront/ })[0];
  expect(link).toHaveAttribute('href', '/');
  expect(link).toHaveAttribute('target', '_blank');
  expect(link).toHaveAttribute('rel', 'noopener noreferrer');
  const theme = screen.getByRole('button', { name: /Dark theme/ });
  // The storefront link comes first in the toolbar, right before the theme switcher.
  expect(
    link.compareDocumentPosition(theme) & Node.DOCUMENT_POSITION_FOLLOWING,
  ).toBeTruthy();
});

it('edits an exact dictionary key and explicitly requests restoration', () => {
  const request = vi.spyOn(router, 'put').mockImplementation(() => undefined);
  render(
    <Translations
      {...shell}
      languages={[{ code: 'el', name: 'Ελληνικά' }]}
      filters={{ locale: 'el', q: '' }}
      rows={[
        {
          key: 'common.save',
          original: 'Αποθήκευση',
          value: 'Προσαρμογή',
          overridden: true,
        },
      ]}
      page={1}
      lastPage={1}
      total={1}
    />,
  );
  fireEvent.change(screen.getByLabelText('common.save'), {
    target: { value: 'Νέα διατύπωση' },
  });
  fireEvent.click(screen.getByRole('button', { name: english['common.save'] }));
  expect(request.mock.calls[0][1]).toEqual({
    key: 'common.save',
    locale: 'el',
    value: 'Νέα διατύπωση',
    restore: false,
  });
  fireEvent.click(screen.getByRole('button', { name: 'Restore original' }));
  expect(request.mock.calls[1][1]).toMatchObject({
    key: 'common.save',
    locale: 'el',
    restore: true,
  });
});

it('switches the admin between the light default and the dark theme', () => {
  render(<Header onOpenNavigation={vi.fn()} />);
  const toggle = screen.getByRole('button', { name: 'Dark theme' });
  expect(toggle).toHaveAttribute('aria-pressed', 'false');
  expect(document.documentElement).not.toHaveAttribute('data-theme');
  fireEvent.click(toggle);
  expect(toggle).toHaveAttribute('aria-pressed', 'true');
  expect(document.documentElement).toHaveAttribute('data-theme', 'dark');
  expect(localStorage.getItem('cms-theme')).toBe('dark');
  fireEvent.click(toggle);
  expect(document.documentElement).not.toHaveAttribute('data-theme');
  expect(localStorage.getItem('cms-theme')).toBe('light');
});
