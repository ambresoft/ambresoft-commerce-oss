import { pageLabels, pageStateTone } from './pageLabels';

// Blogs and posts share the page states; a post can also be a draft (no publication date).
export const blogStateLabel = {
  ...pageLabels.state,
  draft: 'blogs.state_draft',
} as const;

export const blogStateTone = { ...pageStateTone, draft: 'neutral' } as const;
