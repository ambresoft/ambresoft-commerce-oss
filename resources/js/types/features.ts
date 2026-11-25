export type FeatureText = {
  name: string;
  description: string;
};
export type FeaturePurpose = 'standard' | 'landing_pages' | 'variations';
export type VariationStyle = 'text' | 'color' | 'image' | 'dropdown';
// Landing fields are present only for Brand / Author Landing Pages features.
export type FeatureOptionText = {
  label: string;
  description?: string;
  seo_title?: string;
  seo_description?: string;
  meta_keywords?: string;
  slug?: string;
  suggest_slug?: boolean;
};
export type FeatureOption = {
  id: string;
  version: number;
  status: string;
  label: string;
  products?: number;
  translations: Record<string, FeatureOptionText>;
  image_url?: string | null;
  landing_eligible?: boolean;
  // Present only for Variations as separate products; shared across languages.
  swatch_color?: string | null;
  swatch_image_url?: string | null;
  image_meta?: { size: number; width: number; height: number } | null;
  swatch_meta?: { size: number; width: number; height: number } | null;
};
export type FeatureGroup = {
  id: string;
  version: number;
  name: string;
  protected: boolean;
  translations: Record<string, { name: string; description: string }>;
};
export type Feature = {
  id: string;
  version: number;
  code: string;
  name: string;
  type: string;
  purpose: FeaturePurpose;
  variation_style?: VariationStyle;
  variation_groups?: number;
  products?: number;
  status: string;
  incomplete: boolean;
  group_id: string;
  unit: string;
  required: boolean;
  filterable: boolean;
  comparison: boolean;
  categories: string[];
  translations: Record<string, FeatureText>;
  options: FeatureOption[];
  readonly?: boolean;
  historical?: boolean;
};
export type FeatureValue = {
  version: number;
  number: string;
  boolean: string;
  texts: Record<string, string>;
  options: string[];
};
export type FeatureEditor = {
  definitions: Feature[];
  values: Record<string, FeatureValue>;
  groups: FeatureGroup[];
  catalog_version: number;
  missing: string[];
};
export type ImpactPreview = {
  impact: Record<string, number>;
  token: string;
} | null;
export const featureTypes = [
  'text',
  'single',
  'number',
  'boolean',
  'multiple',
] as const;
export const featurePurposes = [
  'standard',
  'landing_pages',
  'variations',
] as const;
export const variationStyles = ['text', 'color', 'image', 'dropdown'] as const;
export const landingFields = [
  'description',
  'seo_title',
  'seo_description',
  'meta_keywords',
  'slug',
] as const;
export const emptyFeatureText: FeatureText = {
  name: '',
  description: '',
};
export const emptyFeatureValue = (): FeatureValue => ({
  version: 0,
  number: '',
  boolean: '',
  texts: {},
  options: [],
});
