export type TaxRateSummary = {
  area: string;
  type: 'percent' | 'fixed';
  label: string;
};
export type TaxRow = {
  id: string;
  version: number;
  name: string;
  registration_number: string | null;
  priority: number;
  status: 'active' | 'disabled';
  price_includes_tax: boolean;
  address_basis: 'shipping' | 'billing';
  rates: TaxRateSummary[];
  products: number;
};
export type TaxRateInput = {
  area_id: string;
  type: 'percent' | 'fixed';
  value: string;
};
export type TaxEditor = {
  id: string;
  version: number;
  names: Record<string, string>;
  registration_number: string;
  priority: number;
  status: 'active' | 'disabled';
  price_includes_tax: boolean;
  address_basis: 'shipping' | 'billing';
  rates: TaxRateInput[];
  products: number;
};
export type AreaOption = {
  id: string;
  name: string;
  default: boolean;
  active: boolean;
};
export type CountryOption = { code: string; name: string };
export type RateAreaRow = {
  id: string;
  version: number;
  name: string;
  status: 'active' | 'disabled';
  default: boolean;
  countries: string[];
  country_count: number;
  regions: number;
  taxes: number;
};
export type RateAreaEditor = {
  id: string;
  version: number;
  names: Record<string, string>;
  status: 'active' | 'disabled';
  is_default: boolean;
  countries: string[];
  regions: Record<string, string[]>;
  taxes: number;
};
export type ProductTaxOption = {
  id: string;
  name: string;
  active: boolean;
  price_includes_tax: boolean;
  priority: number;
};
