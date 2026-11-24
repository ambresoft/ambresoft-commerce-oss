import type { Pagination } from './admin';

export type CategorySummary = {
  id: string;
  parentId: string | null;
  name: string;
  position: number;
  status: 'active' | 'disabled';
  system: boolean;
  version: number;
  childrenCount: number;
  resolvedLocale: string | null;
  translationState: 'system' | 'translated' | 'fallback';
  editUrl: string;
  childUrl: string;
  path?: string[];
};
export type CategoryBranch = {
  parentId: string | null;
  data: CategorySummary[];
  page: number;
  lastPage: number;
  total: number;
};
export type CategoryResults = Pagination<CategorySummary>;
export type CategoryTranslation = {
  name: string;
  description: string;
  seo_title: string;
  seo_description: string;
  slug: string;
  image_alt: string;
  meta_keywords: string;
  age_warning_message: string;
};
export type CategoryEditor = {
  faqIds?: string[];
  blogPostIds?: string[];
  id: string;
  displayName: string;
  childrenCount: number;
  parentId: string | null;
  position: number;
  status: 'active' | 'disabled';
  version: number;
  menuIconUrl?: string | null;
  menuIconMeta?: { size: number; width: number; height: number } | null;
  blockImageUrl: string | null;
  blockImageMeta?: { size: number; width: number; height: number } | null;
  bannerImageUrl?: string | null;
  bannerLink?: string;
  bannerImageMeta?: { size: number; width: number; height: number } | null;
  imageUrl: string | null;
  imageMeta?: { size: number; width: number; height: number } | null;
  excludeFromDiscovery: boolean;
  excludeFromSitemap?: boolean;
  /** The storefront shows the subcategories as cards instead of products. */
  isLandingPage?: boolean;
  /** Empty: every visitor. */
  customerGroups: string[];
  ageVerification: boolean;
  ageLimit: number | null;
  translations: Record<string, CategoryTranslation>;
};

export type ParentAgeRestriction = {
  parentId: string | null;
  verification: boolean;
  ageLimit: number | null;
  sourceCategoryId: string | null;
};
