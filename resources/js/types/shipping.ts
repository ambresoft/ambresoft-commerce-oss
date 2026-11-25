export type SettingFieldView = {
  key: string;
  type:
    | 'text'
    | 'textarea'
    | 'secret'
    | 'select'
    | 'boolean'
    | 'date'
    | 'integer'
    | 'decimal'
    | 'order_status'
    | 'payment_method';
  label: string;
  hint: string;
  required: boolean;
  options: { value: string; label: string }[];
  max_length: number;
  min: string | null;
  max: string | null;
  value: string;
  set: boolean;
};

export type ShippingMethodRow = {
  id: string;
  version: number;
  name: string;
  calculation: 'manual' | 'carrier';
  provider: string | null;
  provider_name: string | null;
  provider_available: boolean;
  account_name: string | null;
  active: boolean;
  orders: number;
  updated_at: string | null;
  image_url: string | null;
};

export type ShippingRateInput = {
  rate_area_id: string;
  weight_from: string;
  weight_to: string;
  base: string;
  per_unit: string;
};

export type ShippingMethodEditor = {
  id: string;
  version: number;
  name: string;
  calculation: 'manual' | 'carrier';
  provider: string;
  account_id: string;
  active: boolean;
  min_weight: string;
  max_weight: string;
  free_shipping_applies: boolean;
  /** Maximum shipment value (goods after discounts with taxes, without shipping), in the primary currency. */
  value_limit_enabled: boolean;
  max_shipment_value: string;
  carrier_amount: 'gross' | 'net';
  carrier_surcharge: string;
  translations: Record<
    string,
    { name: string; description: string; delivery_time: string }
  >;
  rates: ShippingRateInput[];
  area_ids: string[];
  tax_ids: string[];
  payment_method_ids: string[];
  customer_group_ids: string[];
  orders: number;
  image: MethodImage;
};

/** Optional image of a payment or shipping method, served by the admin. */
export type MethodImage = {
  url: string;
  meta: { size: number; width: number; height: number };
} | null;

export type NamedOption = { id: string; name: string };

export type ShippingProviderOption = {
  id: string;
  name: string;
  quotes: boolean;
  shipments: boolean;
  accounts: { id: string; name: string; usable: boolean }[];
};

export type CarrierAccountView = {
  id: string;
  version: number;
  name: string;
  issues: string[];
  verified: boolean;
  verified_at: string | null;
  fields: SettingFieldView[];
  methods: number;
};

export type CarrierView = {
  id: string;
  name: string;
  owner: string;
  owner_title: string | null;
  version: number;
  settings: SettingFieldView[];
  settings_issues: string[];
  capabilities: Record<
    | 'quotes'
    | 'shipments'
    | 'labels'
    | 'tracking'
    | 'cancel'
    | 'verify'
    | 'manifests',
    boolean
  >;
  manifest_label: string | null;
  account_fields: SettingFieldView[];
  accounts: CarrierAccountView[];
};

export type ShipmentView = {
  id: string;
  provider: string;
  provider_name: string;
  account_name: string | null;
  state: 'creating' | 'created' | 'failed' | 'cancelled';
  tracking_number: string | null;
  extra_numbers: string[];
  pickup_date: string | null;
  weight_kg: string | null;
  packages: number;
  cod_amount: string | null;
  manifest_number: string | null;
  label_printed_at: string | null;
  tracking_state:
    | 'created'
    | 'in_transit'
    | 'delivered'
    | 'returning'
    | 'returned'
    | 'exception'
    | 'unknown'
    | null;
  tracking_code: string | null;
  tracking_summary: string | null;
  tracking_events: {
    at: string | null;
    action: string;
    location: string;
    notes: string;
  }[];
  tracking_checked_at: string | null;
  delivered_at: string | null;
  returned_at: string | null;
  error: string | null;
  created_by: string | null;
  created_at: string | null;
  stale: boolean;
  actions: Record<'label' | 'track' | 'cancel' | 'resolve', boolean>;
  label_formats: { value: string; label: string }[];
};

export type OrderShipping = {
  method: { id: string; name: string } | null;
  charge: string;
  details: {
    calculation?: 'manual' | 'carrier' | null;
    provider_name?: string;
    account_name?: string;
    weight_unit?: string;
    physical_weight?: string;
    chargeable_weight?: string;
    overridden?: boolean;
    calculated_amount?: string | null;
    free_shipping_only?: boolean;
    rate?: { area_name?: string };
  } | null;
  provider: { id: string; name: string } | null;
  can_create: boolean;
  create_blocker: string | null;
  shipments: ShipmentView[];
};

export type ShipmentForm = {
  fields: SettingFieldView[];
  request_key: string;
};
