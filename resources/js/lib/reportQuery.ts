import { usePage } from '@inertiajs/react';

/** The current URL's path and query, to build links that change one parameter and keep the rest (filters, sort, view). */
export function useReportUrl() {
  const { url } = usePage();
  const [path, query = ''] = url.split('?');
  const params = new URLSearchParams(query);
  /** `updates` with null/'' removes a parameter; `page` is dropped unless given, so a changed filter starts at page 1. */
  const href = (updates: Record<string, string | number | null>) => {
    const next = new URLSearchParams(params);
    next.delete('page');
    for (const [key, value] of Object.entries(updates)) {
      if (value === null || value === '') next.delete(key);
      else next.set(key, String(value));
    }
    const text = next.toString();
    return path + (text ? '?' + text : '');
  };
  return { path, params, href, search: query ? '?' + query : '' };
}
