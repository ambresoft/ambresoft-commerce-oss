export type CountryRow = {
  code: string;
  name: string;
  shipping: boolean;
  regions: number;
};
export type RegionRow = {
  id: string;
  code: string;
  name: string;
  status: 'active' | 'disabled';
  version: number;
  names: Record<string, string>;
};
