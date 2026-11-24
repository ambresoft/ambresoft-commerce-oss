import StorefrontPrimaryHeading from '../../components/StorefrontPrimaryHeading';
import DocumentLocale from '../../components/DocumentLocale';
import { Head } from '@inertiajs/react';
import { useTranslation } from '../../lib/useTranslation';
import { renderTheme } from '../../storefront/registry';
import type { StorefrontPage } from '../../storefront/types';
// Only trusted build-time theme code is loaded. Database strings cannot import files.
import.meta.glob('../../storefront/themes/*/index.tsx', { eager: true });
export default function Homepage({
  storefront,
  seo,
}: {
  storefront: StorefrontPage;
  seo?: { title: string };
}) {
  const t = useTranslation();
  return (
    <>
      <DocumentLocale />
      <Head title={seo?.title || t('storefront.home')} />
      {renderTheme(storefront)}
      <StorefrontPrimaryHeading title={seo?.title || t('storefront.home')} />
    </>
  );
}
