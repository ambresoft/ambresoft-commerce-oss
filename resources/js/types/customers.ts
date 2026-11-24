export type CustomerRow = {
  id: string;
  name: string;
  email: string;
  phone: string | null;
  status: 'active' | 'disabled';
  /** Set when the customer deleted their own account. */
  deleted_at?: string | null;
  tax_exempt: boolean;
  groups: string[];
  blacklisted: boolean;
  /** Linked to a sign-in identity (A99). */
  has_account?: boolean;
  created_at: string;
};
export type BlacklistMatch = {
  id: string;
  kind: 'email' | 'phone';
  value: string;
  reason: string;
  created_at: string;
  actor: string | null;
  own: boolean;
  /** `full` blocks the contact; `payment_methods` only disables the listed methods at checkout. */
  scope: 'full' | 'payment_methods';
  payment_methods: string[];
};
export type BlacklistEntry = {
  id: string;
  kind: 'email' | 'phone';
  value: string;
  reason: string;
  created_at: string;
  released: boolean;
  actor: string | null;
  customer: { id: string; name: string } | null;
  scope: 'full' | 'payment_methods';
  payment_methods: string[];
};
export type CustomerAddress = {
  id: string;
  label: string | null;
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
  /** Street number and delivery details. */
  street_number?: string | null;
  floor?: string | null;
  bell_name?: string | null;
  notes?: string | null;
};
export type CustomerDetail = {
  id: string;
  version: number;
  first_name: string;
  last_name: string;
  email: string;
  phone: string | null;
  status: 'active' | 'disabled';
  /** Set when the customer deleted their own account (the profile is disabled and kept). */
  deleted_at?: string | null;
  tax_exempt: boolean;
  notes: string | null;
  group_ids: string[];
  groups: string[];
  addresses: CustomerAddress[];
  blacklist: BlacklistMatch[];
  /** Sign-in account: linked, invited/registered awaiting verification, verified but not yet attached, or none. */
  account: {
    state: 'linked' | 'invited' | 'registered' | 'none';
    since: string | null;
    email_verified: boolean;
    active: boolean;
  };
  default_billing_address_id: string | null;
  default_shipping_address_id: string | null;
  created_at: string;
  updated_at: string;
};
export type CustomerGroupOption = {
  id: string;
  name: string;
  active: boolean;
  default: boolean;
};
export type CustomerGroupRow = {
  id: string;
  version: number;
  name: string;
  description: string;
  status: 'active' | 'disabled';
  default: boolean;
  members: number;
};
export type CustomerGroupEditor = {
  id: string;
  version: number;
  status: 'active' | 'disabled';
  is_default: boolean;
  names: Record<string, string>;
  descriptions: Record<string, string>;
  members: number;
};
export type AddressInput = {
  label: string;
  first_name: string;
  last_name: string;
  company: string;
  line1: string;
  line2: string;
  city: string;
  postal_code: string;
  country_code: string;
  region_code: string;
  phone: string;
  /** Street number and optional delivery details; orders retain a copy with the street number in line1. */
  street_number?: string;
  floor?: string;
  bell_name?: string;
  notes?: string;
};
/** Active regions of each shipping country (Geography → Regions), keyed by country code. */
export type RegionOptions = Record<string, { code: string; name: string }[]>;
