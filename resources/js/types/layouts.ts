export type MobileMenuLinks = {
  title: string;
  items: { label: string; url: string; new_window: boolean }[];
};
export type BlockConfig = {
  id: string;
  type: string;
  name: string;
  template: string;
  css_class: string;
  settings: Record<string, string | number | boolean | string[]>;
  translations: Record<
    string,
    {
      title: string;
      html: string;
      html_right?: string;
      link_text?: string;
      mobile_links?: MobileMenuLinks | null;
    }
  >;
  selections: Record<string, string[]>;
  /** A picture beside the block's content (blocks with an Image tab). */
  image_media_id?: string | null;
  /** Its phone version; empty uses the desktop picture. */
  image_mobile_media_id?: string | null;
};
export type Placement = {
  id: string;
  grid_id: string;
  position: number;
  enabled: boolean;
  mobile: boolean;
  tablet: boolean;
  desktop: boolean;
  block: BlockConfig;
};
export type Grid = {
  id: string;
  parent_id: string | null;
  region: string;
  width: number;
  row: number;
  position: number;
};
export type LayoutOverride = {
  placement_id: string;
  enabled: boolean;
  block: BlockConfig | null;
};
export type Layout = {
  id: string;
  page_type: string;
  version: number;
  grids: Grid[];
  placements: Placement[];
  overrides: LayoutOverride[];
};
export type BlockField = {
  key: string;
  kind:
    | 'selection'
    | 'select'
    | 'order'
    | 'number'
    | 'richtext'
    | 'boolean'
    | 'text'
    | 'color'
    | 'textarea'
    | 'url'
    | 'translated_text'
    | 'image';
  /** Shown on its own tab instead of Content. */
  tab?: 'image' | 'mobile_links' | 'product_options' | 'identity_options';
  label: string;
  /** Label of the button that clears a color field; defaults to the background wording. */
  reset_label?: string;
  /** Translation key of a hint shown under the field. */
  help?: string;
  /** Show the field only while another setting has this value. */
  when?: Record<string, string | string[]>;
  /** Offered only for the carousel templates, not the single ones. */
  carousel_only?: boolean;
  /** Show only when this entity selection is populated. */
  when_selection?: string;
  /** Show only in the editor of these page kinds (for example the main content block's per-page settings). */
  pages?: string[];
  source?: string;
  single?: boolean;
  options?: string[];
  option_labels?: Record<string, string>;
  min?: number;
  max?: number;
};
export type BlockDefinition = {
  creatable?: boolean;
  label: string;
  fields: BlockField[];
  defaults: BlockConfig['settings'];
  templates: string[];
  template_labels?: Record<string, string>;
  pages: string[];
  policy: {
    move: boolean;
    disable: boolean;
    delete: boolean;
    configure: boolean;
  };
  canManage: boolean;
};
export type LayoutOptions = Record<
  string,
  { id: string; name: string; brand_id?: string }[]
>;
export type LayoutTab = {
  key: string;
  label: string;
  /** Built-in tabs carry a translation key; merchant-added layouts carry their own name. */
  translate: boolean;
  page_id: string | null;
  custom: boolean;
};
export type PageText = {
  name: string;
  page_title: string;
  meta_description: string;
  meta_keywords: string;
};
export type PageSettings = {
  version: number;
  custom: boolean;
  page_id: string | null;
  position: number;
  indexable: boolean;
  custom_html: string;
  translations: Record<string, PageText>;
};
export type PageOption = {
  id: string;
  name: string;
  depth: number;
  layout: string | null;
};
