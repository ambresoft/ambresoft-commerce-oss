import type { Breadcrumb, NavigationItem } from './page';

export type AdminShell = {
  navigation: NavigationItem[];
  breadcrumbs: Breadcrumb[];
};
export type RoleOption = { id: string; name: string };
export type Pagination<T> = {
  data: T[];
  current_page: number;
  last_page: number;
  prev_page_url: string | null;
  next_page_url: string | null;
  total: number;
};
