export type StickerCondition = {
  reference?: string;
  type: string;
  minimum: string;
  maximum: string;
  category_id: string;
  feature_id: string;
  option_id: string;
  include_descendants: boolean;
  boolean_value: boolean | null;
};
export type StickerText = { text: string; short_text: string; tooltip: string };
export type StickerData = {
  version: number;
  name: string;
  type: string;
  style: string;
  status: string;
  starts_at: string;
  ends_at: string;
  priority: string;
  match: string;
  placement: string;
  show_card: boolean;
  show_detail: boolean;
  show_mobile: boolean;
  show_tablet: boolean;
  show_desktop: boolean;
  is_filter: boolean;
  background: string;
  color: string;
  border_color: string;
  border_width: string;
  radius: string;
  padding: string;
  font_size: string;
  alignment: string;
  image_width: string;
  css_class: string;
  image_media_id: string;
  image: File | null;
  translations: Record<string, StickerText>;
  conditions: StickerCondition[];
  products: string[];
};
export type StickerDetail = Omit<StickerData, 'image'> & {
  id: string;
  image: string | null;
  /** Generated from the name on save; the value of ?product-label= in shopping filters. */
  filter_slug: string;
  state: string;
  assigned_count: number;
  updated_at: string;
};
export type StickerOption = {
  id: string;
  name: string;
  type?: string;
  feature_id?: string;
};
export type StickerLimits = {
  version: number;
  card_limit: number;
  detail_limit: number;
};
export const conditionTypes = [
  'price',
  'discount_percent',
  'discount_amount',
  'category',
  'feature',
  'in_stock',
  'out_of_stock',
  'quantity',
  'purchasable',
  'new',
  'sales',
  'free_shipping',
];
export const emptyCondition = (type: string): StickerCondition => ({
  type,
  minimum: '',
  maximum: type === 'new' ? '14' : '',
  category_id: '',
  feature_id: '',
  option_id: '',
  include_descendants: true,
  boolean_value: null,
});
