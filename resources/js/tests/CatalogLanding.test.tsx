import { cleanup, render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import BrandHeader, {
  BrandBanner,
} from '../storefront/themes/Default/CatalogLanding';
import type { BrandPageData, PublicBlock } from '../storefront/types';

vi.mock('../lib/useTranslation', () => ({
  useTranslation: () => (key: string) =>
    ({
      'storefront.home': 'Home',
      'storefront.brands_title': 'All brands',
      'storefront.breadcrumb': 'Breadcrumb',
    })[key] ?? key,
}));

it('shows the brand picture beside its name and description', () => {
  const block = {
    id: 'b',
    template: 'page_header',
    content: {
      brand_page: {
        id: '1',
        source: 'brand',
        name: 'Apple',
        url: '/apple',
        description: '<p>Our brand</p>',
        image: { url: '/apple.png', width: 200, height: 100 },
        image_alt: 'Apple logo',
      },
    },
  } as unknown as PublicBlock;
  render(<BrandHeader block={block} />);
  const trail = screen.getByRole('navigation', { name: 'Breadcrumb' });
  expect(trail).toHaveClass('sf-brand-page-breadcrumb');
  expect(trail.nextElementSibling).toHaveClass('sf-brand-page');
  const logo = screen.getByRole('img', { name: 'Apple logo' });
  const heading = screen.getByRole('heading', { name: 'Apple', level: 1 });
  expect(
    logo.compareDocumentPosition(heading) & Node.DOCUMENT_POSITION_FOLLOWING,
  ).toBeTruthy();
  expect(screen.getByText('Our brand')).toBeVisible();
  // No "Brand" label.
  expect(screen.queryByText(/storefront\./)).toBeNull();
});

it('shows the brand banner apart from the heading, with its own alternative text and an optional link', () => {
  const data = {
    id: '1',
    source: 'brand',
    name: 'Apple',
    url: '/apple',
    description: '',
    image: null,
    image_alt: '',
    banner: { url: '/banner.png', width: 1200, height: 300 },
    banner_alt: 'Summer offers',
    banner_link: '/offers',
  } as unknown as BrandPageData;
  // The heading no longer carries the banner: it sits above the products, beside the filters.
  render(
    <BrandHeader
      block={{ content: { brand_page: data } } as unknown as PublicBlock}
    />,
  );
  expect(screen.queryByRole('img', { name: 'Summer offers' })).toBeNull();
  cleanup();
  const view = render(<BrandBanner data={data} />);
  expect(screen.getByRole('link', { name: 'Summer offers' })).toHaveAttribute(
    'href',
    '/offers',
  );
  view.rerender(
    <BrandBanner data={{ ...data, banner_link: null, banner_alt: '' }} />,
  );
  expect(screen.getByRole('img', { name: 'Apple' })).toBeVisible();
  expect(screen.queryByRole('link')).not.toBeInTheDocument();
});
