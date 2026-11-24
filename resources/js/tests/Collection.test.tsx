import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { Collection } from '../storefront/themes/Default/parts';
import type { PublicBlock } from '../storefront/types';

vi.mock('../lib/useTranslation', () => ({
  useTranslation: () => (key: string) => key,
}));
vi.mock('@inertiajs/react', async (original) => ({
  ...(await original<typeof import('@inertiajs/react')>()),
  usePage: () => ({
    props: { i18n: { locale: 'en' }, preferences: { timezone: 'UTC' } },
  }),
}));

const block = (infinite: boolean) =>
  ({
    id: 'b',
    template: 'category_carousel',
    title: '',
    content: {
      display: { infinite_scroll: infinite, navigation: 'outside' },
      items: ['One', 'Two', 'Three'].map((name) => ({
        id: name,
        name,
        url: '/' + name.toLowerCase(),
        image: null,
      })),
    },
  }) as unknown as PublicBlock;

// jsdom has no layout: three 300px items in a 400px track, so there is something to scroll.
beforeEach(() => {
  vi.spyOn(HTMLElement.prototype, 'offsetWidth', 'get').mockReturnValue(300);
  vi.spyOn(HTMLElement.prototype, 'clientWidth', 'get').mockReturnValue(400);
});
afterEach(() => vi.restoreAllMocks());

it('adds hidden copies of the first items after the last one when scrolling is infinite', () => {
  const { container } = render(<Collection block={block(true)} />);
  const copies = container.querySelectorAll('.sf-item[data-clone]');
  expect(copies).toHaveLength(3);
  // The copies are for the eye only: out of the accessibility tree and not focusable.
  copies.forEach((copy) => {
    expect(copy).toHaveAttribute('aria-hidden', 'true');
    expect(copy).toHaveAttribute('inert');
  });
  expect(screen.getAllByRole('link', { name: 'One' })).toHaveLength(1);
  // "Next" is never disabled: it keeps moving the same way.
  expect(screen.getByRole('button', { name: 'storefront.next' })).toBeEnabled();
});

it('adds no copies without infinite scrolling, or when everything already fits', () => {
  const { container, unmount } = render(<Collection block={block(false)} />);
  expect(container.querySelectorAll('.sf-item[data-clone]')).toHaveLength(0);
  unmount();
  vi.spyOn(HTMLElement.prototype, 'clientWidth', 'get').mockReturnValue(2000);
  const wide = render(<Collection block={block(true)} />);
  expect(wide.container.querySelectorAll('.sf-item[data-clone]')).toHaveLength(
    0,
  );
});

it('steps forward by one item from the start without jumping back', () => {
  const { container } = render(<Collection block={block(true)} />);
  const track = container.querySelector<HTMLElement>('.sf-items')!;
  const by = vi.fn();
  track.scrollBy = by;
  fireEvent.click(screen.getByRole('button', { name: 'storefront.next' }));
  expect(by).toHaveBeenCalledWith({ left: 300, behavior: 'smooth' });
});

it('shows blog post cards with their date and excerpt when the block asks for them', () => {
  const posts = {
    id: 'b',
    template: 'blog_carousel',
    title: 'From the blog',
    content: {
      display: { show_date: true, show_excerpt: false, per_row: 3 },
      items: [
        {
          id: '5',
          name: 'Launch',
          url: '/news/launch',
          image: null,
          excerpt: 'Short story',
          published_at: '2026-10-01T10:00:00Z',
        },
      ],
    },
  } as unknown as PublicBlock;
  const { container } = render(<Collection block={posts} />);
  expect(screen.getByRole('link', { name: /Launch/ })).toHaveAttribute(
    'href',
    '/news/launch',
  );
  expect(screen.getByText('Oct 1, 2026')).toBeVisible();
  expect(screen.queryByText('Short story')).toBeNull();
  expect(container.querySelector('.sf-blog-items.sf-sized')).not.toBeNull();
});

it('repositions blog arrows when the cover finishes loading at a different size', () => {
  let height = 160;
  vi.spyOn(HTMLElement.prototype, 'offsetHeight', 'get').mockImplementation(
    function (this: HTMLElement) {
      return this.tagName === 'PICTURE' ? height : 0;
    },
  );
  vi.spyOn(HTMLElement.prototype, 'offsetTop', 'get').mockReturnValue(0);
  const posts = block(false);
  posts.template = 'blog_carousel';
  posts.content.items![0].image = {
    url: '/cover.png',
    width: 800,
    height: 500,
  };
  const { container } = render(<Collection block={posts} />);
  const wrap = container.querySelector<HTMLElement>('.sf-carousel-wrap')!;
  expect(wrap.style.getPropertyValue('--sf-blog-arrow-top')).toBe('80px');
  height = 240;
  fireEvent.load(container.querySelector('img')!);
  expect(wrap.style.getPropertyValue('--sf-blog-arrow-top')).toBe('120px');
});

it('shows a full-width split card and article link for one blog post', () => {
  const data = block(false);
  data.template = 'blog_carousel';
  data.content.items = [
    {
      ...data.content.items![0],
      image: { url: '/cover.png', width: 800, height: 500 },
      excerpt: 'Read about our launch',
    },
  ];
  data.content.display = { show_excerpt: true, card_shadow: true };
  const { container, rerender } = render(<Collection block={data} />);
  expect(container.querySelector('.sf-blog-featured')).not.toBeNull();
  expect(container.querySelector('.sf-blog-items')).toHaveClass('sf-single');
  expect(screen.getByText('Read about our launch')).toBeVisible();
  expect(
    screen.getByText('storefront.blog_read_article').closest('a'),
  ).toHaveAttribute('href', '/one');
  expect(
    screen.queryByRole('button', { name: 'storefront.next' }),
  ).not.toBeInTheDocument();
  rerender(
    <Collection
      block={{
        ...data,
        template: 'blog_single',
        content: { ...data.content, items: block(false).content.items },
      }}
    />,
  );
  expect(container.querySelectorAll('.sf-blog-featured')).toHaveLength(1);
});

it('keeps multi-post blog carousels as ordinary cards', () => {
  const data = block(false);
  data.template = 'blog_carousel';
  const { container } = render(<Collection block={data} />);
  expect(container.querySelector('.sf-blog-featured')).toBeNull();
  expect(
    screen.queryByText('storefront.blog_read_article'),
  ).not.toBeInTheDocument();
  expect(container.querySelector('.sf-blog-items')).toHaveClass('sf-carousel');
});

it.each([
  'menu_tiles_carousel',
  'menu_circles_carousel',
  'menu_cards_carousel',
])('renders image menu links in the %s style', (template) => {
  const data = block(false);
  data.template = template;
  data.title = 'Explore';
  data.content.items = [
    {
      id: 'one',
      name: 'Offers',
      url: '/offers',
      image: { url: '/offers.png', width: 100, height: 100 },
    },
    {
      id: 'two',
      name: 'External',
      url: 'https://example.com',
      new_window: true,
      image: null,
    },
    { id: 'three', name: 'Information', url: null, image: null },
  ];
  const { container } = render(<Collection block={data} />);
  expect(screen.getByRole('region', { name: 'Explore' })).toBeVisible();
  expect(screen.getByRole('link', { name: 'Offers' })).toHaveAttribute(
    'href',
    '/offers',
  );
  expect(screen.getByRole('link', { name: 'External' })).toHaveAttribute(
    'rel',
    'noopener noreferrer',
  );
  expect(screen.getByRole('link', { name: 'External' })).toHaveAttribute(
    'target',
    '_blank',
  );
  expect(
    screen.queryByRole('link', { name: 'Information' }),
  ).not.toBeInTheDocument();
  expect(screen.getByText('Information')).toBeVisible();
  expect(
    container.querySelector('.sf-menu-picture img, .sf-category img'),
  ).toHaveAttribute('src', '/offers.png');
  expect(
    container.querySelector('.sf-menu-picture img, .sf-category img'),
  ).toHaveAttribute('alt', '');
  if (template === 'menu_cards_carousel') {
    expect(container.querySelector('.sf-category-items')).toHaveClass(
      'sf-sized',
    );
    expect(
      container.querySelectorAll('.sf-category.sf-card-shadow'),
    ).toHaveLength(3);
  }
  expect(container.querySelector('.sf-menu-carousel')).toHaveClass(
    template === 'menu_cards_carousel'
      ? 'sf-menu-cards'
      : template === 'menu_tiles_carousel'
        ? 'sf-menu-tiles'
        : 'sf-menu-circles',
  );
});

it.each(['coupon_carousel', 'offer_carousel'])(
  'renders %s with the shared controls and accessible terms',
  (template) => {
    const data = block(true);
    data.template = template;
    data.title = 'Your rewards';
    data.content.display = {
      per_row: 4,
      navigation: 'inside',
      drag_scroll: true,
      infinite_scroll: true,
      card_shadow: true,
    };
    data.content.items = [1, 2, 3].map((id) => ({
      id: String(id),
      name: `Reward ${id}`,
      deal: {
        kind: template === 'coupon_carousel' ? 'coupon' : 'offer',
        benefit: '15%',
        code: template === 'coupon_carousel' ? `SAVE${id}` : null,
        description: '<b>Plain public description</b>',
        expires_on: '2026-10-29',
        terms: ['Minimum order €25', 'Confirmed at checkout'],
      },
    }));
    const { container } = render(<Collection block={data} />);
    expect(screen.getByRole('region', { name: 'Your rewards' })).toHaveClass(
      'sf-deal-items',
      'sf-sized',
      'sf-drag',
    );
    expect(
      screen.getByRole('button', { name: 'storefront.next' }),
    ).toBeVisible();
    expect(container.querySelectorAll('.sf-deal.sf-card-shadow')).toHaveLength(
      6,
    );
    expect(
      container.querySelectorAll('[data-clone][aria-hidden="true"][inert]'),
    ).toHaveLength(3);
    expect(container.querySelector('time')).toHaveAttribute(
      'dateTime',
      '2026-10-29',
    );
    expect(container.querySelector('b')).not.toBeInTheDocument();
    expect(container.querySelector('details')).not.toBeInTheDocument();
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    fireEvent.click(
      screen.getAllByRole('button', { name: 'storefront.deal_terms' })[0],
    );
    const terms = screen.getByRole('dialog', { name: 'storefront.deal_terms' });
    expect(terms).toHaveTextContent('Minimum order €25');
    expect(terms).toHaveTextContent('Reward 1');
    fireEvent.click(screen.getByRole('button', { name: 'storefront.close' }));
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(container.querySelector('code')?.textContent ?? null).toBe(
      template === 'coupon_carousel' ? 'SAVE1' : null,
    );
  },
);

it.each(['coupon_carousel', 'offer_carousel'])(
  'hides empty terms for %s and keeps genuine descriptions readable in a modal',
  (template) => {
    const data = block(false);
    data.template = template;
    data.content.items = [
      {
        id: 'empty',
        name: 'Unrestricted',
        deal: {
          kind: template === 'coupon_carousel' ? 'coupon' : 'offer',
          benefit: '10%',
          code: null,
          expires_on: null,
          description: '   ',
          terms: ['', '  '],
        },
      },
    ];
    const { rerender } = render(<Collection block={data} />);
    expect(
      screen.queryByRole('button', { name: 'storefront.deal_terms' }),
    ).not.toBeInTheDocument();
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    data.content.items[0].deal!.description = 'Valid on qualifying orders.';
    rerender(<Collection block={{ ...data }} />);
    fireEvent.click(
      screen.getByRole('button', { name: 'storefront.deal_terms' }),
    );
    const dialog = screen.getByRole('dialog', {
      name: 'storefront.deal_terms',
    });
    expect(dialog).toHaveTextContent('Valid on qualifying orders.');
    fireEvent(dialog, new Event('cancel', { bubbles: true, cancelable: true }));
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  },
);

it.each([
  'banner_carousel',
  'product_carousel',
  'category_carousel',
  'blog_carousel',
  'brand_carousel',
  'coupon_carousel',
  'offer_carousel',
  'menu_tiles_carousel',
  'menu_circles_carousel',
  'menu_cards_carousel',
])(
  'marks only the configured %s for mobile arrow hiding while retaining navigation',
  (template) => {
    vi.spyOn(HTMLElement.prototype, 'scrollWidth', 'get').mockReturnValue(900);
    const data = block(false);
    data.template = template;
    data.content.display = {
      ...data.content.display,
      hide_arrows_mobile: true,
    };
    const { container, rerender } = render(<Collection block={data} />);
    expect(container.querySelector('.sf-carousel-wrap')).toHaveClass(
      'sf-hide-arrows-mobile',
    );
    expect(
      screen.getByRole('button', { name: 'storefront.next' }),
    ).toBeInTheDocument();
    rerender(
      <Collection
        block={{
          ...data,
          content: {
            ...data.content,
            display: { ...data.content.display, hide_arrows_mobile: false },
          },
        }}
      />,
    );
    expect(container.querySelector('.sf-carousel-wrap')).not.toHaveClass(
      'sf-hide-arrows-mobile',
    );
  },
);

it('keeps banner dots when mobile arrows are hidden and ignores the setting on single-item templates', () => {
  vi.spyOn(HTMLElement.prototype, 'scrollWidth', 'get').mockReturnValue(900);
  const data = block(false);
  data.template = 'banner_carousel';
  data.content.display = {
    navigation: 'inside',
    navigation_style: 'dots',
    hide_arrows_mobile: true,
  };
  const { container, rerender } = render(<Collection block={data} />);
  expect(container.querySelector('.sf-dots button')).toBeInTheDocument();
  expect(container.querySelector('.sf-arrow')).not.toBeInTheDocument();
  rerender(<Collection block={{ ...data, template: 'banner_single' }} />);
  expect(container.querySelector('.sf-carousel-wrap')).not.toHaveClass(
    'sf-hide-arrows-mobile',
  );
});
