import { useId } from 'react';
import { useTranslation } from '../../../lib/useTranslation';
import type { DirectoryLink, DirectoryNode, PublicBlock } from '../../types';

function LinkText({ link }: { link: DirectoryLink }) {
  return link.url ? (
    <a href={link.url}>{link.name}</a>
  ) : (
    <span>{link.name}</span>
  );
}
function CategoryChildren({ nodes }: { nodes: DirectoryNode[] }) {
  if (!nodes.length) return null;
  return (
    <ul>
      {nodes.map((node) => (
        <li key={node.id}>
          <LinkText link={node} />
          <CategoryChildren nodes={node.children} />
        </li>
      ))}
    </ul>
  );
}

/** The HTML sitemap and brand index use the existing layout's protected main content block. */
export default function Directory({ block }: { block: PublicBlock }) {
  const t = useTranslation();
  const id = useId();
  const data = block.content.directory;
  if (!data) return null;
  const title =
    data.kind === 'sitemap'
      ? t('storefront.sitemap_title')
      : t('storefront.brands_title');
  return (
    <article className="sf-directory">
      <nav
        tabIndex={0}
        className="sf-cl-trail"
        aria-label={t('storefront.breadcrumb')}
      >
        <ol>
          <li>
            <a href="/">{t('storefront.home')}</a>
          </li>
          <li aria-current="page">{title}</li>
        </ol>
      </nav>
      <h1>{title}</h1>
      {data.kind === 'sitemap' ? (
        <>
          <section aria-labelledby={`${id}-store`}>
            <h2 id={`${id}-store`}>{t('storefront.sitemap_store')}</h2>
            <div className="sf-directory-columns sf-sitemap-information">
              <section>
                <h3>{t('storefront.sitemap_information')}</h3>
                <ul>
                  {data.pages.map((link) => (
                    <li key={link.url ?? link.name}>
                      <LinkText link={link} />
                    </li>
                  ))}
                </ul>
              </section>
              <section>
                <h3>{t('storefront.account')}</h3>
                <ul>
                  {data.account.map((link) => (
                    <li key={link.url ?? link.name}>
                      <LinkText link={link} />
                    </li>
                  ))}
                </ul>
              </section>
            </div>
          </section>
          {data.categories.length > 0 && (
            <section aria-labelledby={`${id}-catalog`}>
              <h2 id={`${id}-catalog`}>{t('storefront.sitemap_catalog')}</h2>
              <div className="sf-directory-columns sf-sitemap-categories">
                {data.categories.map((category) => (
                  <section key={category.id}>
                    <h3>
                      <LinkText link={category} />
                    </h3>
                    <CategoryChildren nodes={category.children} />
                  </section>
                ))}
              </div>
            </section>
          )}
        </>
      ) : (
        <>
          {data.groups.length > 0 ? (
            <>
              <nav
                className="sf-brand-alphabet"
                aria-label={t('storefront.brands_alphabet')}
              >
                <ul>
                  {data.groups.map((group, index) => (
                    <li key={group.letter}>
                      <a href={`#${id}-letter-${index}`}>{group.letter}</a>
                    </li>
                  ))}
                </ul>
              </nav>
              {data.groups.map((group, index) => (
                <section
                  key={group.letter}
                  className="sf-brand-letter"
                  aria-labelledby={`${id}-letter-${index}`}
                >
                  <h2 id={`${id}-letter-${index}`}>{group.letter}</h2>
                  <ul className="sf-brand-directory-list">
                    {group.items.map((brand) => (
                      <li key={brand.id}>
                        <LinkText link={brand} />
                      </li>
                    ))}
                  </ul>
                </section>
              ))}
            </>
          ) : (
            <p className="sf-directory-empty">{t('storefront.brands_empty')}</p>
          )}
        </>
      )}
    </article>
  );
}
