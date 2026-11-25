import DocumentLocale from '../../components/DocumentLocale';
import { Head } from '@inertiajs/react';
import { renderTheme } from '../../storefront/registry';
import type { StorefrontPage } from '../../storefront/types';
// Only trusted build-time theme code is loaded. Database strings cannot import files.
import.meta.glob('../../storefront/themes/*/index.tsx', { eager: true });

/** A product page: the Products layout, whose main content block shows the product (reviews arrive as their own prop). */
export default function ProductPage({
  storefront,
  seo,
}: {
  storefront: StorefrontPage;
  seo: { title: string };
}) {
  return (
    <>
      <DocumentLocale />
      <Head title={seo.title} />
      {renderTheme(storefront)}
    </>
  );
}
