import type { CSSProperties } from 'react';
import { PostCards } from './Blog';
import { useTranslation } from '../../../lib/useTranslation';
import type { CategoryLandingData, PublicBlock } from '../../types';
import AgeGate from './AgeGate';
import { FaqList, NoImage, Picture } from './parts';

/** A landing page category's main content block: its trail, its name and its subcategories as cards. */
export default function CategoryLanding({ block }: { block: PublicBlock }) {
  const t = useTranslation();
  const data = block.content.category;
  if (!data) return null;
  if (data.age_gate)
    return <AgeGate age={data.age_gate.age} message={data.age_gate.message} />;
  const perRow = Math.min(8, Math.max(1, block.content.per_row ?? 5));
  const mobilePerRow = Math.min(
    4,
    Math.max(1, block.content.mobile_per_row ?? 2),
  );
  const carousel = block.content.mobile_display === 'carousel';
  const shadow = block.content.card_shadow !== false;
  const style = {
    '--sf-cl-cols': perRow,
    '--sf-cl-tablet-cols': Math.min(perRow, 4),
    '--sf-cl-mobile-cols': mobilePerRow,
  } as CSSProperties;
  return (
    <div className="sf-landing">
      <CategoryHeader data={data} />
      {data.items.length === 0 ? (
        <p className="sf-pdp-empty">{t('storefront.category_empty')}</p>
      ) : (
        <ul
          className={`sf-cl-grid${carousel ? ' is-mobile-carousel' : ''}`}
          style={style}
        >
          {data.items.map((item) => {
            const body = (
              <>
                <span className="sf-cl-media">
                  {item.image ? (
                    <Picture image={item.image} alt={item.image_alt} />
                  ) : (
                    <NoImage />
                  )}
                </span>
                <span className="sf-cl-name">{item.name}</span>
              </>
            );
            return (
              <li
                key={item.id}
                className={`sf-cl-card${shadow ? ' has-shadow' : ''}`}
              >
                {item.url ? <a href={item.url}>{body}</a> : body}
              </li>
            );
          })}
        </ul>
      )}
      <CategoryPosts data={data} />
      <CategoryFaqs data={data} />
    </div>
  );
}

/** A category's trail, name, banner and description (landing pages and product listings). */
export function CategoryHeader({ data }: { data: CategoryLandingData }) {
  const t = useTranslation();
  const trail = data.path.slice(0, -1);
  return (
    <>
      <nav
        tabIndex={0}
        className="sf-cl-trail"
        aria-label={t('storefront.breadcrumb')}
      >
        <ol>
          <li>
            <a href="/">{t('storefront.home')}</a>
          </li>
          {trail.map((c, n) => (
            <li key={n}>{c.url ? <a href={c.url}>{c.name}</a> : c.name}</li>
          ))}
          <li aria-current="page">{data.name}</li>
        </ol>
      </nav>
      <h1>{data.name}</h1>
      {data.banner && (
        <div className="sf-cl-banner">
          {data.banner_link ? (
            <a href={data.banner_link}>
              <Picture image={data.banner} alt={data.banner_alt ?? ''} eager />
            </a>
          ) : (
            <Picture image={data.banner} alt={data.banner_alt ?? ''} eager />
          )}
        </div>
      )}
      {data.description && (
        <div
          className="sf-prose sf-cl-description"
          dangerouslySetInnerHTML={{ __html: data.description }}
        />
      )}
    </>
  );
}

/** A category's questions and answers, after its content. */
/** Blog posts the category links to, above its questions. */
export function CategoryPosts({ data }: { data: CategoryLandingData }) {
  const t = useTranslation();
  if ((data.posts ?? []).length === 0) return null;
  return (
    <section
      className="sf-blog-posts sf-cl-posts"
      aria-labelledby="sf-cl-posts"
    >
      <h2 id="sf-cl-posts">{t('storefront.blog_related')}</h2>
      <PostCards posts={data.posts ?? []} />
    </section>
  );
}

export function CategoryFaqs({ data }: { data: CategoryLandingData }) {
  const t = useTranslation();
  if ((data.faqs ?? []).length === 0) return null;
  return (
    <section className="sf-cl-faqs" aria-labelledby="sf-cl-faqs">
      <h2 id="sf-cl-faqs">{t('storefront.faqs')}</h2>
      <FaqList faqs={data.faqs ?? []} />
    </section>
  );
}
