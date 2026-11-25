export type CurrencyRow = {
  id: string;
  code: string;
  name: string;
  symbol: string;
  rate: string;
  primary: boolean;
  active: boolean;
  sample: string;
};
export type CurrencySettings = {
  id: string;
  version: number;
  code: string;
  name: string;
  symbol: string;
  symbol_position: 'before' | 'after';
  thousands_separator: string;
  decimal_separator: string;
  decimals: number;
  rate: string;
  status: 'active' | 'disabled';
  primary: boolean;
};
export type PrimaryChangePreview = {
  products: number;
  from: string;
  to: string | null;
  rates: { code: string; from: string; to: string }[];
  error: string | null;
};
export type PrimaryCurrency = { code: string; name: string };
export type ProductCurrency = PrimaryCurrency & {
  symbol: string;
  decimals: number;
};
