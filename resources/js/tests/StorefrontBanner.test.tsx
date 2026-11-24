import { act, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import BannerPreview from '../components/admin/banners/BannerPreview';
import StorefrontBanner from '../storefront/components/StorefrontBanner';
import type { PublicBanner } from '../storefront/types';

vi.mock('../lib/useTranslation', () => ({
  useTranslation: () => (key: string) => key,
}));
const pc = {
  layout: {
    valign: 'center',
    halign: 'left',
    paddings: '40px',
    full_width: false,
    title_size: 46,
    title_shadow: true,
    description_size: 18,
    background_type: 'image',
    background_size: 'cover',
    background_valign: 'center',
    button_enabled: true,
    button_text_color: '#302010',
    button_color: '#f3d49b',
    mask_type: 'gradient',
    mask_position: 'banner',
    mask_opacity: 40,
    mask_direction: 'auto',
    mask_color: null,
    css_classes: 'banner-background-fade',
  },
  content: {
    title:
      '<span class="banner-title-above">New collection</span>Kraft <i>bags</i>',
    description: 'For bakeries & pastry shops',
    button_text: 'View collection',
    youtube_video: '',
    main_image: null,
    background_image: '/background.png',
    background_video: null,
  },
} satisfies PublicBanner['devices']['pc'];
const banner: PublicBanner = {
  settings: {
    color_scheme: 'dark',
    object_type: 'image',
    youtube_autoplay: false,
    youtube_hide_controls: false,
    products_template: 'grid',
    products_columns: 4,
    products_rows: 1,
    object_valign: 'center',
    object_halign: 'center',
    background_color: '#302010',
    background_halign: 'right',
    title_color: '#ffffff',
    title_weight: 'bold',
    title_tag: 'h2',
    description_color: null,
    description_background: null,
    button_style: 'standard',
    button_hover: 'none',
    button_transition: 0.3,
    url: '/collection',
    new_window: true,
    tablet_custom: true,
    phone_custom: true,
  },
  devices: {
    pc,
    tablet: { ...pc, layout: { ...pc.layout, title_size: 32 } },
    phone: {
      ...pc,
      layout: { ...pc.layout, title_size: 28, paddings: '20px' },
      content: { ...pc.content, title: 'Mobile collection' },
    },
  },
  products: [],
};
beforeEach(() => {
  Object.defineProperty(window, 'innerWidth', {
    value: 1280,
    writable: true,
    configurable: true,
  });
});
afterEach(() => vi.unstubAllGlobals());

it('uses the exact admin preview composition for storefront banners with no separate image-and-caption layout', () => {
  const preview = render(
    <BannerPreview
      device="pc"
      settings={banner.settings}
      layout={pc.layout}
      content={pc.content}
      products={[]}
    />,
  );
  const design = preview.container.querySelector('.banner-preview')!.outerHTML;
  preview.unmount();
  const publicView = render(<StorefrontBanner banner={banner} />);
  expect(publicView.container.querySelector('.banner-preview')!.outerHTML).toBe(
    design,
  );
  expect(
    publicView.container.querySelector('.sf-banner-copy'),
  ).not.toBeInTheDocument();
  expect(screen.getByRole('link')).toHaveAttribute('href', '/collection');
  expect(screen.getByRole('link')).toHaveAttribute(
    'rel',
    'noopener noreferrer',
  );
  expect(publicView.container.querySelector('.banner-title')).toHaveStyle({
    fontSize: '46px',
    color: '#ffffff',
    fontWeight: 700,
  });
  expect(screen.getByText('View collection')).toHaveStyle({
    backgroundColor: '#f3d49b',
  });
});

it('scales inside its grid and changes device composition without duplicating banner content', () => {
  const disconnect = vi.fn();
  let availableWidth = 600;
  vi.stubGlobal(
    'ResizeObserver',
    class {
      constructor(
        private callback: (
          entries: { contentRect: { width: number } }[],
        ) => void,
      ) {}
      observe() {
        this.callback([{ contentRect: { width: availableWidth } }]);
      }
      disconnect = disconnect;
    },
  );
  const { container, rerender, unmount } = render(
    <StorefrontBanner banner={banner} />,
  );
  expect(container.querySelector('[style*="scale(0.5)"]')).toBeInTheDocument();
  // An Inertia update can remove the full-slide link and replace the observed wrapper.
  const updated = structuredClone(banner);
  updated.settings.url = null;
  availableWidth = 300;
  rerender(<StorefrontBanner banner={updated} />);
  expect(container.querySelector('[style*="scale(0.25)"]')).toBeInTheDocument();
  act(() => {
    window.innerWidth = 390;
    window.dispatchEvent(new Event('resize'));
  });
  expect(screen.getByText('Mobile collection')).toHaveStyle({
    fontSize: '28px',
  });
  expect(container.querySelectorAll('.banner-preview')).toHaveLength(1);
  expect(container.querySelector('.banner-preview')).toHaveStyle({
    width: '375px',
    height: '520px',
  });
  unmount();
  expect(disconnect).toHaveBeenCalled();
});

it('keeps embedded video interactive and gives its CTA a safe link without nesting the iframe in an anchor', () => {
  const video = structuredClone(banner);
  video.settings.object_type = 'youtube';
  video.devices.pc.content.youtube_video = 'dQw4w9WgXcQ';
  const { container } = render(<StorefrontBanner banner={video} />);
  expect(screen.getByTitle('YouTube').closest('a')).toBeNull();
  expect(screen.getByRole('link', { name: 'View collection' })).toHaveAttribute(
    'href',
    '/collection',
  );
  expect(container.querySelector('iframe')!.src).toContain(
    'youtube-nocookie.com/embed/dQw4w9WgXcQ',
  );
});

it('makes the banner taller at the same width on desktop and tablet, never on phones or below the design', () => {
  vi.stubGlobal(
    'ResizeObserver',
    class {
      constructor(
        private callback: (
          entries: { contentRect: { width: number } }[],
        ) => void,
      ) {}
      observe() {
        this.callback([{ contentRect: { width: 600 } }]);
      }
      disconnect() {}
    },
  );
  const canvas = (container: HTMLElement) =>
    container.querySelector<HTMLElement>('.banner-preview')!;
  window.innerWidth = 1400;
  const { container, rerender } = render(
    <StorefrontBanner banner={banner} heights={{ pc: 150, tablet: 200 }} />,
  );
  // 450px designed, 150% asked for; the width (and so the scale) is unchanged.
  expect(canvas(container)).toHaveStyle({ width: '1200px', height: '675px' });
  expect(container.querySelector('[style*="scale(0.5)"]')).toBeInTheDocument();
  expect(
    container.querySelector<HTMLElement>('.relative.w-full')!.style.aspectRatio,
  ).toBe('1200 / 675');
  // Less than the design is not allowed.
  rerender(<StorefrontBanner banner={banner} heights={{ pc: 40 }} />);
  expect(canvas(container)).toHaveStyle({ height: '450px' });
  act(() => {
    window.innerWidth = 900;
    window.dispatchEvent(new Event('resize'));
  });
  rerender(
    <StorefrontBanner banner={banner} heights={{ pc: 150, tablet: 200 }} />,
  );
  expect(canvas(container)).toHaveStyle({ width: '768px', height: '840px' });
  act(() => {
    window.innerWidth = 390;
    window.dispatchEvent(new Event('resize'));
  });
  expect(canvas(container)).toHaveStyle({ width: '375px', height: '520px' });
});

it('gives the button its hover effect and transition time, without one by default', () => {
  const view = render(<StorefrontBanner banner={banner} />);
  const button = () =>
    view.container.querySelector<HTMLElement>('.banner-button')!;
  expect(button()).toHaveClass('banner-button-none', 'banner-button-standard');
  const hovering = structuredClone(banner);
  hovering.settings.button_hover = 'lift';
  hovering.settings.button_transition = 0.5;
  view.rerender(<StorefrontBanner banner={hovering} />);
  expect(button()).toHaveClass('banner-button-lift');
  // The stylesheet reads the fill, the text on it and the duration from these.
  expect(button().style.getPropertyValue('--bb-t')).toBe('0.5s');
  expect(button().style.getPropertyValue('--bb-bg')).not.toBe('');
});
