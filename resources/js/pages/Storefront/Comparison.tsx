import DocumentLocale from '../../components/DocumentLocale';
import { Head } from '@inertiajs/react';
import { renderTheme } from '../../storefront/registry';
import type { StorefrontPage } from '../../storefront/types';
// Only trusted build-time theme code is loaded. Database strings cannot import files.
import.meta.glob('../../storefront/themes/*/index.tsx', { eager: true });

/** The product comparison: the Comparison layout, whose main content block shows the compared products. */
export default function ComparisonPage({
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
