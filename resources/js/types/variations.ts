import type { Pagination } from './admin';

export type VariationGroupRow = {
  id: string;
  name: string;
  members: number;
  type: string | null;
  category: string[];
  features: string[];
};
export type VariationGroupList = Pagination<VariationGroupRow>;

export type DefiningFeature = {
  id: string;
  name: string;
  code: string;
  style: string;
  options: { id: string; label: string; status: string }[];
};
export type VariationMember = {
  id: string;
  name: string;
  sku: string | null;
  status: string;
  version: number;
  values: Record<string, { id: string; label: string } | null>;
  problems: string[];
};
export type VariationGroupDetail = {
  translations?: Record<string, { name: string }>;
  single_listing?: boolean;
  single_carousel?: boolean;
  single_search?: boolean;
  view_product?: boolean;
  color_swatches?: boolean;
  clickable_colors?: boolean;
  card_features?: string[];
  card_labels?: Record<string, Record<string, string>>;
  id: string;
  name: string;
  version: number;
  type: string | null;
  category: string[];
  features: DefiningFeature[];
  members: VariationMember[];
  problems: string[];
  readiness: { total: number; available: number; eligible: boolean };
  pending: { id: string; name: string }[];
};
export type VariationCandidate = {
  id: string;
  name: string;
  sku: string | null;
  status: string;
  version: number;
  values: string[];
  problems: string[];
};
export type TransferTarget = { id: string; name: string; version: number };
export type VariationImpact = {
  impact: {
    members: number;
    added: string[];
    removed: string[];
    combinations: { product: string; values: string }[];
  };
  token: string;
  features: string[];
} | null;
export type CreationFeature = {
  id: string;
  name: string;
  value: string | null;
  eligible: boolean;
  problem: string | null;
};
export type CreationData = {
  products: {
    id: string;
    name: string;
    sku: string | null;
    type: string;
    category: string[];
  }[];
  selected: {
    id: string;
    name: string;
    sku: string | null;
    type: string;
    version: number;
    category: string[];
    grouped: boolean;
    problems: string[];
    suggested_name: string;
    features: CreationFeature[];
  } | null;
};
