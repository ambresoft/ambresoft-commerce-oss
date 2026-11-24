import { Link } from '@inertiajs/react';
import { BrandBanner } from './CatalogLanding';
import { useEffect, useRef, useState, type CSSProperties } from 'react';
import { useTranslation } from '../../../lib/useTranslation';
import type {
  CurrencyFormat,
  ListingActiveFilter,
  ListingFilterGroup,
  PublicBlock,
  PublicItem,
} from '../../types';
import AgeGate from './AgeGate';
import { CategoryFaqs, CategoryHeader, CategoryPosts } from './CategoryLanding';
import {
  changeListing,
  clearFilters,
  closeFilters,
  listingHref,
  openFilters,
  scrollToListing,
  toggleValue,
  useFiltersPanel,
  useListingQuery,
  useListingStatus,
} from './listingUrl';
import { comparisonRequest } from './comparisonApi';
import {
  CardComparisonContext,
  FALLBACK_FORMAT,
  Money,
  Product,
  Stars,
} from './parts';
import { emitStorefrontEvent } from '../../events';

const fold = (text: string) =>
  text
    .normalize('NFD')
    .replace(/\p{Diacritic}/gu, '')
    .toLowerCase();

/** A range value as the shopper reads it: money in the store's format, numbers with their unit. */
function RangeValue({
  value,
  money,
  unit,
  format,
}: {
  value: string;
  money: boolean;
  unit: string;
  format: CurrencyFormat;
}) {
  return money ? (
    <Money amount={value} format={format} inline />
  ) : (
    <>{unit ? `${value} ${unit}` : value}</>
  );
}

function ChipText({
  filter,
  format,
}: {
  filter: ListingActiveFilter;
  format: CurrencyFormat;
}) {
  if (filter.kind === 'choice')
    return (
      <>
        {filter.name}: {filter.label}
      </>
    );
  const value = (v: string | null) =>
    v === null ? (
      '…'
    ) : (
      <RangeValue
        value={v}
        money={filter.money}
        unit={filter.unit}
        format={format}
      />
    );
  return (
    <>
      {filter.name}: {value(filter.from)} – {value(filter.to)}
    </>
  );
}

function removeFilter(filter: ListingActiveFilter) {
  changeListing((query) => {
    if (filter.kind === 'range') {
      query.delete(`${filter.param}_min`);
      query.delete(`${filter.param}_max`);
    } else toggleValue(query, filter.param, filter.value);
  });
}

/**
 * Min/max inputs over a two-handle slider, with the range's ends beneath. Nothing loads while typing or dragging:
 * releasing a handle, pressing Enter or leaving an input applies the range. A bound at the range's end is no bound.
 */
function RangeFilter({
  group,
  format,
}: {
  group: Extract<ListingFilterGroup, { kind: 'range' }>;
  format: CurrencyFormat;
}) {
  const t = useTranslation();
  const [from, setFrom] = useState(group.from ?? '');
  const [to, setTo] = useState(group.to ?? '');
  // A chip removal or "clear all" empties the inputs too.
  const applied = `${group.from}|${group.to}`;
  const [shown, setShown] = useState(applied);
  if (shown !== applied) {
    setShown(applied);
    setFrom(group.from ?? '');
    setTo(group.to ?? '');
  }
  // The slider moves in whole steps of the bounds' precision (whole currency units for prices).
  const decimals = group.money
    ? 0
    : Math.max(
        ...[group.min, group.max].map((v) => (v.split('.')[1] ?? '').length),
      );
  const scale = 10 ** decimals;
  const low = Math.floor(Number(group.min) * scale);
  const high = Math.max(low, Math.ceil(Number(group.max) * scale));
  const position = (value: string, fallback: number) => {
    const n = Number(value.trim().replace(',', '.'));
    return value.trim() === '' || Number.isNaN(n)
      ? fallback
      : Math.min(high, Math.max(low, Math.round(n * scale)));
  };
  const text = (step: number) => (step / scale).toFixed(decimals);
  const lo = position(from, low);
  const hi = Math.max(lo, position(to, high));
  const unit = group.money ? format.symbol : group.unit;
  const apply = (min = from, max = to) => {
    const bound = (value: string, end: number) => {
      const v = value.trim().replace(',', '.');
      return v === '' || position(v, end) === end ? null : v;
    };
    const next = [bound(min, low), bound(max, high)];
    if (next[0] === group.from && next[1] === group.to) return;
    changeListing((query) => {
      for (const [key, value] of [
        [`${group.param}_min`, next[0]],
        [`${group.param}_max`, next[1]],
      ] as const)
        if (value === null) query.delete(key);
        else query.set(key, value);
    });
  };
  const end = (step: number) =>
    group.money ? (
      <Money amount={text(step)} format={format} inline />
    ) : (
      <>{unit ? `${text(step)} ${unit}` : text(step)}</>
    );
  const field = (
    value: string,
    set: (v: string) => void,
    label: string,
    placeholder: string,
  ) => (
    <label className="sf-range-field">
      <input
        inputMode="decimal"
        value={value}
        placeholder={placeholder}
        aria-label={`${group.name}: ${label}`}
        onChange={(e) => set(e.target.value)}
        onBlur={() => apply()}
        onKeyDown={(e) => e.key === 'Enter' && apply()}
      />
      {unit && <span aria-hidden="true">{unit}</span>}
    </label>
  );
  const release = () => apply();
  return (
    <div className="sf-filter-range">
      <div className="sf-range-fields">
        {field(from, setFrom, t('storefront.range_from'), text(low))}
        <span aria-hidden="true">-</span>
        {field(to, setTo, t('storefront.range_to'), text(high))}
      </div>
      {high > low && (
        <>
          <div
            className="sf-range-slider"
            style={
              {
                '--sf-range-lo': `${((lo - low) / (high - low)) * 100}%`,
                '--sf-range-hi': `${((hi - low) / (high - low)) * 100}%`,
              } as CSSProperties
            }
          >
            <input
              type="range"
              min={low}
              max={high}
              value={lo}
              aria-label={`${group.name}: ${t('storefront.range_from')}`}
              aria-valuetext={`${text(lo)}${unit ? ` ${unit}` : ''}`}
              onChange={(e) =>
                setFrom(text(Math.min(Number(e.target.value), hi)))
              }
              onPointerUp={release}
              onKeyUp={release}
            />
            <input
              type="range"
              min={low}
              max={high}
              value={hi}
              aria-label={`${group.name}: ${t('storefront.range_to')}`}
              aria-valuetext={`${text(hi)}${unit ? ` ${unit}` : ''}`}
              onChange={(e) =>
                setTo(text(Math.max(Number(e.target.value), lo)))
              }
              onPointerUp={release}
              onKeyUp={release}
            />
          </div>
          <p className="sf-range-ends">
            <span>{end(low)}</span>
            <span>{end(high)}</span>
          </p>
        </>
      )}
    </div>
  );
}

/** Checkbox values with counts; long lists fold behind "Show more" and get a search field. */
function ChoiceFilter({
  group,
  visible,
}: {
  group: Extract<ListingFilterGroup, { kind: 'choice' | 'rating' }>;
  visible: number;
}) {
  const t = useTranslation();
  const query = useListingQuery();
  const [more, setMore] = useState(false);
  const [search, setSearch] = useState('');
  const selected = (param: string) => (query.get(param) ?? '').split(',');
  const matches = search
    ? group.options.filter((o) => fold(o.label).includes(fold(search)))
    : group.options;
  const shown =
    more || search
      ? matches
      : // Chosen values stay in view even past the fold.
        matches.filter(
          (o, n) =>
            n < visible || selected(o.param ?? group.param).includes(o.value),
        );
  return (
    <>
      {group.options.length > 10 && (
        <input
          type="search"
          className="sf-filter-search"
          value={search}
          placeholder={t('storefront.filter_search', { name: group.name })}
          aria-label={t('storefront.filter_search', { name: group.name })}
          onChange={(e) => setSearch(e.target.value)}
        />
      )}
      <ul className="sf-filter-options">
        {shown.map((o) => {
          const param = o.param ?? group.param;
          const checked = selected(param).includes(o.value);
          return (
            <li key={o.value}>
              <label
                className={`sf-filter-option${o.count === 0 && !checked ? ' is-empty' : ''}`}
              >
                <input
                  type="checkbox"
                  checked={checked}
                  disabled={o.count === 0 && !checked}
                  onChange={() =>
                    changeListing((q) => toggleValue(q, param, o.value))
                  }
                />
                {group.kind === 'rating' ? (
                  <span className="sf-filter-label">
                    <Stars value={o.stars ?? 0} size={16} />
                    <span className="sf-visually-hidden">{o.label}</span>
                  </span>
                ) : (
                  <span className="sf-filter-label">{o.label}</span>
                )}
                <span className="sf-filter-count">{o.count}</span>
              </label>
            </li>
          );
        })}
      </ul>
      {!search && matches.length > visible && (
        <button
          type="button"
          className="sf-filter-more"
          aria-expanded={more}
          onClick={() => setMore(!more)}
        >
          {more ? t('storefront.show_less') : t('storefront.show_more')}
        </button>
      )}
    </>
  );
}

function FilterGroup({
  group,
  visible,
  format,
}: {
  group: ListingFilterGroup;
  visible: number;
  format: CurrencyFormat;
}) {
  const [open, setOpen] = useState(true);
  return (
    <details
      className="sf-filter"
      open={open}
      onToggle={(e) => setOpen(e.currentTarget.open)}
    >
      <summary>{group.name}</summary>
      {group.kind === 'range' ? (
        <RangeFilter group={group} format={format} />
      ) : (
        <ChoiceFilter group={group} visible={visible} />
      )}
    </details>
  );
}

/** The product filters block: a sidebar on desktop, a full-screen panel opened from the listing below that width. */
export function ProductFilters({ block }: { block: PublicBlock }) {
  const t = useTranslation();
  const { open } = useFiltersPanel(true);
  const close = useRef<HTMLButtonElement>(null);
  const data = block.content.filters;
  useEffect(() => {
    if (!open) return;
    close.current?.focus();
    // Escape closes the panel wherever focus is (a loaded change can leave it on the page).
    const escape = (e: KeyboardEvent) => e.key === 'Escape' && closeFilters();
    document.addEventListener('keydown', escape);
    return () => document.removeEventListener('keydown', escape);
  }, [open]);
  if (!data) return null;
  const format = data.currency_format ?? FALLBACK_FORMAT;
  return (
    <div className="sf-filters">
      <div
        id="sf-filters-panel"
        className={`sf-filters-panel${open ? ' is-open' : ''}`}
        role={open ? 'dialog' : 'region'}
        aria-modal={open || undefined}
        aria-label={t('storefront.filters')}
      >
        <div className="sf-filters-head">
          <h2>{t('storefront.filters')}</h2>
          {data.active.length > 0 && (
            <button
              type="button"
              className="sf-filters-clear"
              onClick={() => changeListing(clearFilters)}
            >
              {t('storefront.filters_reset')}
            </button>
          )}
          <button
            ref={close}
            type="button"
            className="sf-filters-close"
            aria-label={t('storefront.close')}
            onClick={closeFilters}
          >
            ×
          </button>
        </div>
        {data.groups.map((group) => (
          <FilterGroup
            key={group.key}
            group={group}
            visible={data.visible_options}
            format={format}
          />
        ))}
        <div className="sf-filters-foot">
          <button
            type="button"
            className="sf-cart-button"
            onClick={closeFilters}
          >
            {t('storefront.filters_show', { count: data.total })}
          </button>
        </div>
      </div>
    </div>
  );
}

function SortIcon() {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      width="22"
      height="22"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <path d="M7 20V4M3 8l4-4 4 4M17 4v16M13 16l4 4 4-4" />
    </svg>
  );
}

function FilterIcon() {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      width="20"
      height="20"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <path d="M3 5h18l-7 8v6l-4 2v-8z" />
    </svg>
  );
}

/** The sort choice: the current order with the sort icon; opened, the current order on top and the others below. */
function SortMenu({
  value,
  options,
  onChange,
}: {
  value: string;
  options: string[];
  onChange: (sort: string) => void;
}) {
  const t = useTranslation();
  const [open, setOpen] = useState(false);
  const box = useRef<HTMLDivElement>(null);
  const trigger = useRef<HTMLButtonElement>(null);
  useEffect(() => {
    if (!open) return;
    const outside = (e: MouseEvent) =>
      !box.current?.contains(e.target as Node) && setOpen(false);
    const escape = (e: KeyboardEvent) => {
      if (e.key !== 'Escape') return;
      setOpen(false);
      trigger.current?.focus();
    };
    document.addEventListener('mousedown', outside);
    document.addEventListener('keydown', escape);
    return () => {
      document.removeEventListener('mousedown', outside);
      document.removeEventListener('keydown', escape);
    };
  }, [open]);
  const label = (sort: string) => t(`storefront.sort_${sort}`);
  return (
    <div className="sf-sort" ref={box}>
      <button
        ref={trigger}
        type="button"
        className="sf-sort-current"
        aria-expanded={open}
        aria-label={`${t('storefront.sort_by')}: ${label(value)}`}
        onClick={() => setOpen(!open)}
      >
        <SortIcon />
        {label(value)}
      </button>
      {open && (
        <ul className="sf-sort-menu" aria-label={t('storefront.sort_by')}>
          <li>
            <button
              type="button"
              className="sf-sort-current"
              aria-current="true"
              onClick={() => {
                setOpen(false);
                trigger.current?.focus();
              }}
            >
              <SortIcon />
              {label(value)}
            </button>
          </li>
          {options
            .filter((sort) => sort !== value)
            .map((sort) => (
              <li key={sort}>
                <button
                  type="button"
                  onClick={() => {
                    setOpen(false);
                    onChange(sort);
                  }}
                >
                  {label(sort)}
                </button>
              </li>
            ))}
        </ul>
      )}
    </div>
  );
}

/** Page links: first, last and two either side of the current page. */
export function Pagination({ page, last }: { page: number; last: number }) {
  const t = useTranslation();
  if (last < 2) return null;
  const href = (n: number) =>
    listingHref(
      window.location.pathname + window.location.search,
      (q) => (n > 1 ? q.set('page', String(n)) : q.delete('page')),
      true,
    );
  const pages = [
    ...new Set([1, page - 2, page - 1, page, page + 1, page + 2, last]),
  ]
    .filter((n) => n >= 1 && n <= last)
    .sort((a, b) => a - b);
  return (
    <nav className="sf-pagination" aria-label={t('storefront.pagination')}>
      {page > 1 && (
        <Link
          href={href(page - 1)}
          rel="prev"
          preserveScroll
          onSuccess={scrollToListing}
        >
          {t('storefront.previous')}
        </Link>
      )}
      {pages.map((n, i) => (
        <span key={n}>
          {i > 0 && n - pages[i - 1] > 1 && <span aria-hidden="true">…</span>}
          <Link
            href={href(n)}
            preserveScroll
            onSuccess={scrollToListing}
            aria-label={t('storefront.page_number', { page: n })}
            aria-current={n === page ? 'page' : undefined}
          >
            {n}
          </Link>
        </span>
      ))}
      {page < last && (
        <Link
          href={href(page + 1)}
          rel="next"
          preserveScroll
          onSuccess={scrollToListing}
        >
          {t('storefront.next')}
        </Link>
      )}
    </nav>
  );
}

/** The cards' compare buttons: the visitor's comparison list, its limit and a short notice after each change. */
function useComparison(initial: { max: number; ids: string[] } | null) {
  const t = useTranslation();
  const [ids, setIds] = useState(initial?.ids ?? []);
  const [notice, setNotice] = useState<{ text: string; link: boolean }>();
  const busy = useRef(false);
  useEffect(() => {
    if (!notice) return;
    const timer = window.setTimeout(() => setNotice(undefined), 5000);
    return () => window.clearTimeout(timer);
  }, [notice]);
  const toggle = async (id: string, item?: PublicItem) => {
    if (!initial || busy.current) return;
    const active = ids.includes(id);
    if (!active && ids.length >= initial.max) {
      setNotice({
        text: t('storefront.comparison_full', { max: initial.max }),
        link: true,
      });
      return;
    }
    busy.current = true;
    const result = await comparisonRequest(
      active ? 'DELETE' : 'PUT',
      `/comparison/products/${id}`,
    );
    busy.current = false;
    if (!result.ok) {
      setNotice({
        text: result.message || t('storefront.listing_error'),
        link: false,
      });
      return;
    }
    setIds(result.data.ids);
    if (!active)
      emitStorefrontEvent({
        type: 'add_to_comparison',
        currency: item?.currency,
        items: [
          {
            id,
            name: item?.name,
            sku: item?.sku,
            price: item?.price,
            quantity: 1,
          },
        ],
      });
    setNotice({
      text: t(
        active
          ? 'storefront.comparison_removed'
          : 'storefront.comparison_added',
      ),
      link: !active,
    });
  };
  return { value: initial ? { ids, toggle } : null, notice };
}

/** A product listing's main content block: the category or brand, its products and how they are filtered and sorted. */
/** The search page's heading: what was searched (the header's search field edits it). */
function SearchHeader({ query }: { query: string }) {
  const t = useTranslation();
  return (
    <header className="sf-search-header">
      <h1>
        {query
          ? t('storefront.search_results_for', { query })
          : t('storefront.search_title')}
      </h1>
    </header>
  );
}

export default function CategoryListing({ block }: { block: PublicBlock }) {
  const t = useTranslation();
  const { busy, failed } = useListingStatus();
  const data = block.content.listing;
  const comparison = useComparison(data?.comparison ?? null);
  const filtersPanel = useFiltersPanel();
  if (!data) return null;
  const search = data.search;
  // The search engine was not asked or could not answer: say so, never "no results".
  if (search && search.state !== 'results' && search.state !== 'degraded') {
    return (
      <div className="sf-landing sf-listing">
        <SearchHeader query={search.query} />
        <p
          className="sf-search-state"
          role={search.state === 'no_query' ? undefined : 'alert'}
        >
          {t(`storefront.search_${search.state}`, { count: search.min ?? 0 })}
        </p>
      </div>
    );
  }
  const category = data.category;
  if (category?.age_gate)
    return (
      <AgeGate
        age={category.age_gate.age}
        message={category.age_gate.message}
      />
    );
  const items = data.items ?? [];
  const active = data.active ?? [];
  const total = data.total ?? 0;
  const format = data.currency_format ?? FALLBACK_FORMAT;
  const display = data.display ?? {};
  const style = {
    '--sf-lp-cols': Math.min(5, Math.max(2, data.per_row ?? 3)),
    '--sf-lp-mobile-cols': data.mobile_per_row === 1 ? 1 : 2,
    '--sf-lp-tablet-cols': data.tablet_per_row === 3 ? 3 : 2,
    '--sf-w': `${display.image_width ?? 300}px`,
    '--sf-h': `${display.image_height ?? 260}px`,
  } as CSSProperties;
  const clear = (
    <button
      type="button"
      className="sf-chip-clear"
      onClick={() => changeListing(clearFilters)}
    >
      {t('storefront.filters_clear')}
    </button>
  );
  return (
    <div className="sf-landing sf-listing" aria-busy={busy}>
      {search && <SearchHeader query={search.query} />}
      {search?.state === 'degraded' && (
        <p className="sf-search-state" role="status">
          {t('storefront.search_degraded')}
        </p>
      )}
      {category && <CategoryHeader data={category} />}
      {data.brand && <BrandBanner data={data.brand} />}
      {category && category.items.length > 0 && (
        <nav
          className="sf-listing-subcats"
          aria-label={t('storefront.subcategories')}
        >
          <ul>
            {category.items.map((c) => (
              <li key={c.id}>
                {c.url ? <a href={c.url}>{c.name}</a> : c.name}
              </li>
            ))}
          </ul>
        </nav>
      )}
      <div className="sf-listing-bar">
        {data.show_count !== false && (
          <p className="sf-listing-count" role="status">
            {total === 1
              ? t('storefront.results_one')
              : t('storefront.results_many', { count: total })}
          </p>
        )}
        {data.show_sorting !== false && (data.sorts ?? []).length > 1 && (
          <SortMenu
            value={data.sort ?? data.default_sort ?? 'recommended'}
            options={data.sorts ?? []}
            onChange={(sort) =>
              changeListing((q) =>
                sort === data.default_sort
                  ? q.delete('sort')
                  : q.set('sort', sort),
              )
            }
          />
        )}
      </div>
      {active.length > 0 && (
        <div className="sf-listing-chips">
          <ul aria-label={t('storefront.filters_active')}>
            {active.map((filter) => {
              const key =
                filter.kind === 'range'
                  ? filter.param
                  : `${filter.param}=${filter.value}`;
              return (
                <li key={key}>
                  <button
                    type="button"
                    className="sf-chip"
                    aria-label={t('storefront.filter_remove', {
                      name:
                        filter.kind === 'range'
                          ? filter.name
                          : `${filter.name}: ${filter.label}`,
                    })}
                    onClick={() => removeFilter(filter)}
                  >
                    <ChipText filter={filter} format={format} />
                    <span aria-hidden="true">×</span>
                  </button>
                </li>
              );
            })}
          </ul>
          {clear}
        </div>
      )}
      {failed && (
        <p className="sf-listing-error" role="alert">
          {t('storefront.listing_error')}
        </p>
      )}
      {filtersPanel.present && (
        <button
          type="button"
          className="sf-listing-filters"
          aria-expanded={filtersPanel.open}
          aria-controls="sf-filters-panel"
          onClick={(e) => openFilters(e.currentTarget)}
        >
          <FilterIcon />
          {t('storefront.filters_open')}
          {active.length > 0 && (
            <span className="sf-filters-badge">{active.length}</span>
          )}
        </button>
      )}
      {items.length === 0 ? (
        <div className="sf-listing-empty">
          <p className="sf-pdp-empty">
            {active.length > 0
              ? t('storefront.listing_no_results')
              : search
                ? t('storefront.search_no_results', { query: search.query })
                : t('storefront.listing_empty')}
          </p>
          {active.length > 0 && clear}
        </div>
      ) : (
        <CardComparisonContext.Provider value={comparison.value}>
          <div
            className="sf-listing-grid sf-sized"
            data-mobile-columns={data.mobile_per_row === 1 ? 1 : 2}
            style={style}
          >
            {items.map((item) => (
              <Product
                key={item.id}
                item={item}
                display={display}
                format={format}
                mobileHorizontal={data.mobile_per_row === 1}
              />
            ))}
          </div>
        </CardComparisonContext.Provider>
      )}
      <p className="sf-listing-note" role="status">
        {comparison.notice && (
          <span>
            {comparison.notice.text}
            {comparison.notice.link && (
              <a href="/comparison">{t('storefront.comparison_view')}</a>
            )}
          </span>
        )}
      </p>
      <Pagination page={data.page ?? 1} last={data.last_page ?? 1} />
      {category && <CategoryPosts data={category} />}
      {category && <CategoryFaqs data={category} />}
    </div>
  );
}
