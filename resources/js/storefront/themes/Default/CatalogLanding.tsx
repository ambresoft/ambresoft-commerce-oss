import type { BrandPageData, PublicBlock } from '../../types';
import { Picture } from './parts';
import { useTranslation } from '../../../lib/useTranslation';

/** The full-width brand heading above the filters and products; its banner sits above the products (BrandBanner). */
export default function BrandHeader({ block }: { block: PublicBlock }) {
  const t = useTranslation();
  const data = block.content.brand_page;
  if (!data) return null;
  return (
    <>
      {data.source === 'brand' && (
        <nav
          tabIndex={0}
          className="sf-cl-trail sf-brand-page-breadcrumb"
          aria-label={t('storefront.breadcrumb')}
        >
          <ol>
            <li>
              <a href="/">{t('storefront.home')}</a>
            </li>
            <li>
              <a href="/brands">{t('storefront.brands_title')}</a>
            </li>
            <li aria-current="page">{data.name}</li>
          </ol>
        </nav>
      )}
      <header className="sf-brand-page">
        {data.image && (
          <div className="sf-brand-page-logo">
            <Picture image={data.image} alt={data.image_alt} eager />
          </div>
        )}
        <div className="sf-brand-page-text">
          <h1>{data.name}</h1>
          {data.description && (
            <div
              className="sf-prose"
              dangerouslySetInnerHTML={{ __html: data.description }}
            />
          )}
        </div>
      </header>
    </>
  );
}

/** The brand's banner, above the products and beside the filters; optionally a link. */
export function BrandBanner({ data }: { data: BrandPageData }) {
  if (!data.banner) return null;
  const picture = (
    <Picture image={data.banner} alt={data.banner_alt || data.name} eager />
  );
  return (
    <div className="sf-cl-banner">
      {data.banner_link ? <a href={data.banner_link}>{picture}</a> : picture}
    </div>
  );
}
