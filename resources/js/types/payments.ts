import type { MethodImage } from './shipping';

export type PaymentMethodRow = {
  code: string;
  version: number;
  name: string;
  description: string;
  provider: string;
  owner: string;
  owner_title: string | null;
  kind: 'offline' | 'redirect';
  active: boolean;
  issues: string[];
  available: boolean;
  image_url: string | null;
};

export type PaymentSettingField = {
  key: string;
  type: 'text' | 'secret' | 'select' | 'boolean';
  label: string;
  hint: string;
  required: boolean;
  options: { value: string; label: string }[];
  max_length: number;
  value: string;
  set: boolean;
};

export type PaymentMethodEditor = {
  code: string;
  version: number;
  name: string;
  provider: string;
  owner: string;
  owner_title: string | null;
  kind: 'offline' | 'redirect';
  active: boolean;
  paid_status_id: string;
  translations: Record<string, { name: string; description: string }>;
  fields: PaymentSettingField[];
  issues: string[];
  verified_at: string | null;
  image: MethodImage;
  exclude_physical: boolean;
  exclude_digital: boolean;
  /** Optional fee added to orders paid with the method (empty: none), in the primary currency. */
  fee: string;
  /** Checkout does not offer the method above this order subtotal (empty: no limit), in the primary currency. */
  max_subtotal: string;
  fee_currency: string;
  /** Categories the method cannot pay for, each with its subcategories. */
  excluded_category_ids: string[];
};

export type OrderPaymentStatus =
  'unpaid' | 'pending' | 'partially_paid' | 'paid';

export type OrderPaymentRecord = {
  id: string;
  kind: 'manual' | 'online' | 'gift_card';
  state:
    'created' | 'processing' | 'pending' | 'captured' | 'failed' | 'cancelled';
  amount: string;
  method: string | null;
  provider_reference: string | null;
  capture_reference: string | null;
  detail: string | null;
  note: string | null;
  recorded_by: string | null;
  created_at: string;
  completed_at: string | null;
  can_refresh: boolean;
};

export type OrderPayment = {
  method: {
    name: string;
    kind: 'offline' | 'redirect';
    online: boolean;
  } | null;
  status: OrderPaymentStatus;
  paid: string;
  outstanding: string;
  outstanding_raw: string;
  decimals: number;
  records: OrderPaymentRecord[];
  link: string | null;
  can_record: boolean;
  can_gift_card: boolean;
};

export type AddonRow = {
  name: string;
  title: string;
  description: string;
  author: string;
  /** The add-on's declared icon; none means the default puzzle icon is shown. */
  icon_url?: string | null;
  version: string | null;
  installed_version: string | null;
  source: 'bundled' | 'uploaded';
  status: 'not_installed' | 'disabled' | 'enabled';
  compatible: boolean;
  cms: string | null;
  requires: string[];
  contributes: Record<string, string[]>;
  error: string | null;
  upgrade_available: boolean;
  settings: { name: string; href: string }[];
  actions: Record<
    'install' | 'enable' | 'disable' | 'upgrade' | 'uninstall',
    boolean
  >;
};
