export type Device = 'pc' | 'tablet' | 'phone';
export type VAlign = 'top' | 'center' | 'bottom';
export type HAlign = 'left' | 'center' | 'right';

export type Layout = {
  valign: VAlign;
  halign: HAlign;
  paddings: string;
  full_width: boolean;
  title_size: number;
  title_shadow: boolean;
  description_size: number;
  background_type: 'image' | 'video';
  background_size: 'cover' | 'contain';
  background_valign: VAlign;
  button_enabled: boolean;
  button_text_color: string | null;
  button_color: string | null;
  mask_type: 'none' | 'semi' | 'blur' | 'gradient' | 'color';
  mask_position: 'banner' | 'content';
  mask_opacity: number;
  mask_direction: 'auto' | 'top' | 'bottom' | 'left' | 'right';
  mask_color: string | null;
  css_classes: string | null;
};
/** Tablet/phone overrides: null inherits the PC value. */
export type LayoutDraft = { [K in keyof Layout]: Layout[K] | null };

export type StoredMedia = {
  id: string;
  url: string;
  meta?: { size: number; width: number; height: number } | null;
};
export type MediaSlot = 'main_image' | 'background_image' | 'background_video';
export type StoredContent = {
  title: string | null;
  description: string | null;
  button_text: string | null;
  youtube_video: string | null;
  main_image: StoredMedia | null;
  background_image: StoredMedia | null;
  background_video: StoredMedia | null;
};
export type ContentDraft = {
  title: string;
  description: string;
  button_text: string;
  youtube_video: string;
  main_image: File | null;
  background_image: File | null;
  background_video: File | null;
  remove_main_image: boolean;
  remove_background_image: boolean;
  remove_background_video: boolean;
};

export type Settings = {
  color_scheme: 'light' | 'dark';
  object_type: 'image' | 'youtube' | 'products';
  youtube_autoplay: boolean;
  youtube_hide_controls: boolean;
  products_template: 'grid' | 'small' | 'thumbnails';
  products_columns: number;
  products_rows: number;
  object_valign: VAlign;
  object_halign: HAlign;
  background_color: string | null;
  background_halign: HAlign;
  title_color: string | null;
  title_weight: 'thin' | 'normal' | 'bold' | 'heavy';
  title_tag: 'div' | 'h1' | 'h2' | 'h3' | 'h4' | 'h5' | 'h6';
  description_color: string | null;
  description_background: string | null;
  button_style: 'standard' | 'outline' | 'text';
  /** How the button reacts to the pointer, and the seconds that change takes. */
  button_hover:
    'none' | 'darken' | 'lighten' | 'lift' | 'grow' | 'glow' | 'invert';
  button_transition: number;
  url: string | null;
  new_window: boolean;
  tablet_custom: boolean;
  phone_custom: boolean;
};

export type ProductOption = {
  id: string;
  name: string;
  sku: string | null;
  image: string | null;
};
export type BannerGroup = { id: string; name: string; banners: number };
export type BannerState = 'live' | 'scheduled' | 'expired' | 'disabled';

export type BannerEditor = Settings & {
  id: string;
  version: number;
  group_id: string | null;
  position: number;
  status: 'active' | 'disabled';
  created_on: string;
  available_from: string | null;
  available_until: string | null;
  state: BannerState;
  names: Record<string, string>;
  layouts: Partial<Record<Device, LayoutDraft>>;
  contents: Partial<Record<Device, Record<string, StoredContent>>>;
  products: ProductOption[];
  customer_groups: string[];
};

export type BannerRow = {
  id: string;
  name: string;
  position: number;
  status: 'active' | 'disabled';
  state: BannerState;
  available_from: string | null;
  available_until: string | null;
  object_type: Settings['object_type'];
  version: number;
  group: { id: string; name: string } | null;
  thumbnail: string | null;
};

/** What the AI assistant may change: names, look and PC texts. */
export type BannerAiDraft = {
  names: Record<string, string>;
  settings: Partial<Settings>;
  layout: Partial<Layout>;
  contents: Record<
    string,
    { title: string; description: string; button_text: string }
  >;
};
