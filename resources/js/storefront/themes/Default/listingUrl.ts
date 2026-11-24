import { router } from '@inertiajs/react';
import { useEffect, useState, useSyncExternalStore } from 'react';

/**
 * The product listing's state lives in its address only (filters, sort, page), so the filters block and the listing
 * block never disagree, reloading restores it and back/forward walk through it. While a change is on its way the
 * pending address is the state, so quick successive clicks build on each other; Inertia cancels the older visit, so
 * a slow earlier answer cannot overwrite a newer choice.
 */
let pending: string | null = null;
const listeners = new Set<() => void>();

function setPending(href: string | null) {
  pending = href;
  listeners.forEach((listener) => listener());
}

function subscribe(listener: () => void) {
  listeners.add(listener);
  return () => listeners.delete(listener);
}

function current() {
  return pending ?? window.location.pathname + window.location.search;
}

/** The listing's current (or pending) query, updated on every change. */
export function useListingQuery(): URLSearchParams {
  const href = useSyncExternalStore(subscribe, current);
  return new URL(href, 'http://listing').searchParams;
}

/**
 * The address after a change: parameters are sorted so equal states have one address, commas stay readable, and
 * the page number is dropped unless the change keeps it (a new filter can leave fewer pages).
 */
export function listingHref(
  href: string,
  change: (query: URLSearchParams) => void,
  keepPage = false,
): string {
  const url = new URL(href, 'http://listing');
  change(url.searchParams);
  if (!keepPage) url.searchParams.delete('page');
  url.searchParams.sort();
  const query = url.searchParams.toString().replaceAll('%2C', ',');
  return url.pathname + (query ? `?${query}` : '');
}

/** Adds or removes one value of a comma-separated parameter; `single` keeps at most one value. */
export function toggleValue(
  query: URLSearchParams,
  param: string,
  value: string,
  single = false,
) {
  const values = (query.get(param) ?? '').split(',').filter(Boolean);
  const next = values.includes(value)
    ? values.filter((v) => v !== value)
    : single
      ? [value]
      : [...values, value];
  if (next.length) query.set(param, next.join(','));
  else query.delete(param);
}

/** Every filter off; the sort order and a search's text stay. */
export function clearFilters(query: URLSearchParams) {
  for (const key of [...query.keys()])
    if (key !== 'sort' && key !== 'q') query.delete(key);
}

/**
 * Brings the visitor back to the top of the listing (smoothly, unless they asked for reduced motion) once new
 * products arrive; nothing moves when the listing's top is already in view.
 */
export function scrollToListing() {
  const listing = document.querySelector('.sf-listing');
  if (!listing || listing.getBoundingClientRect().top >= 0) return;
  const reduce = window.matchMedia?.(
    '(prefers-reduced-motion: reduce)',
  ).matches;
  listing.scrollIntoView({
    behavior: reduce ? 'auto' : 'smooth',
    block: 'start',
  });
}

/** Applies a change to the listing's address and loads it without leaving the page. */
export function changeListing(
  change: (query: URLSearchParams) => void,
  keepPage = false,
) {
  const href = listingHref(current(), change, keepPage);
  setPending(href);
  router.get(
    href,
    {},
    {
      preserveState: true,
      preserveScroll: true,
      onSuccess: scrollToListing,
      // A newer change cancels this visit and owns the pending address.
      onFinish: () => {
        if (pending === href) setPending(null);
      },
    },
  );
}

/** Whether the listing is loading a change, and whether the last change failed to arrive. */
export function useListingStatus() {
  const [busy, setBusy] = useState(false);
  const [failed, setFailed] = useState(false);
  useEffect(() => {
    const stops = [
      router.on('start', () => {
        setBusy(true);
        setFailed(false);
      }),
      router.on('finish', () => setBusy(false)),
      router.on('networkError', () => setFailed(true)),
      router.on('httpException', () => setFailed(true)),
    ];
    return () => stops.forEach((stop) => stop());
  }, []);
  return { busy, failed };
}

/**
 * The filters panel lives in the filters block, but below desktop width it opens from a button in the listing block,
 * right above the products. The button shows only while a filters block is on the page; closing the panel returns
 * focus to it.
 */
const panel = { open: false, mounted: 0, opener: null as HTMLElement | null };
const panelListeners = new Set<() => void>();

function panelChanged() {
  panelListeners.forEach((listener) => listener());
}

function subscribePanel(listener: () => void) {
  panelListeners.add(listener);
  return () => panelListeners.delete(listener);
}

export function openFilters(opener: HTMLElement) {
  panel.opener = opener;
  panel.open = true;
  panelChanged();
}

export function closeFilters() {
  panel.open = false;
  panelChanged();
  panel.opener?.focus();
}

/** Whether the filters panel is open; `register` marks the filters block as present while it is mounted. */
export function useFiltersPanel(register = false) {
  useEffect(() => {
    if (!register) return;
    panel.mounted++;
    panelChanged();
    return () => {
      panel.mounted--;
      panelChanged();
    };
  }, [register]);
  const open = useSyncExternalStore(subscribePanel, () => panel.open);
  const present = useSyncExternalStore(subscribePanel, () => panel.mounted > 0);
  return { open, present };
}
