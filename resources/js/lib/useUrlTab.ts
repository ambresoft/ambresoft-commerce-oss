import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

// The tab open when a form was submitted, so the redirect back to the same editor reopens it.
let carried: { path: string; tab: string } | null = null;

/**
 * Keeps an editor's active tab in the `?tab=` query parameter so it survives refreshes,
 * shared links and save redirects. The first tab is the default and is left out of the URL.
 */
export function useUrlTab<T extends string>(
  tabs: readonly T[],
): [T, (tab: T) => void] {
  const url = usePage().url;
  const [tab, setTab] = useState<string | null>(
    () =>
      new URLSearchParams(window.location.search).get('tab') ??
      (carried?.path === window.location.pathname ? carried.tab : null),
  );
  const active = tabs.find((item) => item === tab) ?? tabs[0];
  const fallback = tabs[0];

  useEffect(() => {
    const next = new URL(window.location.href);
    if (active === fallback) next.searchParams.delete('tab');
    else next.searchParams.set('tab', active);
    if (next.href !== window.location.href)
      router.replace({
        url: next.pathname + next.search + next.hash,
        preserveState: true,
        preserveScroll: true,
      });
  }, [active, fallback, url]);

  useEffect(
    () =>
      router.on('start', (event) => {
        carried =
          event.detail.visit.method === 'get'
            ? null
            : { path: window.location.pathname, tab: active };
      }),
    [active],
  );

  return [active, setTab];
}
