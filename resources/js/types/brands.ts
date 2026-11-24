import type { StoredImage } from '../components/admin/ImageUploader';
import type { Attachment } from './attachments';

export type BrandText = {
  name: string;
  description: string;
  image_alt: string;
  banner_alt: string;
};
export type BrandRow = {
  id: string;
  name: string;
  code: string;
  status: 'active' | 'disabled';
  position: number;
  products_count: number;
  version: number;
  updated_at: string;
  image: string | null;
};
export type BrandEditor = Omit<BrandRow, 'image' | 'updated_at'> & {
  files?: Attachment[];
  image: StoredImage | null;
  banner_image: StoredImage | null;
  banner_link: string;
  translations: Record<string, BrandText>;
};
