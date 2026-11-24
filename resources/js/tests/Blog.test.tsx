import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { expect, it, vi } from 'vitest';
import { BlogLanding, BlogPost } from '../storefront/themes/Default/Blog';
import type { BlogData, BlogPostData, PublicBlock } from '../storefront/types';

vi.mock('../lib/useTranslation', () => ({
  useTranslation: () => (key: string) => key,
}));
vi.mock('@inertiajs/react', () => ({
  usePage: () => ({
    props: { i18n: { locale: 'en' }, preferences: { timezone: 'UTC' } },
  }),
  Link: ({
    href,
    children,
    ...rest
  }: {
    href: string;
    children: ReactNode;
  }) => (
    <a href={href} {...rest}>
      {children}
    </a>
  ),
}));

const block = (content: Record<string, unknown>) =>
  ({ id: 'b', template: 'main_content', content }) as unknown as PublicBlock;

it('lists the blog posts with their links, dates and excerpts', () => {
  const blog: BlogData = {
    id: '1',
    name: 'News',
    content: '<p>About us</p>',
    url: '/news',
    page: 1,
    last_page: 1,
    posts: [
      {
        id: '5',
        name: 'Launch',
        url: '/news/launch',
        excerpt: 'Short story',
        published_at: '2026-10-01T10:00:00Z',
        cover: { url: '/c.png', width: 10, height: 10 },
        cover_alt: '',
      },
    ],
  };
  render(<BlogLanding block={block({ blog })} />);
  expect(screen.getByRole('heading', { level: 1, name: 'News' })).toBeVisible();
  expect(screen.getByRole('link', { name: 'Launch' })).toHaveAttribute(
    'href',
    '/news/launch',
  );
  expect(screen.getByText('Short story')).toBeVisible();
  expect(screen.getByText('Oct 1, 2026')).toHaveAttribute(
    'datetime',
    '2026-10-01T10:00:00Z',
  );
});

it('shows a post with its cover, tags and a link back to its blog', () => {
  const post: BlogPostData = {
    id: '5',
    name: 'Launch',
    content: '<p>Long story</p>',
    excerpt: '',
    url: '/news/launch',
    published_at: null,
    cover: { url: '/c.png', width: 10, height: 10 },
    cover_alt: 'A beach',
    tags: ['news', 'Summer'],
    blog: { name: 'News', url: '/news' },
  };
  render(<BlogPost block={block({ post })} />);
  expect(screen.getByRole('img', { name: 'A beach' })).toBeVisible();
  expect(screen.getByText('Long story')).toBeVisible();
  expect(
    screen.getByRole('list', { name: 'storefront.blog_tags' }),
  ).toHaveTextContent('newsSummer');
  expect(screen.getAllByRole('link', { name: 'News' })[0]).toHaveAttribute(
    'href',
    '/news',
  );
  expect(screen.queryByRole('time')).toBeNull();
});
