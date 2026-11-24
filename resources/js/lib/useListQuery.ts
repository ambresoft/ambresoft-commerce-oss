import { router } from '@inertiajs/react';

type Query = Record<string, string | number | null | undefined>;

/**
 * Applies list filter changes as a GET visit, dropping empty values. Any
 * filter change returns to the first page unless the change sets a page.
 */
export function useListQuery(base: string, current: Query) {
  return (changes: Query) => {
    const query: Record<string, string> = {};
    for (const [key, value] of Object.entries({
      ...current,
      page: undefined,
      ...changes,
    }))
      if (value !== '' && value !== null && value !== undefined)
        query[key] = String(value);
    router.get(base, query, {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    });
  };
}
