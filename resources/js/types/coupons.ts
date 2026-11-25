export type CouponRow = {
  id: string;
  version: number;
  name: string;
  code: string;
  status: string;
  discount: string;
  free_shipping: boolean;
  starts_on: string | null;
  ends_on: string | null;
  used: number;
  total_limit: number | null;
};
export type CouponData = {
  version: number;
  name: string;
  code: string;
  type: string;
  amount: string;
  minimum: string;
  login_required: boolean;
  free_shipping: boolean;
  all_products: boolean;
  starts_on: string;
  ends_on: string;
  total_limit: string;
  customer_limit: string;
  status: string;
  product_ids: string[];
  category_ids: string[];
};
export type CouponDetail = CouponData & {
  id: string;
  deleted_at: string | null;
};
export type CouponUse = {
  id: string;
  order_id: string;
  order_number: string;
  customer: string;
  amount: string;
  created_at: string;
  released: boolean;
};
export type CouponOption = { id: string; name: string };
