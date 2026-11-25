import type { AppliedPromotion } from './promotions';
import type { AddressInput } from './customers';

export type OrderStatusOption = {
  id: string;
  code: string;
  name: string;
  color: string;
  active: boolean;
  initial: boolean;
  final: boolean;
  stock_action: 'none' | 'decrease' | 'restore';
  position: number;
  system: boolean;
  /** Entering this status emails the invoice (once per order). */
  invoice: boolean;
  /** Customers may cancel their own order while it is in this status. */
  customer_can_cancel: boolean;
};
export type OrderRow = {
  id: string;
  number: string;
  version: number;
  customer: string;
  email: string;
  phone: string | null;
  status: OrderStatusOption | null;
  items: number;
  units: number;
  total: string;
  currency: string;
  created_at: string;
  updated_at: string;
  ip_address: string | null;
};
export type OrderAddress = {
  first_name: string;
  last_name: string;
  company: string | null;
  line1: string;
  line2: string | null;
  city: string;
  postal_code: string | null;
  country_code: string;
  country: string;
  region_code: string | null;
  /** Region name from Geography, when the saved code is a known region. */
  region?: string | null;
  phone: string | null;
  floor?: string | null;
  bell_name?: string | null;
  notes?: string | null;
};
export type OrderEmailResult = {
  id: string;
  kind: 'status' | 'invoice';
  state: 'pending' | 'sending' | 'sent' | 'failed';
  attempts: number;
  recipient: string;
  subject: string | null;
  error: string | null;
  sent_at: string | null;
  last_attempt_at: string | null;
};
export type OrderDetail = {
  promotions?: AppliedPromotion[];
  id: string;
  number: string;
  version: number;
  status: OrderStatusOption | null;
  locale: string;
  customer_id: string | null;
  first_name: string;
  last_name: string;
  email: string;
  phone: string | null;
  ip_address: string | null;
  currency: string;
  tax_method: string;
  tax_exempt: boolean;
  stock_held: boolean;
  totals: {
    subtotal: string;
    discount: string;
    has_discount: boolean;
    tax: string;
    tax_added: string;
    has_added_tax: boolean;
    tax_included: string;
    tax_included_raw: string;
    shipping: string;
    /** The payment method's fee (e.g. cash on delivery), when the order has one. */
    payment_fee?: string | null;
    total: string;
  };
  billing: OrderAddress | null;
  shipping: OrderAddress | null;
  coupon_code?: string;
  coupon_id?: string | null;
  customer_note: string | null;
  internal_note: string | null;
  /** The buyer asked for an invoice at checkout. */
  invoice_requested: boolean;
  invoice_details?: {
    country_code: string;
    country?: string;
    vat_number: string;
    business_name: string | null;
    provider: string;
    verified_at: string;
  } | null;
  created_at: string;
  updated_at: string;
  items: {
    id: string;
    product_id: string | null;
    image_url?: string | null;
    name: string;
    sku: string | null;
    variation: string | null;
    quantity: number;
    deducted: number;
    unit_price: string;
    unit_net: string;
    vat_rate: string;
    unit_gross: string;
    catalog_price: string;
    price_overridden: boolean;
    discount?: string | null;
    subtotal: string;
    tax: string;
    tax_included: boolean;
    total: string;
  }[];
  taxes: {
    name: string;
    included: boolean;
    rate: string | null;
    amount: string;
  }[];
  history: {
    id: string;
    from: OrderStatusOption | null;
    to: OrderStatusOption | null;
    at: string;
    actor: string | null;
    source: 'admin' | 'system' | 'checkout';
    note: string | null;
    emails: OrderEmailResult[];
  }[];
  comments: {
    id: string;
    visibility: 'internal' | 'customer';
    body: string;
    author: string | null;
    at: string;
  }[];
};
export type OrderAddressInput = Omit<AddressInput, 'label'>;
export type OrderLineInput = {
  key: string;
  item_id: string | null;
  product_id: string | null;
  name: string;
  sku: string | null;
  variation: string | null;
  quantity: string;
  unit_price: string;
  catalog_price: string;
  price_overridden: boolean;
};
export type OrderEditor = {
  id: string;
  number: string;
  version: number;
  status: OrderStatusOption | null;
  customer_id: string;
  locale: string;
  first_name: string;
  last_name: string;
  email: string;
  phone: string;
  billing: OrderAddressInput | null;
  shipping: OrderAddressInput | null;
  coupon_code?: string;
  coupon_id?: string | null;
  customer_note: string;
  internal_note: string;
  currency: string;
  editable_currency: boolean;
  payment_method_id: string;
  payment_method_name: string | null;
  shipping_method_id: string;
  shipping_method_name: string | null;
  /** A staff-entered shipping charge; empty when the calculated charge applies. */
  shipping_amount: string;
  items: {
    item_id: string;
    product_id: string | null;
    name: string;
    sku: string | null;
    variation: string | null;
    quantity: number;
    unit_price: string;
    catalog_price: string;
    price_overridden: boolean;
  }[];
};
export type OrderProductOption = {
  id: string;
  name: string;
  sku: string | null;
  status: string;
  quantity: number;
  price: string;
  price_formatted: string;
};
export type OrderCustomerOption = {
  id: string;
  name: string;
  first_name: string;
  last_name: string;
  email: string;
  phone: string;
  status: string;
  billing: OrderAddressInput | null;
  shipping: OrderAddressInput | null;
};
export type OrderQuote = {
  promotions?: AppliedPromotion[];
  discount?: string;
  coupon_code?: string | null;
  shipping_discount?: string;
  lines: {
    key: string;
    name: string;
    sku: string | null;
    variation: string | null;
    unit_price: string;
    subtotal: string;
    tax: string;
    total: string;
  }[];
  taxes: { name: string; included: boolean; amount: string }[];
  subtotal: string;
  tax: string;
  tax_included: string;
  shipping: string;
  payment_fee?: string | null;
  total: string;
  unresolved: string[];
  exempt: boolean;
  shipping_status: {
    available: boolean;
    message: string | null;
    calculated: string | null;
    overridden: boolean;
  } | null;
};
export type OrderShippingChoice = {
  id: string;
  name: string;
  calculation: 'manual' | 'carrier';
  /** Payment methods this shipping method is limited to; empty means all. */
  payment_method_ids: string[];
};
