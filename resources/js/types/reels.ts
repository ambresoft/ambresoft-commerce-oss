import type {
  PublicImage,
  PublicItem,
  CurrencyFormat,
} from '../storefront/types';

export type ReelRow = {
  id: string;
  name: string;
  status: 'active' | 'disabled';
  version: number;
  views: number;
  created_at: string;
  cover: string;
};
export type ReelEditor = Pick<
  ReelRow,
  'id' | 'name' | 'status' | 'version' | 'cover'
> & {
  video: string;
  translations: Record<string, { name: string }>;
  products: string[];
};
export type ReelDetail = {
  id: string;
  name: string;
  image: PublicImage;
  video: string;
  views: number;
  view_token: string;
  products: PublicItem[];
  currency_format: CurrencyFormat;
};
