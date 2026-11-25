import { router } from '@inertiajs/react';

export const TAGS_HEADER = 'X-Cms-Tags';
let marked = false;

/**
 * Third-party tags now run in this document (Google tags or consented scripts, A147/A149). Every later in-app visit says
 * so, and the server answers a visit to a private page with a full page load, which leaves those tags behind. Documents
 * without tags (a private page opened directly) keep normal in-app visits, partial reloads and redirects.
 */
export function markTagsLoaded() {
  if (marked) return;
  marked = true;
  router.on('before', (event) => {
    const visit = event.detail.visit;
    visit.headers = { ...visit.headers, [TAGS_HEADER]: '1' };
  });
}
