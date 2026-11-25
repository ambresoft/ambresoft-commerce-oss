export type MenuText = {
  name: string;
  description?: string;
  image_alt?: string;
};
export type Menu = {
  id: string;
  status: string;
  placement: string;
  source: string;
  style?: string;
  version: number;
  item_count: number;
  translations: Record<string, MenuText>;
};
export type MenuItem = {
  name?: string;
  id: string;
  menu_id: string;
  parent_id: string | null;
  status: string;
  position: number;
  kind: string;
  url: string | null;
  category_id: string | null;
  generate_children: boolean;
  new_window: boolean;
  version: number;
  translations: Record<string, MenuText>;
  imageUrl: string | null;
  imageMeta: { size: number; width: number; height: number } | null;
};
export type MenuRow = {
  id: string;
  name: string;
  status: string;
  placement: string;
  source: string;
  style?: string;
  item_count: number;
  version: number;
};
export type ItemRow = {
  id: string;
  name: string;
  status: string;
  position: number;
  children_count: number;
  kind?: string;
  url?: string | null;
  category_id?: string | null;
  generate_children?: boolean;
};
