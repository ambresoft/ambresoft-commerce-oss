import type { ImageMeta } from '../components/admin/ImageUploader';
import type { PageStatus } from './pages';

export type BlogKind = 'blog' | 'post';

export type BlogState =
  'live' | 'draft' | 'hidden' | 'disabled' | 'scheduled' | 'expired';

export type BlogRow = {
  id: string;
  name: string;
  slug: string;
  status: PageStatus;
  state: BlogState;
  version: number;
  // Blogs
  position?: number;
  posts_count?: number;
  // Posts
  blog_id?: string;
  blog?: string;
  published_at?: string | null;
  cover?: string | null;
};

export type BlogText = {
  name: string;
  content: string;
  slug: string;
  seo_title: string;
  meta_description: string;
  meta_keywords: string;
  // Posts
  excerpt?: string;
  cover_alt?: string;
  tags?: string;
};

export type BlogEditor = {
  id: string;
  /** The storefront address with preview on. */
  preview_url: string | null;
  version: number;
  status: PageStatus;
  state: BlogState;
  facebook_type: string;
  available_from: string | null;
  available_until: string | null;
  translations: Record<string, BlogText>;
  position?: number;
  blog_id?: string;
  published_at?: string | null;
  cover?: { url: string; meta?: ImageMeta | null } | null;
  cover_mobile?: { url: string; meta?: ImageMeta | null } | null;
};
