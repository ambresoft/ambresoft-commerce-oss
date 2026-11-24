import { useTranslation } from '../../../lib/useTranslation';
import type { PublicBlock } from '../../types';
import { FaqList } from './parts';

/** A static page's main content block: the trail, the title, the content and its questions. */
export default function StaticPage({ block }: { block: PublicBlock }) {
  const t = useTranslation();
  const page = block.content.page;
  if (!page) return null;
  return (
    <article className="sf-page">
      <nav
        tabIndex={0}
        className="sf-cl-trail"
        aria-label={t('storefront.breadcrumb')}
      >
        <ol>
          <li>
            <a href="/">{t('storefront.home')}</a>
          </li>
          <li aria-current="page">{page.name}</li>
        </ol>
      </nav>
      {/* Content that opens with its own main heading already carries the title. */}
      {!/^\s*<h1[\s>]/i.test(page.content) && <h1>{page.name}</h1>}
      {page.content && (
        <div
          className="sf-prose sf-page-content"
          dangerouslySetInnerHTML={{ __html: page.content }}
        />
      )}
      {page.faqs.length > 0 && (
        <section className="sf-page-faqs" aria-labelledby="sf-page-faqs">
          <h2 id="sf-page-faqs">{t('storefront.faqs')}</h2>
          <FaqList faqs={page.faqs} />
        </section>
      )}
    </article>
  );
}
