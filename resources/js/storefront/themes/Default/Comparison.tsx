import { router } from '@inertiajs/react';
import { useState, type CSSProperties } from 'react';
import { useTranslation } from '../../../lib/useTranslation';
import type { ComparisonPageData, PublicBlock } from '../../types';
import { comparisonRequest } from './comparisonApi';
import { Money, NetPrice, NoImage, Picture, Stars } from './parts';

type Filter = 'all' | 'different' | 'similar';

function TrashIcon() {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      width="20"
      height="20"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-12M9 7V4h6v3" />
    </svg>
  );
}

/** The comparison page's main content block: the compared products side by side and their features, similar or different. */
export default function Comparison({ block }: { block: PublicBlock }) {
  const t = useTranslation();
  const data: ComparisonPageData | undefined = block.content.comparison;
  const [filter, setFilter] = useState<Filter>('all');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  if (!data) return null;
  const { items, groups, currency_format: format } = data;
  const change = async (method: 'DELETE', url: string) => {
    setBusy(true);
    setError('');
    const result = await comparisonRequest(method, url);
    if (result.ok) {
      router.reload({ onFinish: () => setBusy(false) });
    } else {
      setBusy(false);
      setError(result.message || t('storefront.wishlist_error'));
    }
  };
  const columns = { '--sf-cmp-cols': items.length } as CSSProperties;
  const visible = groups
    .map((group) => ({
      ...group,
      rows: group.rows.filter(
        (row) =>
          filter === 'all' || (filter === 'similar' ? row.same : !row.same),
      ),
    }))
    .filter((group) => group.rows.length > 0);
  const filters: { key: Filter; label: string }[] = [
    { key: 'all', label: 'storefront.comparison_show_all' },
    { key: 'different', label: 'storefront.comparison_show_differences' },
    { key: 'similar', label: 'storefront.comparison_show_similarities' },
  ];
  return (
    <div className="sf-compare">
      <header className="sf-cmp-head">
        <div>
          <h1>{t('storefront.comparison_title')}</h1>
          {items.length > 0 && (
            <p>
              {t('storefront.comparison_count', {
                count: items.length,
                max: data.max,
              })}
            </p>
          )}
        </div>
        {items.length > 0 && (
          <button
            type="button"
            className="sf-cmp-clear"
            disabled={busy}
            onClick={() => change('DELETE', '/comparison')}
          >
            <TrashIcon />
            {t('storefront.comparison_clear')}
          </button>
        )}
      </header>
      {error && (
        <p role="alert" className="sf-pdp-form-error">
          {error}
        </p>
      )}
      {items.length === 0 ? (
        <p className="sf-pdp-empty">{t('storefront.comparison_empty')}</p>
      ) : (
        <>
          {items.length === 1 && (
            <p className="sf-cmp-hint">
              {t('storefront.comparison_need_more')}
            </p>
          )}
          <div className="sf-cmp-scroll">
            <div className="sf-cmp-table" style={columns}>
              <div className="sf-cmp-products">
                {items.map((item) => (
                  <article key={item.id} className="sf-cmp-product">
                    <button
                      type="button"
                      className="sf-cmp-remove"
                      aria-label={t('storefront.comparison_remove_item', {
                        name: item.name ?? '',
                      })}
                      title={t('storefront.comparison_remove')}
                      disabled={busy}
                      onClick={() =>
                        change('DELETE', `/comparison/products/${item.id}`)
                      }
                    >
                      <TrashIcon />
                    </button>
                    <a
                      className="sf-cmp-picture"
                      href={item.url ?? undefined}
                      tabIndex={-1}
                      aria-hidden="true"
                    >
                      {item.image ? (
                        <Picture image={item.image} alt="" />
                      ) : (
                        <NoImage />
                      )}
                    </a>
                    <h2>
                      {item.url ? (
                        <a href={item.url}>{item.name}</a>
                      ) : (
                        item.name
                      )}
                    </h2>
                    {item.rating && item.rating.average && (
                      <p className="sf-cmp-rating">
                        <Stars value={Number(item.rating.average)} size={16} />
                        <span>({item.rating.count})</span>
                      </p>
                    )}
                    {item.price != null && (
                      <p className="sf-cmp-price">
                        <strong>
                          <Money amount={item.price} format={format} />
                        </strong>
                        <NetPrice item={item} format={format} />
                      </p>
                    )}
                  </article>
                ))}
              </div>
              {groups.length > 0 && (
                <section className="sf-cmp-features">
                  <div className="sf-cmp-bar">
                    <h2>{t('storefront.comparison_features')}</h2>
                    {items.length > 1 && (
                      <div
                        className="sf-cmp-filter"
                        role="group"
                        aria-label={t('storefront.comparison_features')}
                      >
                        {filters.map((f) => (
                          <button
                            key={f.key}
                            type="button"
                            aria-pressed={filter === f.key}
                            onClick={() => setFilter(f.key)}
                          >
                            {t(f.label)}
                          </button>
                        ))}
                      </div>
                    )}
                  </div>
                  {visible.length === 0 ? (
                    <p className="sf-pdp-empty">
                      {t('storefront.comparison_no_rows')}
                    </p>
                  ) : (
                    visible.map((group) => (
                      <div key={group.name} className="sf-cmp-group">
                        {group.name && <h3>{group.name}</h3>}
                        {group.rows.map((row) => (
                          <div
                            key={row.id}
                            className={`sf-cmp-row${row.same ? '' : ' is-different'}`}
                          >
                            {row.values.map((value, i) => (
                              <div key={i} className="sf-cmp-cell">
                                <span>{row.name}</span>
                                <strong>{value ?? '—'}</strong>
                              </div>
                            ))}
                          </div>
                        ))}
                      </div>
                    ))
                  )}
                </section>
              )}
            </div>
          </div>
        </>
      )}
    </div>
  );
}
