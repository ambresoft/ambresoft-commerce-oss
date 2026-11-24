export type PromotionOption = { id: string; name: string };
export type TargetKind = 'products' | 'categories' | 'groups' | 'shipping';
export type PromotionCondition = {
  type:
    'subtotal' | 'products' | 'categories' | 'quantity' | 'groups' | 'shipping';
  minimum: string;
  maximum: string;
  quantity: string;
  quantity_scope: 'cart' | 'products' | 'categories';
  match: 'all' | 'any';
  products: string[];
  categories: string[];
  groups: string[];
  shipping: string[];
};
export type PromotionData = {
  version: number;
  translations: Record<string, { name: string; description: string }>;
  status: 'active' | 'disabled';
  mode: 'automatic' | 'coupon';
  coupon_id: string;
  starts_at: string;
  ends_at: string;
  priority: string;
  stop_other: boolean;
  match: 'all' | 'any';
  benefit: 'percentage' | 'fixed' | 'free_shipping' | 'coupon';
  scope: 'order' | 'products';
  amount: string;
  all_products: boolean;
  exclude_sale: boolean;
  all_shipping: boolean;
  conditions: PromotionCondition[];
  products: string[];
  categories: string[];
  shipping: string[];
  excluded_products: string[];
  excluded_categories: string[];
};
export type PromotionState = 'active' | 'scheduled' | 'expired' | 'disabled';
export type PromotionRow = {
  id: string;
  version: number;
  name: string;
  state: PromotionState;
  status: PromotionData['status'];
  mode: PromotionData['mode'];
  benefit: PromotionData['benefit'];
  amount: string | null;
  starts_at: string | null;
  ends_at: string | null;
  priority: string;
  coupon_code: string | null;
  used: number;
  total_limit: number | null;
};
type SavedCondition = Omit<
  PromotionCondition,
  'minimum' | 'maximum' | 'quantity' | TargetKind
> & {
  minimum: string | null;
  maximum: string | null;
  quantity: string | null;
} & Record<TargetKind, (string | null)[]>;
export type PromotionDetail = Omit<
  PromotionData,
  | 'amount'
  | 'coupon_id'
  | 'starts_at'
  | 'ends_at'
  | 'conditions'
  | 'products'
  | 'categories'
  | 'shipping'
  | 'excluded_products'
  | 'excluded_categories'
> & {
  id: string;
  name: string;
  state: PromotionState;
  amount: string | null;
  coupon_id: string | null;
  starts_at: string | null;
  ends_at: string | null;
  conditions: SavedCondition[];
  products: (string | null)[];
  categories: (string | null)[];
  shipping: (string | null)[];
  excluded_products: (string | null)[];
  excluded_categories: (string | null)[];
};
export type AppliedPromotion = {
  id: string;
  name: string;
  discount: string;
  shipping_discount: string;
};
