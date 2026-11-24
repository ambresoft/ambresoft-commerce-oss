import { fireEvent, render, screen, within } from '@testing-library/react';
import { router } from '@inertiajs/react';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import SitemapPage, {
  type SitemapProps,
} from '../../../modules/ambresoft/sitemap/resources/js/Sitemap';
import { choose } from './select';

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
    url: '/admin/sitemap',
  }),
}));

const section = {
  enabled: true,
  frequency: 'day',
  status: 'completed',
  error: null,
  eligible: 1,
  draft: 1,
  published: 1,
  last_refresh_at: '2026-01-01T00:00:00Z',
  next_refresh_at: '2026-01-02T00:00:00Z',
};
const props: SitemapProps = {
  navigation: [],
  breadcrumbs: [],
  version: 7,
  pending: true,
  sections: { homepage: section, pages: section },
  published: { id: 'version', at: '2026-01-01T00:00:00Z', count: 2 },
  publishedUrl: 'https://store.example/sitemap.xml',
  tab: 'overview',
  activeSection: 'pages',
  entries: {
    items: [
      {
        id: 9,
        source_id: '1',
        canonical_url: 'https://store.example/about',
        url_override: null,
        inclusion_override: null,
        available: true,
        lastmod: '2026-01-01T00:00:00Z',
        warning: null,
      },
    ],
    page: 1,
    last_page: 1,
    total: 1,
  },
  canManage: true,
  canPublish: true,
};
beforeEach(() => vi.restoreAllMocks());

it('collects section settings until Save and does not publish them', () => {
  const put = vi.spyOn(router, 'put').mockImplementation(() => undefined);
  const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
  render(<SitemapPage {...props} />);
  fireEvent.click(
    screen.getByRole('switch', {
      name: 'cms-sitemap::ui.enabled · cms-sitemap::ui.section_homepage',
    }),
  );
  expect(put).not.toHaveBeenCalled();
  fireEvent.click(
    screen.getByRole('button', { name: 'cms-sitemap::ui.save_settings' }),
  );
  expect(put).toHaveBeenCalledWith(
    '/admin/sitemap/settings',
    expect.objectContaining({
      version: 7,
      sections: expect.objectContaining({
        homepage: { enabled: false, frequency: 'day' },
      }),
    }),
    expect.anything(),
  );
  expect(post).not.toHaveBeenCalled();
});

it('saves only the current draft rows and disables publication while edits are unsaved', () => {
  const put = vi.spyOn(router, 'put').mockImplementation(() => undefined);
  const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
  render(<SitemapPage {...props} tab="urls" />);
  choose(
    screen.getByRole('combobox', {
      name: 'cms-sitemap::ui.include · https://store.example/about',
    }),
    'cms-sitemap::ui.excluded',
  );
  fireEvent.change(
    screen.getByRole('textbox', { name: 'cms-sitemap::ui.url · 1' }),
    { target: { value: 'https://store.example/about' } },
  );
  expect(
    screen.getByRole('button', { name: 'cms-sitemap::ui.publish' }),
  ).toBeDisabled();
  expect(
    screen.getByRole('button', { name: 'cms-sitemap::ui.generate' }),
  ).toBeDisabled();
  fireEvent.click(
    screen.getByRole('button', { name: 'cms-sitemap::ui.save_draft' }),
  );
  expect(put).toHaveBeenCalledWith(
    '/admin/sitemap/draft',
    {
      version: 7,
      entries: [{ id: 9, include: false, url: 'https://store.example/about' }],
    },
    expect.anything(),
  );
  expect(post).not.toHaveBeenCalled();
});

it('queues draft generation through its own action', () => {
  const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
  render(<SitemapPage {...props} />);
  fireEvent.click(
    screen.getByRole('button', { name: 'cms-sitemap::ui.generate' }),
  );
  expect(post).toHaveBeenCalledWith(
    '/admin/sitemap/generate',
    { version: 7 },
    expect.anything(),
  );
});

it('requires a separate modal confirmation before publishing', () => {
  const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
  render(<SitemapPage {...props} />);
  fireEvent.click(
    screen.getByRole('button', { name: 'cms-sitemap::ui.publish' }),
  );
  expect(post).not.toHaveBeenCalled();
  const dialog = screen.getByRole('dialog', {
    name: 'cms-sitemap::ui.publish_title',
  });
  fireEvent.click(
    within(dialog).getByRole('button', { name: 'cms-sitemap::ui.publish' }),
  );
  expect(post).toHaveBeenCalledWith(
    '/admin/sitemap/publish',
    { version: 7, confirmed: true },
    expect.anything(),
  );
});

it('cancels publication without sending a request and keeps the live URL visible', () => {
  const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
  render(<SitemapPage {...props} />);
  expect(
    screen.getByRole('link', { name: props.publishedUrl }),
  ).toHaveAttribute('href', props.publishedUrl);
  fireEvent.click(
    screen.getByRole('button', { name: 'cms-sitemap::ui.publish' }),
  );
  fireEvent.click(
    within(screen.getByRole('dialog')).getByRole('button', {
      name: 'common.cancel',
    }),
  );
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  expect(post).not.toHaveBeenCalled();
});

it('keeps view-only users read-only and shows server action errors', () => {
  render(
    <SitemapPage
      {...props}
      canManage={false}
      canPublish={false}
      errors={{ sitemap: 'Refresh the draft before publishing.' }}
    />,
  );
  expect(
    screen.queryByRole('button', { name: 'cms-sitemap::ui.publish' }),
  ).not.toBeInTheDocument();
  expect(
    screen.queryByRole('button', { name: 'cms-sitemap::ui.generate' }),
  ).not.toBeInTheDocument();
  expect(
    screen
      .getAllByRole('switch')
      .every((control) => control.hasAttribute('disabled')),
  ).toBe(true);
  expect(
    screen.getByText('Refresh the draft before publishing.'),
  ).toBeInTheDocument();
});
