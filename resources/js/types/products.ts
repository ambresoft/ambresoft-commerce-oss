export type ProductSale = {
  id: string;
  customer_group_id: string;
  minimum_quantity: string;
  priority: string;
  kind: string;
  amount: string;
  stock_limit: string;
  starts_on: string;
  ends_on: string;
  starts_time: string;
  ends_time: string;
  /** Optional sale title per language code. */
  titles: Record<string, string>;
  used: number;
};
import type { ProductTaxOption } from './taxes';
export type ProductText = {
  name: string;
  description: string;
  short_description: string;
  seo_title: string;
  seo_description: string;
  meta_keywords: string;
  slug: string;
  regenerate_slug?: boolean;
};
/** download: delivered to buyers of a digital product; attachment: product document for any product. */
export type FilePurpose = 'download' | 'attachment';
export type ProductFile = {
  id: string;
  display_name: string;
  original_name: string;
  byte_size?: number;
  /** Older payloads without a purpose are downloads. */
  purpose?: FilePurpose;
  replacement?: number;
};
export type ProductEditor = {
  brand_id?: string;
  similar_products?: boolean;
  facebook_type?: string;
  exclude_from_sitemap?: boolean;
  reviews_enabled?: boolean;
  companion_ids?: string[];
  sticker_ids?: string[];
  /** The public storefront address while visitors can see the product. */
  storefront_url?: string | null;
  sales?: ProductSale[];
  in_stock_status_id?: string | null;
  out_of_stock_status_id?: string | null;
  availability?: {
    id: string;
    name: string;
    bucket: string;
    inherited: boolean;
  };
  faq_ids?: string[];
  id: string;
  version: number;
  sku: string | null;
  ean: string | null;
  mpn: string | null;
  isbn: string | null;
  upc: string | null;
  returnable: boolean;
  type: string;
  price: string | null;
  list_price: string | null;
  quantity: number;
  min_qty: number | null;
  max_qty: number | null;
  qty_step: number | null;
  /** null follows the general setting; N or B overrides it for this product. */
  out_of_stock_actions: string | null;
  tax_ids: string[];
  taxes: ProductTaxOption[];
  general_out_of_stock_action: string;
  status: string;
  category_id: string;
  /** Extra categories the product is also listed in; the main category sets its address and breadcrumb. */
  extra_category_ids?: string[];
  weight: string | null;
  length: string | null;
  width: string | null;
  height: string | null;
  free_shipping: boolean;
  shipping_cost: string | null;
  /** Y-m-d release/pre-order date, or null. */
  available_from: string | null;
  box_min_items: number | null;
  box_max_items: number | null;
  box_length: string | null;
  box_width: string | null;
  box_height: string | null;
  /** Empty: every visitor. */
  customer_groups: string[];
  incomplete: boolean;
  translations: Record<string, ProductText>;
  images: string[];
  primary_image: string;
  files: ProductFile[];
  image_meta?: Record<string, { size: number; width: number; height: number }>;
};
export type ProductRow = {
  sticker_count?: number;
  id: string;
  name: string;
  confirmationName: string;
  version: number;
  sku: string | null;
  type: string;
  price: string | null;
  price_formatted: string | null;
  quantity: number;
  low_stock?: boolean;
  list_price_formatted: string | null;
  status: string;
  category: string[];
  incomplete: boolean;
  updated_at: string;
  image: string | null;
  variation_group: { id: string; name: string } | null;
};
export type ProductOption = { id: string; name: string };
