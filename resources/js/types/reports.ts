import type { Pagination } from './admin';

export type Currency = { code: string; decimals: number };
export type SelectOption = { value: string; label: string };

/** Period, currency, filter values and the choices every report screen shares. */
export type ReportCommon = {
  report: string;
  applicable: string[];
  period: {
    preset: string;
    from: string;
    to: string;
    days: number;
    granularity: 'hour' | 'day' | 'week' | 'month';
    timezone: string;
  };
  previous: { from: string; to: string };
  filters: {
    compare: boolean;
    currency: string;
    date: 'paid' | 'created';
    status_id: number | null;
    payment_method_id: number | null;
    shipping_method_id: number | null;
    product_id: number | null;
    category_id: number | null;
    customer_id: number | null;
  };
  currency: Currency;
  currencies: string[];
  other_currencies: { currency: string; orders: number }[];
  options: {
    statuses: (SelectOption & { void: boolean })[];
    payment_methods: SelectOption[];
    shipping_methods: SelectOption[];
    categories: SelectOption[];
  };
  links: {
    can_customers: boolean;
    can_export: boolean;
    can_orders: boolean;
    can_products: boolean;
    can_categories: boolean;
    can_brands?: boolean;
  };
};

/** Decimal strings (exact) with display-formatted twins; `null` means not available or not attributable. */
export type SalesSummary = {
  orders: string;
  units: string;
  gross: string | null;
  discounts: string | null;
  refunds: string | null;
  net: string | null;
  shipping: string | null;
  shipping_discount: string | null;
  /** Payment method fees charged (e.g. cash on delivery). */
  payment_fees?: string | null;
  tax_added: string | null;
  tax_included: string | null;
  total: string | null;
  aov: string | null;
  gross_formatted: string | null;
  discounts_formatted: string | null;
  net_formatted: string | null;
  shipping_formatted: string | null;
  shipping_discount_formatted: string | null;
  payment_fees_formatted?: string | null;
  tax_added_formatted: string | null;
  tax_included_formatted: string | null;
  total_formatted: string | null;
  aov_formatted: string | null;
};
export type SeriesPoint = { at: string; orders: number; net: string };
export type SalesContext = {
  placed: number;
  unpaid_orders: number;
  unpaid_value_formatted: string;
  void_orders: number;
  void_value_formatted: string;
  collected_on_void_orders: number;
  collected_on_void_formatted: string;
};
export type ProductRow = {
  product_id: string | null;
  name: string;
  sku: string | null;
  exists: boolean;
  trashed: boolean;
  status: string | null;
  units?: number;
  orders?: number;
  gross?: string;
  discounts?: string;
  net?: string;
  net_raw?: string;
  previous_units?: number;
  change?: number;
  type?: string;
  created_at?: string;
};
export type CategoryRow = {
  id: string;
  name: string;
  path: string;
  exists: boolean;
  units: number;
  orders: number;
  gross_formatted: string;
  discounts_formatted: string;
  net_formatted: string;
  net: string;
};
/** Sales of one brand, from the brand each line was sold under. */
export type BrandRow = {
  id: string;
  name: string;
  code: string | null;
  exists: boolean;
  units: number;
  orders: number;
  products: number;
  gross_formatted: string;
  discounts_formatted: string;
  net_formatted: string;
  net: string;
  /** Percentage of all net merchandise in the period. */
  share: string;
  previous_net_formatted?: string | null;
  /** Percentage change against the previous period; null without comparison or previous sales. */
  change: string | null;
};
export type TimingPoint = {
  key: number;
  orders: number;
  net: string;
  net_formatted: string;
};
export type { Pagination };
