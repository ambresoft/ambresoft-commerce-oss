import { router } from '@inertiajs/react';
import {
  useEffect,
  useId,
  useRef,
  useState,
  type FormEvent,
  type KeyboardEvent,
  type ReactNode,
} from 'react';
import { useTranslation } from '../../../lib/useTranslation';
import type { CurrencyFormat, PublicImage } from '../../types';
import { Collection, Money, NoImage, Picture } from './parts';

type Suggestion = {
  price_from?: boolean;
  id: string;
  name: string;
  sku?: string | null;
  url?: string | null;
  image?: PublicImage | null;
  price?: string | null;
  base_price?: string | null;
  category?: { name: string; url: string | null } | null;
};

type Reply = {
  state: 'results' | 'unavailable';
  items: Suggestion[];
  total: number;
  currency_format: CurrencyFormat | null;
};

/** Typing waits this long before suggestions are asked for, so a word typed quickly costs one request. */
export const SUGGEST_DELAY = 250;

function currentQuery(): string {
  if (typeof window === 'undefined' || window.location.pathname !== '/search')
    return '';
  return new URLSearchParams(window.location.search).get('q') ?? '';
}

export function searchHref(query: string): string {
  return `/search?q=${encodeURIComponent(query.trim())}`;
}

/**
 * The header's search field: product suggestions while typing (the store's search engine, read through the CMS, which
 * shows only products the visitor may see) and the full results page on Enter. The field is a combobox: arrows move
 * through the suggestions and "see all results", Enter opens the highlighted one, Escape closes the list. Answers to
 * older keystrokes are cancelled or ignored, so the list always belongs to what is in the field.
 */
export default function SearchBox({
  limit,
  minLength = 0,
  icon,
}: {
  /** Suggestions to show; 0 turns them off. */
  limit: number;
  /** Characters a query needs before it is searched (the store's search setting; 0 for any). */
  minLength?: number;
  icon: ReactNode;
}) {
  const t = useTranslation();
  const listId = useId();
  const [query, setQuery] = useState(currentQuery);
  const [reply, setReply] = useState<Reply | null>(null);
  const [recent, setRecent] = useState<Suggestion[]>([]);
  const list = useRef<HTMLDivElement>(null);
  const [failed, setFailed] = useState(false);
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(-1);
  const request = useRef<AbortController | null>(null);
  const text = query.trim();
  // Suggestions need at least one character and the store's minimum.
  const enough = text.length >= Math.max(1, minLength);
  const wanted = limit > 0 && enough;
  // Set when the shopper submits too short a query; typing again clears it.
  const [tooShort, setTooShort] = useState(false);

  useEffect(() => {
    if (!wanted) return;
    const timer = window.setTimeout(async () => {
      request.current?.abort();
      const controller = new AbortController();
      request.current = controller;
      try {
        const response = await fetch(
          `/search/suggest?q=${encodeURIComponent(text)}&limit=${limit}`,
          {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: controller.signal,
          },
        );
        const json = (await response.json()) as Reply;
        if (controller.signal.aborted) return;
        setReply(response.ok ? json : null);
        setFailed(!response.ok || json.state !== 'results');
        setActive(-1);
      } catch (error) {
        if ((error as Error).name === 'AbortError') return;
        setReply(null);
        setFailed(true);
      }
    }, SUGGEST_DELAY);
    return () => {
      window.clearTimeout(timer);
      request.current?.abort();
    };
  }, [text, limit, wanted]);
  useEffect(() => () => request.current?.abort(), []);

  useEffect(() => {
    if (!open) return;
    const controller = new AbortController();
    // Fetch once per opening, including when the shopper starts with a typed query.
    const timer = window.setTimeout(async () => {
      try {
        const response = await fetch('/search/recently-viewed', {
          headers: { Accept: 'application/json' },
          credentials: 'same-origin',
          signal: controller.signal,
        });
        const json = (await response.json()) as { items: Suggestion[] };
        if (!controller.signal.aborted) {
          setRecent(response.ok && Array.isArray(json.items) ? json.items : []);
          setActive(-1);
        }
      } catch (error) {
        if (
          !controller.signal.aborted &&
          (error as Error).name !== 'AbortError'
        )
          setRecent([]);
      }
    }, SUGGEST_DELAY);
    return () => {
      window.clearTimeout(timer);
      controller.abort();
    };
  }, [open]);
  const showingRecent = open && recent.length > 0;
  const suggested = wanted && !failed ? (reply?.items ?? []) : [];
  const historyCount = showingRecent ? recent.length : 0;
  const items = [...(showingRecent ? recent : []), ...suggested];
  const shown =
    open &&
    (tooShort || showingRecent || (wanted && (reply !== null || failed)));
  // History options come first, followed by query matches and "see all results".
  const count = items.length + (wanted && reply !== null && !failed ? 1 : 0);
  useEffect(() => {
    if (shown && active >= 0) {
      list.current
        ?.querySelector<HTMLElement>('[aria-selected="true"]')
        ?.scrollIntoView?.({ block: 'nearest', inline: 'nearest' });
    }
  }, [active, shown]);
  const submit = (event: FormEvent) => {
    event.preventDefault();
    const chosen = active >= 0 && active < items.length ? items[active] : null;
    setOpen(false);
    if (chosen?.url) {
      window.location.assign(chosen.url);
      return;
    }
    if (!enough) {
      setTooShort(true);
      setOpen(true);
      return;
    }
    router.visit(searchHref(text));
  };
  const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
    if (event.key === 'Escape') {
      // Escape closes the open list; only then does a second Escape clear the field (the browser's own behaviour).
      if (shown) event.preventDefault();
      setOpen(false);
      setActive(-1);
      return;
    }
    if (!shown || count === 0) return;
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      // -1 is the field itself; the list wraps around through it.
      setActive((current) =>
        event.key === 'ArrowDown'
          ? current + 1 >= count
            ? -1
            : current + 1
          : current - 1 < -1
            ? count - 1
            : current - 1,
      );
    }
  };
  const optionId = (n: number) => `${listId}-option-${n}`;
  const format = reply?.currency_format ?? null;
  return (
    <form
      className="sf-search"
      role="search"
      action="/search"
      onSubmit={submit}
    >
      <input
        type="search"
        name="q"
        value={query}
        maxLength={100}
        placeholder={t('storefront.search_placeholder')}
        aria-label={t('storefront.search')}
        role="combobox"
        aria-autocomplete="list"
        aria-expanded={shown}
        aria-controls={listId}
        aria-activedescendant={
          shown && active >= 0 ? optionId(active) : undefined
        }
        autoComplete="off"
        onChange={(event) => {
          setQuery(event.target.value);
          setActive(-1);
          setTooShort(false);
          setOpen(true);
        }}
        onFocus={() => setOpen(true)}
        onBlur={() => setOpen(false)}
        onKeyDown={onKeyDown}
      />
      <button type="submit" aria-label={t('storefront.search')}>
        {icon}
      </button>
      <div
        ref={list}
        id={listId}
        role="listbox"
        aria-label={t('storefront.search_suggestions')}
        className="sf-suggest"
        hidden={!shown}
        // Choosing with the mouse must not blur the field first.
        onMouseDown={(event) => event.preventDefault()}
      >
        {showingRecent && (
          <section
            className="sf-search-recent"
            aria-labelledby={`${listId}-recent-title`}
          >
            <h2 id={`${listId}-recent-title`}>
              {t('storefront.recently_viewed')}
            </h2>
            <Collection
              block={{
                id: `${listId}-recent`,
                template: 'product_carousel',
                title: t('storefront.recently_viewed'),
                css_class: '',
                mobile: true,
                tablet: true,
                desktop: true,
                content: {
                  items: recent,
                  display: {
                    navigation: 'inside',
                    drag_scroll: true,
                    hide_arrows_mobile: true,
                    per_row: 8,
                    image_width: 88,
                    image_height: 100,
                  },
                },
              }}
              renderItem={(item, n) => (
                <a
                  id={optionId(n)}
                  role="option"
                  aria-selected={active === n}
                  className="sf-search-recent-item"
                  href={item.url ?? '/'}
                  tabIndex={-1}
                  title={item.name}
                >
                  {item.image ? (
                    <Picture image={item.image} alt="" eager />
                  ) : (
                    <NoImage />
                  )}
                  <span className="sf-visually-hidden">{item.name}</span>
                </a>
              )}
            />
          </section>
        )}
        {tooShort ? (
          <p className="sf-suggest-note" role="alert">
            {t('storefront.search_too_short', {
              count: Math.max(1, minLength),
            })}
          </p>
        ) : wanted && failed ? (
          <p className="sf-suggest-note">
            {t('storefront.search_suggestions_error')}
          </p>
        ) : wanted && reply !== null ? (
          <>
            {suggested.length === 0 && (
              <p className="sf-suggest-note">
                {t('storefront.search_no_suggestions', { query: text })}
              </p>
            )}
            {suggested.map((item, n) => (
              <a
                key={item.id}
                id={optionId(historyCount + n)}
                role="option"
                aria-selected={active === historyCount + n}
                className="sf-suggest-item"
                href={item.url ?? searchHref(text)}
                tabIndex={-1}
              >
                <span className="sf-suggest-picture">
                  {item.image ? (
                    <Picture image={item.image} alt="" />
                  ) : (
                    <NoImage />
                  )}
                </span>
                <span className="sf-suggest-text">
                  <strong>{item.name}</strong>
                  {(item.category?.name || item.sku) && (
                    <small>
                      {[item.category?.name, item.sku]
                        .filter(Boolean)
                        .join(' · ')}
                    </small>
                  )}
                </span>
                {item.price != null && format && (
                  <span className="sf-suggest-price">
                    {item.price_from && (
                      <span>{t('storefront.price_from')} </span>
                    )}
                    <Money amount={item.price} format={format} inline />
                  </span>
                )}
              </a>
            ))}
            <a
              id={optionId(historyCount + suggested.length)}
              role="option"
              aria-selected={active === historyCount + suggested.length}
              className="sf-suggest-all"
              href={searchHref(text)}
              tabIndex={-1}
            >
              {t('storefront.search_view_all', { query: text })}
            </a>
          </>
        ) : null}
      </div>
      <p className="sf-visually-hidden" role="status">
        {shown && wanted && !failed && reply
          ? t('storefront.results_many', { count: reply.total })
          : ''}
      </p>
    </form>
  );
}
