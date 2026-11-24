import { readFileSync } from 'node:fs';
import { render } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import AddonStyles from '../storefront/components/AddonStyles';

const shared = vi.hoisted(() => ({
  props: {
    storefrontStylesheets: {} as Record<string, string>,
    storefrontDocumentPalette: null as {
      scheme: 'light' | 'dark';
      background: string;
    } | null,
  },
}));
vi.mock('@inertiajs/react', async (original) => ({
  ...(await original<typeof import('@inertiajs/react')>()),
  usePage: () => shared,
}));
const css = readFileSync(
  'modules/ambresoft/black-friday-theme/resources/theme.css',
  'utf8',
);

it('removes the palette when the server disables the addon, including navigation updates', () => {
  shared.props.storefrontStylesheets = { 'ambresoft/black-friday-theme': css };
  const { container, rerender } = render(<AddonStyles />);
  expect(container.querySelector('style')?.textContent).toBe(css);
  shared.props.storefrontStylesheets = {};
  rerender(<AddonStyles />);
  expect(container.querySelector('style')).toBeNull();
});

it('contains only scoped color overrides, with no layout, interaction or image changes', () => {
  const style = document.createElement('style');
  style.textContent = css;
  document.head.append(style);
  const inspect = (rules: CSSRuleList) => {
    for (const rule of Array.from(rules)) {
      if ('cssRules' in rule) {
        inspect((rule as CSSGroupingRule).cssRules);
        continue;
      }
      const entry = rule as CSSStyleRule;
      expect(entry.selectorText).toMatch(/sf-default|store-auth|store-closed/);
      // A vendor-only pseudo-element must never invalidate ordinary selectors in another browser.
      if (/::-(webkit|moz)-/.test(entry.selectorText)) {
        expect(entry.selectorText.split(',')).toHaveLength(1);
      }
      for (let i = 0; i < entry.style.length; i++) {
        const property = entry.style.item(i);
        expect(property).toMatch(
          /^(color|color-scheme|background-color|border(-(top|bottom|left|right))?-color|fill|stroke|--[a-z-]+)$/,
        );
        if (property.startsWith('--')) {
          expect(entry.style.getPropertyValue(property).trim()).toMatch(
            /^#[\da-f]{6}$/i,
          );
        }
      }
    }
  };
  try {
    expect(style.sheet!.cssRules.length).toBeGreaterThan(0);
    inspect(style.sheet!.cssRules);
    expect(css).not.toMatch(/url\(|@import|filter:|opacity:|pointer-events:/);
  } finally {
    style.remove();
  }
});

it('gives tiles, featured articles, price fields and image controls a dark readable surface', () => {
  const style = document.createElement('style');
  style.textContent = css;
  document.head.append(style);
  const { container } = render(
    <div className="sf-default">
      <a className="sf-menu-tile">Offers</a>
      <article className="sf-blog-card sf-blog-featured">Latest news</article>
      <label className="sf-range-field">
        <input aria-label="Minimum price" />
      </label>
      <button className="sf-card-heart">Save</button>
      <button className="sf-card-compare">Compare</button>
      <button className="sf-pdp-action">Share</button>
      <button className="sf-arrow">Next</button>
      <section className="sf-pdp-variation-alternatives">
        <h2>
          <button className="sf-pdp-alternatives-toggle">
            Other variations
          </button>
        </h2>
      </section>
    </div>,
  );
  try {
    for (const element of container.querySelectorAll(
      'a, article, input, button:not(.sf-pdp-alternatives-toggle), section',
    )) {
      expect(getComputedStyle(element).backgroundColor, element.outerHTML).toBe(
        'rgb(28, 32, 40)',
      );
    }
  } finally {
    style.remove();
  }
});

it('applies the document palette and clears it when the addon is disabled', () => {
  shared.props.storefrontDocumentPalette = {
    scheme: 'dark',
    background: '#12151b',
  };
  document.documentElement.dataset.theme = 'dark';
  const { rerender, unmount } = render(<AddonStyles />);
  expect(document.documentElement.style.colorScheme).toBe('dark');
  expect(document.documentElement.style.backgroundColor).toBe(
    'rgb(18, 21, 27)',
  );
  expect(document.body.style.backgroundColor).toBe('rgb(18, 21, 27)');
  expect(document.documentElement.dataset.theme).toBeUndefined();
  shared.props.storefrontDocumentPalette = null;
  rerender(<AddonStyles />);
  expect(document.documentElement.style.colorScheme).toBe('light');
  expect(document.body.style.backgroundColor).toBe('');
  unmount();
  expect(document.documentElement.style.colorScheme).toBe('');
});
