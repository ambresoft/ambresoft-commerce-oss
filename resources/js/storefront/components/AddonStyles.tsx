import { useLayoutEffect } from 'react';
import { usePage } from '@inertiajs/react';

/** Trusted, enabled package styles; refreshed even on partial Inertia visits. */
export default function AddonStyles() {
  const { storefrontStylesheets = {}, storefrontDocumentPalette = null } =
    usePage<{
      storefrontStylesheets?: Record<string, string>;
      storefrontDocumentPalette?: {
        scheme: 'light' | 'dark';
        background: string;
      } | null;
    }>().props;
  const scheme = storefrontDocumentPalette?.scheme ?? 'light';
  const background = storefrontDocumentPalette?.background ?? '';
  useLayoutEffect(() => {
    const root = document.documentElement;
    delete root.dataset.theme;
    root.style.colorScheme = scheme;
    root.style.backgroundColor = background;
    document.body.style.backgroundColor = background;
    return () => {
      root.style.removeProperty('color-scheme');
      root.style.removeProperty('background-color');
      document.body.style.removeProperty('background-color');
    };
  }, [scheme, background]);
  return Object.entries(storefrontStylesheets).map(([addon, css]) => (
    <style key={addon} data-storefront-addon={addon}>
      {css}
    </style>
  ));
}
